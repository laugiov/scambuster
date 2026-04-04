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

        if (!$message) {
            throw new \RuntimeException('Message not found: ' . $msgId);
        }

        $iocs = $this->em->getRepository(ObservedIoc::class)->findBy(['message' => $message]);

        if (empty($iocs)) {
            return [
                'score_agg' => 0,
                'level' => 'low',
                'reason' => 'No IOCs detected',
                'should_reply' => false,
            ];
        }

        $maxScore = 0;
        $reasons = [];

        foreach ($iocs as $ioc) {
            $context = $ioc->getContext();
            $scoreData = $context['score'] ?? [];

            $iocScore = 0;

            if (is_array($scoreData) && isset($scoreData['agg']) && is_int($scoreData['agg'])) {
                $iocScore = $scoreData['agg'];
            }

            if ($iocScore > $maxScore) {
                $maxScore = $iocScore;
            }

            if (is_array($scoreData) && isset($scoreData['explain']) && is_string($scoreData['explain'])) {
                $explainText = $scoreData['explain'];
                $typeValue = (isset($context['type']) && is_string($context['type'])) ? $context['type'] : 'unknown';
                $reasons[] = sprintf('%s: %s', $typeValue, $explainText);
            }
        }

        $level = $this->riskScorer->determineLevel($maxScore);

        $iocTypes = array_map(function ($ioc) {
            $context = $ioc->getContext();
            $typeValue = isset($context['type']) && is_string($context['type']) ? $context['type'] : '';

            return ['type' => $typeValue];
        }, $iocs);
        $shouldReply = $this->riskScorer->shouldReply($maxScore, $level, $iocTypes);

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

        if (!$observedIoc) {
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
}
