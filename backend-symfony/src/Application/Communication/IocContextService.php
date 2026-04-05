<?php

declare(strict_types=1);

namespace App\Application\Communication;

use Doctrine\DBAL\Connection;
use Psr\Log\LoggerInterface;

/**
 * Computes and persists structural context for IOCs at ingestion time.
 *
 * For each IOC extracted from a message, stores:
 * - Conversation metadata (scam type, persona, engagement metrics)
 * - Revelation context (turn index, stimulus message, co-revealed IOCs)
 * - Campaign link (if any)
 *
 * LLM semantic enrichment is handled by ContextualEnricher (043b).
 */
final class IocContextService
{
    /** @var list<string> */
    public const HEADER_IOC_TYPES = [
        'message_id', 'subject', 'spf_result', 'dkim_result',
        'dmarc_result', 'x_mailer', 'return_path',
    ];

    public function __construct(
        private readonly Connection $connection,
        private readonly LoggerInterface $logger,
    ) {
    }

    public static function isHeaderIocType(string $type): bool
    {
        return \in_array($type, self::HEADER_IOC_TYPES, true);
    }

    public static function computeTurnRatio(int $turnIndex, int $totalTurns): float
    {
        if ($totalTurns <= 0) {
            return 0.0;
        }

        return round($turnIndex / $totalTurns, 3);
    }

    public static function secondsToHours(int $seconds): float
    {
        return round($seconds / 3600.0, 2);
    }

