<?php

declare(strict_types=1);

namespace App\Application\ThreatActor;

use App\Application\Communication\IocConfidenceCalculator;
use App\Domain\ThreatActor\AnalystVerdict;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;

/**
 * Analyst IOC-feedback loop: records the current confirmed/false-positive verdict
 * per indicator (one row, upserted) AND persists that verdict into the IOC confidence
 * of every observation of the indicator, so the human verdict flows through the whole
 * pipeline (all STIX exports read observed_ioc.confidence_score; the feeds read the
 * verdict directly).
 */
final readonly class IocFeedbackService implements IocFeedbackReaderInterface
{
    public function __construct(
        private Connection $conn,
    ) {
    }

    public function indicatorExists(string $indicatorId): bool
    {
        $row = $this->conn->fetchOne('SELECT 1 FROM indicator WHERE indicator_id = :id', ['id' => $indicatorId]);

        return $row !== false && $row !== null;
    }

    public function submit(string $indicatorId, AnalystVerdict $verdict, ?string $note, string $analystId): void
    {
        $this->conn->executeStatement(
            <<<'SQL'
                INSERT INTO ioc_analyst_feedback (indicator_id, verdict, note, analyst_id, created_at)
                VALUES (:id, :verdict, :note, :analyst, :ts)
                ON CONFLICT (indicator_id) DO UPDATE SET
                    verdict = EXCLUDED.verdict,
                    note = EXCLUDED.note,
                    analyst_id = EXCLUDED.analyst_id,
                    created_at = EXCLUDED.created_at
                SQL,
            [
                'id'      => $indicatorId,
                'verdict' => $verdict->value,
                'note'    => $note,
                'analyst' => $analystId,
                'ts'      => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
            ],
        );

        $this->persistVerdictConfidence($indicatorId, $verdict);
    }

    /**
     * Fold the verdict into the persisted confidence of every observation of the indicator.
     * Confirmed pins confidence high (never lowers an already-higher score); false-positive
     * drops it near zero. Mirrors IocConfidenceCalculator::applyAnalystVerdict, applied in bulk.
     */
    private function persistVerdictConfidence(string $indicatorId, AnalystVerdict $verdict): void
    {
        match ($verdict) {
            AnalystVerdict::Confirmed => $this->conn->executeStatement(
                'UPDATE observed_ioc SET confidence_score = GREATEST(confidence_score, :conf) WHERE indicator_id = :id',
                ['conf' => IocConfidenceCalculator::CONFIRMED_CONFIDENCE, 'id' => $indicatorId],
            ),
            AnalystVerdict::FalsePositive => $this->conn->executeStatement(
                'UPDATE observed_ioc SET confidence_score = :conf WHERE indicator_id = :id',
                ['conf' => IocConfidenceCalculator::FALSE_POSITIVE_CONFIDENCE, 'id' => $indicatorId],
            ),
        };
    }

    public function getVerdict(string $indicatorId): ?AnalystVerdict
    {
        $value = $this->conn->fetchOne(
            'SELECT verdict FROM ioc_analyst_feedback WHERE indicator_id = :id',
            ['id' => $indicatorId],
        );

        return \is_string($value) ? AnalystVerdict::tryFrom($value) : null;
    }

    public function getVerdicts(array $indicatorIds): array
    {
        if ($indicatorIds === []) {
            return [];
        }

        /** @var array<string, string> $rows */
        $rows = $this->conn->fetchAllKeyValue(
            'SELECT indicator_id, verdict FROM ioc_analyst_feedback WHERE indicator_id IN (:ids)',
            ['ids' => $indicatorIds],
            ['ids' => ArrayParameterType::STRING],
        );

        $verdicts = [];

        foreach ($rows as $indicatorId => $verdict) {
            $parsed = AnalystVerdict::tryFrom($verdict);

            if ($parsed instanceof AnalystVerdict) {
                $verdicts[$indicatorId] = $parsed;
            }
        }

        return $verdicts;
    }
}
