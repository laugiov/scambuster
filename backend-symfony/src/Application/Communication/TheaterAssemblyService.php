<?php

declare(strict_types=1);

namespace App\Application\Communication;

use App\Domain\Communication\Conversation;
use App\Domain\Communication\IocCategory;
use App\Domain\Communication\Message;
use App\Domain\Communication\ObservedIoc;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Spec 097 — Composes the Live Bait Theater response in ONE round-trip.
 *
 * Slice 1 deliverable: meta + messages + iocs (deduplicated by value_norm,
 * first-message wins). Slice 2 will add revelation_context (LEFT JOIN on
 * `ioc_context`) and `human_factor` aggregates.
 *
 * Hard rules:
 * - The IOC set returned MUST equal the set returned by `IocHandler::
 *   getConversationIocs` after dedup by `value_norm`. The Theater MUST NOT
 *   re-implement IOC attribution.
 * - Soft-deleted messages are excluded. IOCs whose parent message is
 *   soft-deleted (or otherwise absent from the messages list) are
 *   excluded as well (orphan IOC rule, spec §Behavior rule #8).
 * - Conversations with > 100 messages are TRUNCATED to the first 100
 *   (long_conversation_truncated = true). IOCs whose parent message is
 *   outside the truncated window are also excluded.
 */
final readonly class TheaterAssemblyService
{
    public const int LONG_CONVERSATION_THRESHOLD = 100;

    public function __construct(
        private EntityManagerInterface $em,
        private IocHandler $iocHandler,
        private Connection $connection,
        private TheaterHumanFactorCalculator $humanFactor,
    ) {
    }

    /**
     * Assemble the Theater payload for a conversation.
     *
     * @return array{
     *   meta: array<string, mixed>,
     *   messages: list<array<string, mixed>>,
     *   iocs_by_msg: list<array<string, mixed>>,
     *   human_factor: array<string, mixed>
     * }
     */
    public function assemble(Conversation $conv): array
    {
        $allMessages = $this->loadOrderedMessages($conv->getConvId());

        $truncated = false;

        if (\count($allMessages) > self::LONG_CONVERSATION_THRESHOLD) {
            $allMessages = \array_slice($allMessages, 0, self::LONG_CONVERSATION_THRESHOLD);
            $truncated = true;
        }

        $messageIdSet = $this->buildMessageIdSet($allMessages);
        $messages = $this->serializeMessages($allMessages);

        $iocs = $this->iocHandler->getConversationIocs($conv->getConvId());
        $iocsByMsg = $this->serializeAndDedupIocs(array_values($iocs), $messageIdSet);

        // Spec 097 / Slice 2 — enrich each IOC with its revelation_context
        // (LEFT JOIN on ioc_context). Orphan stimulus_msg_id validated
        // against the conv's message set per spec §Behavior rule #9.
        $iocsByMsg = $this->enrichWithRevelationContext($iocsByMsg, $messageIdSet);

        $enrichmentCoveragePct = $this->computeEnrichmentCoverage($iocsByMsg);

        $persona = $conv->getPersona();

        $meta = [
            'conv_id' => $conv->getConvId(),
            'scam_type' => $conv->getScamType()->getCode(),
            'scammer_address' => $this->extractScammerAddress($allMessages),
            'persona_address' => $this->extractPersonaAddress($allMessages),
            'persona_code' => $persona?->getPersonaCode(),
            // Spec 099 S2 — human-readable persona label for display in
            // the Theater header. Frontend prefers persona_label, falls
            // back to persona_code when null (legacy conversations).
            'persona_label' => $persona?->getPersonaLabel(),
            'status' => $conv->getStatus()->value,
            'ts_first' => $conv->getTsFirst()->format(DATE_ATOM),
            'ts_last' => $conv->getTsLast()->format(DATE_ATOM),
            'messages_count' => \count($messages),
            'iocs_count' => \count($iocsByMsg),
            // Spec 099 S6 — count of IOCs in the Actionable tier (excludes
            // header artifacts: subject, message_id, auth results, whois_*).
            // Mirrors the frontend `tierForIocType` mapping so the headline
            // number in the Theater can be cross-checked from the backend
            // payload alone.
            'iocs_count_actionable' => $this->countActionableIocs($iocsByMsg),
            'long_conversation_truncated' => $truncated,
            'enrichment_coverage_pct' => $enrichmentCoveragePct,
        ];

        $humanFactor = $this->humanFactor->compute(
            $messages,
            $iocsByMsg,
            $persona?->getPersonaCode(),
            $enrichmentCoveragePct,
        );

        return [
            'meta' => $meta,
            'messages' => $messages,
            'iocs_by_msg' => $iocsByMsg,
            'human_factor' => $humanFactor,
        ];
    }

    /**
     * Spec 097 / Slice 2 — Single SQL fetch on ioc_context for all the IOCs
     * in this conversation, then merge in-PHP into each IOC dict. Avoids N+1.
     *
     * Also validates `stimulus_msg_id` (rule #9): when the LLM-attributed
     * stimulus message is NOT in the current conv's messages, we null it
     * out so the UI never builds a broken overlay link.
     *
     * @param list<array<string, mixed>> $iocsByMsg
     * @param array<string, true>        $messageIdSet
     *
     * @return list<array<string, mixed>>
     */
    private function enrichWithRevelationContext(array $iocsByMsg, array $messageIdSet): array
    {
        if ([] === $iocsByMsg) {
            return $iocsByMsg;
        }

        $obsIds = [];

        foreach ($iocsByMsg as $r) {
            $obsId = $r['obs_id'] ?? null;

            if (\is_string($obsId) && '' !== $obsId) {
                $obsIds[] = $obsId;
            }
        }

        if ([] === $obsIds) {
            return $iocsByMsg;
        }

        $sql = 'SELECT obs_id::text AS obs_id,'
            . ' enrichment_status, enrichment_confidence, context_excerpt,'
            . ' semantic_role, stimulus_type, urgency_score,'
            . ' hesitation_detected, co_revealed_types, co_revealed_count,'
            . ' stimulus_msg_id::text AS stimulus_msg_id,'
            . ' revelation_turn, revelation_turn_ratio'
            . ' FROM ioc_context WHERE obs_id IN (?)';

        /** @var list<array<string, mixed>> $rows */
        $rows = $this->connection->fetchAllAssociative(
            $sql,
            [$obsIds],
            [\Doctrine\DBAL\ArrayParameterType::STRING],
        );

        $byObsId = [];

        foreach ($rows as $row) {
            $rowObsId = $row['obs_id'] ?? null;

            if (\is_string($rowObsId)) {
                $byObsId[$rowObsId] = $row;
            }
        }

        $out = [];

        foreach ($iocsByMsg as $ioc) {
            $obsIdField = $ioc['obs_id'] ?? null;
            $obsId = \is_string($obsIdField) ? $obsIdField : '';
            $ctxRow = '' !== $obsId ? ($byObsId[$obsId] ?? null) : null;

            if (null === $ctxRow || 'enriched' !== ($ctxRow['enrichment_status'] ?? null)) {
                $statusVal = null === $ctxRow ? null : ($ctxRow['enrichment_status'] ?? null);
                $ioc['revelation_context'] = null === $ctxRow ? null : [
                    'enrichment_status' => \is_string($statusVal) ? $statusVal : 'unknown',
                ];

                $out[] = $ioc;

                continue;
            }

            // Validate stimulus_msg_id belongs to this conversation
            $stimMsgId = \is_string($ctxRow['stimulus_msg_id'] ?? null) ? (string) $ctxRow['stimulus_msg_id'] : null;

            if (null !== $stimMsgId && !isset($messageIdSet[$stimMsgId])) {
                $stimMsgId = null;
            }

            $coRevealed = $this->parsePgTextArray(\is_string($ctxRow['co_revealed_types'] ?? null) ? (string) $ctxRow['co_revealed_types'] : null);

            $ioc['revelation_context'] = [
                'enrichment_status' => 'enriched',
                'enrichment_confidence' => $this->floatOrNull($ctxRow['enrichment_confidence']),
                'context_excerpt' => \is_string($ctxRow['context_excerpt'] ?? null) ? (string) $ctxRow['context_excerpt'] : null,
                'semantic_role' => \is_string($ctxRow['semantic_role'] ?? null) ? (string) $ctxRow['semantic_role'] : null,
                'stimulus_type' => \is_string($ctxRow['stimulus_type'] ?? null) ? (string) $ctxRow['stimulus_type'] : null,
                'urgency_score' => $this->floatOrNull($ctxRow['urgency_score']),
                'hesitation_detected' => $this->boolOrNull($ctxRow['hesitation_detected']),
                'co_revealed_types' => $coRevealed,
                'co_revealed_count' => is_numeric($ctxRow['co_revealed_count'] ?? null) ? (int) $ctxRow['co_revealed_count'] : 0,
                'stimulus_msg_id' => $stimMsgId,
                'revelation_turn' => is_numeric($ctxRow['revelation_turn'] ?? null) ? (int) $ctxRow['revelation_turn'] : null,
                'revelation_turn_ratio' => $this->floatOrNull($ctxRow['revelation_turn_ratio']),
            ];

            $out[] = $ioc;
        }

        return $out;
    }

    /**
     * @param list<array<string, mixed>> $iocsByMsg
     */
    /**
     * Spec 099 S6 — count IOCs in the Actionable tier. The Context tier
     * (subject/message_id/auth-results/whois) is excluded so the headline
     * number in the Theater Intelligence panel reflects analyst-pivotable
     * IOCs only. The mapping mirrors the frontend `tierForIocType`.
     *
     * @param list<array<string, mixed>> $iocsByMsg
     */
    private function countActionableIocs(array $iocsByMsg): int
    {
        // Spec 111 — delegate to the shared policy so the List + Detail
        // + Theater views all use the same definition of "actionable".
        $count = 0;

        foreach ($iocsByMsg as $ioc) {
            $type = \is_string($ioc['type'] ?? null) ? $ioc['type'] : null;

            if ($type !== null && \App\Domain\Communication\Policy\IocActionablePolicy::isActionable($type)) {
                $count++;
            }
        }

        return $count;
    }

    /**
     * @param list<array<string, mixed>> $iocsByMsg
     */
    private function computeEnrichmentCoverage(array $iocsByMsg): float
    {
        if ([] === $iocsByMsg) {
            return 0.0;
        }

        $enriched = 0;

        foreach ($iocsByMsg as $ioc) {
            $ctx = $ioc['revelation_context'];

            if (\is_array($ctx) && 'enriched' === ($ctx['enrichment_status'] ?? null)) {
                $enriched++;
            }
        }

        return round(100.0 * $enriched / \count($iocsByMsg), 1);
    }

    private function floatOrNull(mixed $v): ?float
    {
        return is_numeric($v) ? (float) $v : null;
    }

    private function boolOrNull(mixed $v): ?bool
    {
        return null === $v ? null : (bool) $v;
    }

    /**
     * Parse Postgres text[] literal like `{a,b,c}` into a PHP list.
     *
     * @return list<string>
     */
    private function parsePgTextArray(?string $literal): array
    {
        if (null === $literal || '' === $literal || '{}' === $literal) {
            return [];
        }

        $inner = trim($literal, '{}');

        if ('' === $inner) {
            return [];
        }

        $parts = array_map(
            static fn (string $s): string => trim($s, " \"\t\n"),
            explode(',', $inner),
        );

        return array_values(array_filter($parts, static fn (string $s): bool => '' !== $s));
    }

    /**
     * @return list<Message>
     */
    private function loadOrderedMessages(string $convId): array
    {
        $qb = $this->em->createQueryBuilder();
        $qb->select('m')
            ->from(Message::class, 'm')
            ->where('m.conversation = :convId')
            ->andWhere('m.deletedAt IS NULL')
            ->setParameter('convId', $convId)
            ->orderBy('m.tsMsg', 'ASC')
            ->addOrderBy('m.msgId', 'ASC');

        /** @var list<Message> $result */
        $result = $qb->getQuery()->getResult();

        return $result;
    }

    /**
     * @param list<Message> $messages
     *
     * @return array<string, true>
     */
    private function buildMessageIdSet(array $messages): array
    {
        $set = [];

        foreach ($messages as $m) {
            $set[$m->getMsgId()] = true;
        }

        return $set;
    }

    /**
     * @param list<Message> $messages
     *
     * @return list<array<string, mixed>>
     */
    private function serializeMessages(array $messages): array
    {
        $out = [];

        foreach ($messages as $idx => $m) {
            $headers = $m->getHeaders();
            $from = \is_string($headers['from'] ?? null) ? (string) $headers['from'] : '';

            $out[] = [
                'idx' => $idx + 1,
                'msg_id' => $m->getMsgId(),
                'direction' => 'in' === $m->getDirection()->getCode() ? 'in' : 'out',
                'ts_msg' => $m->getTsMsg()->format(DATE_ATOM),
                'sender' => $from,
                'subject' => $m->getSubject(),
                'body_text' => $m->getBodyText(),
                'lang_detect' => $m->getLangDetect(),
            ];
        }

        return $out;
    }

    /**
     * @param list<ObservedIoc>   $iocs
     * @param array<string, true> $messageIdSet
     *
     * @return list<array<string, mixed>>
     */
    private function serializeAndDedupIocs(array $iocs, array $messageIdSet): array
    {
        // Sort by message ts_msg ASC then ts_observed ASC so the first
        // occurrence in conversation-time order wins on dedup.
        usort($iocs, static function (ObservedIoc $a, ObservedIoc $b): int {
            $tsA = $a->getMessage()->getTsMsg();
            $tsB = $b->getMessage()->getTsMsg();
            $tsCmp = $tsA <=> $tsB;

            if (0 !== $tsCmp) {
                return $tsCmp;
            }

            return $a->getTsObserved() <=> $b->getTsObserved();
        });

        $seen = [];
        $out = [];

        foreach ($iocs as $ioc) {
            $msg = $ioc->getMessage();
            $msgId = $msg->getMsgId();

            // Orphan IOC: parent message not in the (possibly truncated)
            // messages list. Skip.
            if (!isset($messageIdSet[$msgId])) {
                continue;
            }

            $context = $ioc->getContext();
            $valueNorm = \is_string($context['value_norm'] ?? null) ? (string) $context['value_norm'] : '';

            if ('' === $valueNorm) {
                continue;
            }

            if (isset($seen[$valueNorm])) {
                continue;
            }
            $seen[$valueNorm] = true;

            $type = \is_string($context['type'] ?? null) ? (string) $context['type'] : '';
            $value = \is_string($context['value'] ?? null) ? (string) $context['value'] : '';

            $out[] = [
                'msg_id' => $msgId,
                'obs_id' => $ioc->getObsId(),
                'indicator_id' => $ioc->getIndicatorId(),
                'type' => $type,
                'value' => $value,
                'value_norm' => $valueNorm,
                'category' => IocCategory::classify($type),
                'ts_observed' => $ioc->getTsObserved()->format(DATE_ATOM),
                // Slice 2 will populate this from a JOIN on ioc_context.
                'revelation_context' => null,
            ];
        }

        return $out;
    }

    /**
     * @param list<Message> $messages
     */
    private function extractScammerAddress(array $messages): ?string
    {
        foreach ($messages as $m) {
            if ('in' === $m->getDirection()->getCode()) {
                $headers = $m->getHeaders();

                return \is_string($headers['from'] ?? null) ? (string) $headers['from'] : null;
            }
        }

        return null;
    }

    /**
     * @param list<Message> $messages
     */
    private function extractPersonaAddress(array $messages): ?string
    {
        foreach ($messages as $m) {
            if ('out' === $m->getDirection()->getCode()) {
                $headers = $m->getHeaders();

                return \is_string($headers['from'] ?? null) ? (string) $headers['from'] : null;
            }
        }

        return null;
    }
}