    /**
     * Compute and persist structural context for all IOCs from a given message.
     *
     * @param string                                                              $msgId      The message UUID containing the IOCs
     * @param list<array{obs_id: string, indicator_id: string, ioc_type: string}> $obsIocData
     */
    public function computeAndPersistForMessage(string $msgId, array $obsIocData): void
    {
        if (empty($obsIocData)) {
            return;
        }

        // Step 1: Load conversation context
        $convContext = $this->loadConversationContext($msgId);

        if ($convContext === null) {
            $this->logger->warning('Could not load conversation context for message', ['msg_id' => $msgId]);

            return;
        }

        // Step 2: Compute turn index
        $convId = \is_string($convContext['conv_id'] ?? null) ? $convContext['conv_id'] : '';
        $tsMsg = \is_string($convContext['ts_msg'] ?? null) ? $convContext['ts_msg'] : '';

        $turnIndex = $this->computeTurnIndex($convId, $tsMsg);
        $totalTurns = \is_numeric($convContext['turns_count'] ?? null) ? (int) $convContext['turns_count'] : 0;
        $turnRatio = self::computeTurnRatio($turnIndex, $totalTurns);

        // Step 3: Find stimulus message
        $stimulusMsgId = $this->findStimulusMessage($convId, $tsMsg);

        // Step 4: Find all IOC types from same message (for co-revealed)
        $allTypesInMessage = $this->findAllIocTypesInMessage($msgId);

        // Step 5: Find campaign
        $campaignId = $this->findCampaignForMessage($msgId);

        // Step 6: Persist for each IOC
        $now = (new \DateTimeImmutable())->format('Y-m-d H:i:s');

        foreach ($obsIocData as $ioc) {
            $obsId = $ioc['obs_id'];
            $indicatorId = $ioc['indicator_id'];
            $iocType = $ioc['ioc_type'];

            // Skip header IOCs
            if (self::isHeaderIocType($iocType)) {
                $this->insertContext($obsId, $indicatorId, [
                    'enrichment_status' => 'skipped',
                    'computed_at' => $now,
                ]);

                continue;
            }

            // Co-revealed: all types except this one
            $coRevealedTypes = array_values(array_filter(
                $allTypesInMessage,
                fn (string $t) => $t !== $iocType
            ));

            // Remove duplicates and header types from co-revealed
            $coRevealedTypes = array_values(array_unique(array_filter(
                $coRevealedTypes,
                fn (string $t) => !self::isHeaderIocType($t)
            )));

            $engagementSec = \is_numeric($convContext['engagement_duration_sec'] ?? null) ? (int) $convContext['engagement_duration_sec'] : 0;
            $rewardValue = \is_numeric($convContext['reward_value'] ?? null) ? (float) $convContext['reward_value'] : null;

            // Extract extraction_method from observed_ioc context
            $extractionMethod = $this->getExtractionMethod($obsId);

            $this->insertContext($obsId, $indicatorId, [
                'scam_type_code' => $convContext['scam_type'] ?? null,
                'scam_type_attck' => $convContext['attck_technique'] ?? null,
                'scam_type_misp' => $convContext['misp_taxonomy'] ?? null,
                'persona_code' => $convContext['persona_code'] ?? null,
                'persona_label' => $convContext['persona_label'] ?? null,
                'extraction_method' => $extractionMethod,
                'revelation_turn' => $turnIndex,
                'total_turns' => $totalTurns,
                'revelation_turn_ratio' => $turnRatio,
                'engagement_hours' => self::secondsToHours($engagementSec),
                'reward_value' => $rewardValue,
                'stimulus_msg_id' => $stimulusMsgId,
                'co_revealed_types' => '{' . implode(',', $coRevealedTypes) . '}',
                'co_revealed_count' => \count($coRevealedTypes),
                'campaign_id' => $campaignId,
                'enrichment_status' => 'structural',
                'computed_at' => $now,
            ]);
        }

        $this->logger->info('IOC context computed', [
            'msg_id' => $msgId,
            'ioc_count' => \count($obsIocData),
        ]);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function loadConversationContext(string $msgId): ?array
    {
        $row = $this->connection->fetchAssociative(
            'SELECT c.conv_id, c.turns_count, c.engagement_duration_sec, c.reward_value,'
            . ' st.code AS scam_type, st.attck_technique, st.misp_taxonomy,'
            . ' p.persona_code, p.persona_label,'
            . ' m.ts_msg'
            . ' FROM message m'
            . ' JOIN conversation c ON m.conv_id = c.conv_id'
            . ' JOIN lkp_scam_type st ON c.scam_type_id = st.scam_type_id'
            . ' LEFT JOIN persona p ON c.persona_id = p.persona_id'
            . ' WHERE m.msg_id = :msgId',
            ['msgId' => $msgId]
        );

        return $row ?: null;
    }

    private function computeTurnIndex(string $convId, string $msgTimestamp): int
    {
        $count = $this->connection->fetchOne(
            'SELECT COUNT(*) + 1'
            . ' FROM message m'
            . ' WHERE m.conv_id = :convId'
            . ' AND m.direction = (SELECT dir_id FROM lkp_direction WHERE code = \'in\')'
            . ' AND m.ts_msg < :ts'
            . ' AND m.deleted_at IS NULL',
            ['convId' => $convId, 'ts' => $msgTimestamp]
        );

        return \is_numeric($count) ? (int) $count : 1;
    }

    private function findStimulusMessage(string $convId, string $msgTimestamp): ?string
    {
        $result = $this->connection->fetchOne(
            'SELECT m.msg_id'
            . ' FROM message m'
            . ' WHERE m.conv_id = :convId'
            . ' AND m.direction = (SELECT dir_id FROM lkp_direction WHERE code = \'out\')'
            . ' AND m.ts_msg < :ts'
            . ' AND m.deleted_at IS NULL'
            . ' ORDER BY m.ts_msg DESC'
            . ' LIMIT 1',
            ['convId' => $convId, 'ts' => $msgTimestamp]
        );

        return \is_string($result) ? $result : null;
    }

    /**
     * @return list<string>
     */
    private function findAllIocTypesInMessage(string $msgId): array
    {
        $rows = $this->connection->fetchAllAssociative(
            'SELECT DISTINCT i.type'
            . ' FROM observed_ioc oi'
            . ' JOIN indicator i ON oi.indicator_id = i.indicator_id'
            . ' WHERE oi.msg_id = :msgId',
            ['msgId' => $msgId]
        );

        $types = [];

        foreach ($rows as $row) {
            if (\is_string($row['type'] ?? null)) {
                $types[] = $row['type'];
            }
        }

        return $types;
    }

    private function findCampaignForMessage(string $msgId): ?string
    {
        $result = $this->connection->fetchOne(
            'SELECT mc.campaign_id FROM message_campaign mc'
            . ' WHERE mc.msg_id::text = :msgId'
            . ' LIMIT 1',
            ['msgId' => $msgId]
        );

        return \is_string($result) ? $result : null;
    }

    private function getExtractionMethod(string $obsId): ?string
    {
        $contextJson = $this->connection->fetchOne(
            'SELECT context_observation FROM observed_ioc WHERE obs_id = :obsId',
            ['obsId' => $obsId]
        );

        if (!\is_string($contextJson)) {
            return null;
        }

        $context = json_decode($contextJson, true);

        if (!\is_array($context)) {
            return null;
        }

        if (\is_string($context['extraction_method'] ?? null)) {
            return $context['extraction_method'];
        }

        // Normalize source field
        if (\is_string($context['source'] ?? null)) {
            $source = $context['source'];

            if (str_starts_with($source, 'headers')) {
                return 'header';
            }

            // n8n pipeline uses LLM extraction, normalize the generic label
            if ($source === 'extraction') {
                return 'llm';
            }

            return $source;
        }

        return null;
    }

    /**
     * @param array<string, mixed> $data
     */
    private function insertContext(string $obsId, string $indicatorId, array $data): void
    {
        $data['obs_id'] = $obsId;
        $data['indicator_id'] = $indicatorId;

        $columns = array_keys($data);
        $placeholders = array_map(fn (string $col) => ':' . $col, $columns);

        $sql = 'INSERT INTO ioc_context (' . implode(', ', $columns) . ')'
            . ' VALUES (' . implode(', ', $placeholders) . ')'
            . ' ON CONFLICT (obs_id) DO NOTHING';

        $this->connection->executeStatement($sql, $data);
    }
}
