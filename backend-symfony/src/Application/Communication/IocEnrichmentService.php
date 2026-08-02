<?php

declare(strict_types=1);

namespace App\Application\Communication;

use App\Domain\Communication\Message;
use App\Domain\Communication\ObservedIoc;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Handles IOC risk scoring, enrichment updates, and reply decisions.
 *
 * Extracted from IocHandler (CT-0 decomposition).
 */
class IocEnrichmentService
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly RiskScorer $riskScorer,
        private readonly IocExportMapper $exportMapper,
        private readonly RiskScoreCalculator $riskScoreCalculator,
    ) {
    }

    /**
     * Calculate aggregate risk score for a message based on its IOCs.
     *
     * @throws \RuntimeException If message not found
     *
     * @return array{score_agg: int, level: 'high'|'medium'|'low', reason: string, should_reply: bool}
     */
    public function calculateMessageRisk(string $msgId): array
    {
        $message = $this->em->getRepository(Message::class)->find($msgId);

        if ($message === null) {
            throw new \RuntimeException('Message not found: ' . $msgId);
        }

        // Closed-conversation short-circuit.
        // ReplyHandler refuses to generate replies on non-open conversations
        // (defense-in-depth at ReplyHandler.php:104). Surface should_reply=false
        // at the /risk layer so n8n's Decision Gate skips WF-REPLY-GENERATE-V2
        // entirely instead of triggering it to get a 400 downstream. Saves a
        // sub-workflow invocation per inbound on each of the ~47 closed convs
        // still receiving scammer mail (audit 2026-05-24).
        $convStatus = $message->getConversation()->getStatus()->value;

        if ($convStatus !== 'open') {
            return [
                'score_agg' => 0,
                'level' => 'low',
                'reason' => sprintf('conversation_closed: %s', $convStatus),
                'should_reply' => false,
            ];
        }

        // Pre-filter decision shortcut.
        // matchPreFilter wrote headers['pre_filter'] at ingest
        // if the message was deemed non-conversational. Override any
        // IOC-based scoring (external + intrinsic) and refuse
        // to reply. Without this, body IOCs (URL/domain/email) push
        // score_agg into medium and trigger reply on DMARC reports,
        // GitHub notifications, postmaster bounces, etc.
        $headers = $message->getHeaders();
        $preFilter = $headers['pre_filter'] ?? null;

        if (is_array($preFilter)
            && isset($preFilter['kind'], $preFilter['pattern'])
            && is_string($preFilter['kind'])
            && is_string($preFilter['pattern'])
        ) {
            return [
                'score_agg' => 0,
                'level' => 'low',
                'reason' => sprintf('pre_filtered: %s:%s', $preFilter['kind'], $preFilter['pattern']),
                'should_reply' => false,
            ];
        }

        $iocs = $this->em->getRepository(ObservedIoc::class)->findBy(['message' => $message]);

        if ($iocs === []) {
            return [
                'score_agg' => 0,
                'level' => 'low',
                'reason' => 'No IOCs detected',
                'should_reply' => false,
            ];
        }

        $externalMaxScore = 0;
        $reasons = [];
        $typeSet = [];
        $urlCount = 0;

        foreach ($iocs as $ioc) {
            $context = $ioc->getContext();
            $scoreData = $context['score'] ?? [];

            $iocScore = 0;

            if (is_array($scoreData) && isset($scoreData['agg']) && is_int($scoreData['agg'])) {
                $iocScore = $scoreData['agg'];
            }

            if ($iocScore > $externalMaxScore) {
                $externalMaxScore = $iocScore;
            }

            if (is_array($scoreData) && isset($scoreData['explain']) && is_string($scoreData['explain'])) {
                $explainText = $scoreData['explain'];
                $typeValue = (isset($context['type']) && is_string($context['type'])) ? $context['type'] : 'unknown';
                $reasons[] = sprintf('%s: %s', $typeValue, $explainText);
            }

            $typeValue = (isset($context['type']) && is_string($context['type'])) ? $context['type'] : '';

            if ($typeValue !== '') {
                $typeSet[$typeValue] = true;

                if ($typeValue === 'url') {
                    $urlCount++;
                }
            }
        }

        // Combine external (VT/URLscan) with intrinsic
        // (RiskScoreCalculator: scam-type baseline + IOC-presence bonuses).
        // The worse of the two wins, so a mail with an IBAN that VT never
        // saw still gets a high score from the intrinsic side.
        $scamCode = $message->getConversation()->getScamType()->getCode();
        $intrinsicScore = $this->riskScoreCalculator->compute($scamCode, $typeSet, $urlCount);
        $maxScore = max($externalMaxScore, $intrinsicScore);

        // When the intrinsic score is the dominant signal,
        // append an explanatory line to the reasons so the operator sees
        // WHY the bot will reply even though no external enrichment flagged
        // anything.
        if ($intrinsicScore > $externalMaxScore) {
            $reasons[] = $this->buildIntrinsicReason($typeSet, $scamCode, $intrinsicScore);
        }

        $level = $this->riskScorer->determineLevel($maxScore);

        $iocTypes = array_map(function ($ioc): array {
            $context = $ioc->getContext();
            $typeValue = isset($context['type']) && is_string($context['type']) ? $context['type'] : '';

            return ['type' => $typeValue];
        }, $iocs);
        $shouldReply = $this->riskScorer->shouldReply($maxScore, $level, $iocTypes);

        // Conversation-continuity override.
        // Per-message scoring is the cold-start anti-noise filter (DMARC
        // reports, noreply autoresponders, bounces). On follow-up pings in
        // an already-engaged conversation, the per-message intrinsic score
        // regularly lands 1-5 points below the medium threshold — either
        // because the body is bare ("are you still interested?") or because
        // the race on body-IOC extraction recurs intermittently.
        // The conv-level score_risk is a monotonic max maintained by
        // IngestPostProcessor; it is the engagement-continuity signal.
        // When the conv has been seen at medium+ at any earlier point,
        // honour that signal and keep the bot replying. Downstream guards
        // (ReplyHandler cadence/rate-limits/PolicyGuard/kill switch) stay
        // intact and continue to gate any abuse.
        if (!$shouldReply && $message->getConversation()->getScoreRisk() >= 40) {
            $shouldReply = true;
            $reasons[] = sprintf(
                'continuity_override: per-msg=%d below threshold but conv=%d (already engaged)',
                $maxScore,
                $message->getConversation()->getScoreRisk()
            );
        }

        return [
            'score_agg' => $maxScore,
            'level' => $level,
            'reason' => implode(' ; ', $reasons),
            'should_reply' => $shouldReply,
        ];
    }

    /**
     * Update enrichment data for an existing IOC.
     *
     * @param string               $obsId      Observation ID (UUID)
     * @param array<string, mixed> $enrichment Enrichment data (urlscan, virustotal)
     *
     * @throws \RuntimeException If IOC not found
     */
    public function updateIocEnrichment(string $obsId, array $enrichment): ObservedIoc
    {
        $observedIoc = $this->em->getRepository(ObservedIoc::class)->find($obsId);

        if ($observedIoc === null) {
            throw new \RuntimeException("IOC not found: {$obsId}");
        }

        $this->updateIocContext($observedIoc, ['enrichment' => $enrichment]);

        $indicatorId = $observedIoc->getIndicatorId();
        $context = $observedIoc->getContext();
        $fullEnrichment = $context['enrichment'] ?? [];
        $score = $context['score'] ?? [];

        $conn = $this->em->getConnection();
        $now = (new \DateTimeImmutable())->format('Y-m-d H:i:s');

        $conn->executeStatement(
            'UPDATE indicator SET
                enrichment = :enrichment,
                score = :score,
                last_enriched = :lastEnriched,
                updated_at = :updatedAt
            WHERE indicator_id = :indicatorId',
            [
                'enrichment' => json_encode($fullEnrichment),
                'score' => json_encode($score),
                'lastEnriched' => $now,
                'updatedAt' => $now,
                'indicatorId' => $indicatorId,
            ]
        );

        $this->em->flush();

        return $observedIoc;
    }

    /**
     * Update existing IOC context with new enrichment data.
     *
     * @param ObservedIoc          $ioc     Existing IOC entity
     * @param array<string, mixed> $newData New data from n8n
     */
    public function updateIocContext(ObservedIoc $ioc, array $newData): void
    {
        $context = $ioc->getContext();

        if (isset($newData['enrichment']) && is_array($newData['enrichment'])) {
            $existingEnrichment = $context['enrichment'] ?? [];
            $context['enrichment'] = is_array($existingEnrichment) ? array_merge($existingEnrichment, $newData['enrichment']) : $newData['enrichment'];
        }

        /** @phpstan-ignore-next-line */
        $context['score'] = $this->riskScorer->calculateIocScore($context['enrichment'] ?? []);

        if (isset($newData['category']) && is_string($newData['category'])) {
            $context['category'] = $newData['category'];
        }

        if (isset($newData['tags']) && is_array($newData['tags'])) {
            $existingTags = $context['tags'] ?? [];
            $context['tags'] = array_unique(is_array($existingTags) ? array_merge($existingTags, $newData['tags']) : $newData['tags']);
        }

        $context = $this->exportMapper->enrichWithExportMetadata($context);

        $ioc->updateContext($context);
    }

    /**
     * Build a human-readable reason line explaining why the intrinsic
     * scorer pushed `score_agg` above the external max. Names the IOC
     * type(s) responsible so the operator can correlate with the IOC
     * list shown elsewhere in the conversation view.
     *
     * @param array<string, bool> $typeSet
     */
    private function buildIntrinsicReason(array $typeSet, string $scamCode, int $intrinsicScore): string
    {
        $financialTypes = array_intersect(['iban', 'bic', 'wallet_btc', 'wallet_eth', 'wallet_xmr', 'credit_card'], array_keys($typeSet));
        $triggers = [];

        if ($financialTypes !== []) {
            $triggers[] = 'financial IOC (' . implode(', ', $financialTypes) . ')';
        }

        if (isset($typeSet['phone'])) {
            $triggers[] = 'phone IOC';
        }

        if (isset($typeSet['url'])) {
            $triggers[] = 'URL IOC';
        }

        if ($triggers === []) {
            // No bonus IOC fired the increment — must be the scam-type
            // baseline alone (e.g., CEO_FRAUD base 70 with no IOCs).
            $triggers[] = 'scam-type baseline (' . $scamCode . ')';
        }

        return sprintf('intrinsic: %s — score=%d', implode(' + ', $triggers), $intrinsicScore);
    }
}
