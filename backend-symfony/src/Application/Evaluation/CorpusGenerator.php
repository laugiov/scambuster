<?php

declare(strict_types=1);

namespace App\Application\Evaluation;

use App\Application\Communication\ReplyHandler;
use App\Application\LLM\LanguageDetector;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Generates an evaluation corpus by calling the real LLM pipeline
 * on existing conversations and capturing full metadata.
 */
class CorpusGenerator
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ReplyHandler $replyHandler,
        private readonly LanguageDetector $languageDetector,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Generate corpus entries from existing conversations.
     *
     * @param array<string, mixed> $filters {scam_type?: string, persona?: string, language?: string}
     * @param int                  $count   Max entries to generate
     * @param float                $sleep   Seconds between API calls
     * @param bool                 $dryRun  If true, compute cost estimate without LLM calls
     *
     * @return array{entries: array<int, array<string, mixed>>, summary: array<string, mixed>}
     */
    public function generate(
        array $filters = [],
        int $count = 500,
        float $sleep = 1.0,
        bool $dryRun = false,
        ?\Closure $onProgress = null,
    ): array {
        $conversations = $this->loadConversations($filters, $count);

        if (empty($conversations)) {
            return ['entries' => [], 'summary' => $this->buildSummary([], $dryRun)];
        }

        $entries = [];
        $processed = 0;

        foreach ($conversations as $conv) {
            if ($processed >= $count) {
                break;
            }

            $convId = $conv['conv_id'];
            $lastMsgId = $conv['last_msg_id'] ?? null;

            if ($lastMsgId === null) {
                continue;
            }

            if ($dryRun) {
                $entries[] = $this->buildDryRunEntry($conv);
                ++$processed;

                if ($onProgress !== null) {
                    $onProgress($processed, $count);
                }

                continue;
            }

            try {
                $context = $this->replyHandler->getConversationContext($convId);

                if ($context === null) {
                    $this->logger->warning('No context for conversation {conv_id}', ['conv_id' => $convId]);

                    continue;
                }

                $result = $this->replyHandler->generateReply($convId, $lastMsgId, true, 'evaluation');

                if ($result === null) {
                    continue;
                }

                $entries[] = $this->buildEntry($conv, $context, $result);
                ++$processed;

                if ($onProgress !== null) {
                    $onProgress($processed, $count);
                }

                if ($sleep > 0 && $processed < $count) {
                    usleep((int) ($sleep * 1_000_000));
                }
            } catch (\Throwable $e) {
                $this->logger->error('Corpus generation failed for {conv_id}: {error}', [
                    'conv_id' => $convId,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return ['entries' => $entries, 'summary' => $this->buildSummary($entries, $dryRun)];
    }

    /**
     * @param array<string, mixed> $filters
     *
     * @return array<int, array<string, mixed>>
     */
    private function loadConversations(array $filters, int $limit): array
    {
        $conn = $this->em->getConnection();
        $sql = <<<'SQL'
            SELECT
                c.conv_id,
                c.status,
                st.code AS scam_type_code,
                p.persona_code,
                (SELECT m.msg_id FROM message m WHERE m.conv_id = c.conv_id AND m.direction = 3 ORDER BY m.ts_msg DESC LIMIT 1) AS last_msg_id,
                (SELECT m.body_text FROM message m WHERE m.conv_id = c.conv_id AND m.direction = 3 ORDER BY m.ts_msg DESC LIMIT 1) AS last_inbound_text,
                (SELECT COUNT(*) FROM message m WHERE m.conv_id = c.conv_id) AS message_count
            FROM conversation c
            LEFT JOIN lkp_scam_type st ON c.scam_type_id = st.scam_type_id
            LEFT JOIN persona p ON c.persona_id = p.persona_id
            WHERE c.status IN ('open', 'closed')
            SQL;

        $params = [];

        if (!empty($filters['scam_type'])) {
            $sql .= ' AND st.code = :scam_type';
            $params['scam_type'] = $filters['scam_type'];
        }

        if (!empty($filters['persona'])) {
            $sql .= ' AND p.persona_code = :persona';
            $params['persona'] = $filters['persona'];
        }

        $sql .= ' ORDER BY c.created_at DESC LIMIT :max_rows';
        $params['max_rows'] = $limit * 2;

        /** @var array<int, array<string, mixed>> $rows */
        $rows = $conn->fetchAllAssociative($sql, $params);

        if (!empty($filters['language'])) {
            $targetLang = $filters['language'];
            $rows = array_filter($rows, function (array $row) use ($targetLang): bool {
                $rawText = $row['last_inbound_text'] ?? '';
                $text = \is_string($rawText) ? $rawText : '';

                return $this->languageDetector->detect($text) === $targetLang;
            });
            $rows = array_values($rows);
        }

        return $rows;
    }

    /**
     * @param array<string, mixed> $conv
     * @param array<string, mixed> $context
     * @param array<string, mixed> $result
     *
     * @return array<string, mixed>
     */
    private function buildEntry(array $conv, array $context, array $result): array
    {
        /** @var array<string, mixed> $draft */
        $draft = $result['draft'] ?? [];
        /** @var array<string, mixed> $meta */
        $meta = $result['meta'] ?? [];
        /** @var string $text */
        $text = $draft['text'] ?? '';
        $detectedLang = $context['detected_language'] ?? 'en';
        $rawMsgCount = $conv['message_count'] ?? 0;

        return [
            'conv_id' => $conv['conv_id'],
            'scam_type' => $conv['scam_type_code'] ?? 'unknown',
            'persona_code' => $conv['persona_code'] ?? 'unknown',
            'message_count' => \is_numeric($rawMsgCount) ? (int) $rawMsgCount : 0,
            'detected_language' => $detectedLang,
            'reply_language' => $this->languageDetector->detect($text),
            'text' => $text,
            'word_count' => str_word_count($text),
            'attempts' => $meta['attempts'] ?? 1,
            'fallback_used' => $meta['fallback_used'] ?? false,
            'approved' => true,
            'naturalness' => $meta['naturalness'] ?? 3,
            'persona_fit' => $meta['persona_fit'] ?? 3,
            'ti_value' => $meta['ti_value'] ?? 3,
            'security_pass' => $meta['security_pass'] ?? true,
            'policy_flags' => $meta['policy_flags'] ?? [],
            'cost_estimate' => $meta['cost_estimate'] ?? 0.003,
            'generated_at' => date(\DATE_ATOM),
        ];
    }

    /**
     * @param array<string, mixed> $conv
     *
     * @return array<string, mixed>
     */
    private function buildDryRunEntry(array $conv): array
    {
        $rawMsgCount = $conv['message_count'] ?? 0;

        return [
            'conv_id' => $conv['conv_id'],
            'scam_type' => $conv['scam_type_code'] ?? 'unknown',
            'persona_code' => $conv['persona_code'] ?? 'unknown',
            'message_count' => \is_numeric($rawMsgCount) ? (int) $rawMsgCount : 0,
            'detected_language' => 'estimated',
            'reply_language' => 'estimated',
            'text' => '[DRY RUN — no LLM call]',
            'word_count' => 0,
            'attempts' => 0,
            'fallback_used' => false,
            'approved' => false,
            'naturalness' => 0,
            'persona_fit' => 0,
            'ti_value' => 0,
            'security_pass' => true,
            'policy_flags' => [],
            'cost_estimate' => 0.003,
            'generated_at' => date(\DATE_ATOM),
        ];
    }

    /**
     * @param array<int, array<string, mixed>> $entries
     *
     * @return array<string, mixed>
     */
    private function buildSummary(array $entries, bool $dryRun): array
    {
        $personas = [];
        $scamTypes = [];
        $languages = [];
        $totalCost = 0.0;
        $approved = 0;
        $fallback = 0;

        foreach ($entries as $e) {
            $persona = $e['persona_code'] ?? 'unknown';
            $scamType = $e['scam_type'] ?? 'unknown';
            $lang = $e['detected_language'] ?? 'unknown';

            $personas[$persona] = ($personas[$persona] ?? 0) + 1;
            $scamTypes[$scamType] = ($scamTypes[$scamType] ?? 0) + 1;
            $languages[$lang] = ($languages[$lang] ?? 0) + 1;
            $rawCost = $e['cost_estimate'] ?? 0;
            $totalCost += \is_numeric($rawCost) ? (float) $rawCost : 0.0;

            if ($e['approved'] ?? false) {
                ++$approved;
            }

            if ($e['fallback_used'] ?? false) {
                ++$fallback;
            }
        }

        arsort($personas);
        arsort($scamTypes);
        arsort($languages);

        return [
            'total' => count($entries),
            'approved' => $approved,
            'fallback' => $fallback,
            'total_cost' => round($totalCost, 4),
            'dry_run' => $dryRun,
            'personas' => $personas,
            'scam_types' => $scamTypes,
            'languages' => $languages,
            'generated_at' => date(\DATE_ATOM),
        ];
    }
}
