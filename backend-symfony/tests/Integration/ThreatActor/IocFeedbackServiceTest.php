<?php

declare(strict_types=1);

namespace App\Tests\Integration\ThreatActor;

use App\Application\ThreatActor\IocFeedbackService;
use App\Domain\ThreatActor\AnalystVerdict;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class IocFeedbackServiceTest extends KernelTestCase
{
    private const INDICATOR = 'ffffffff-0001-4000-8000-000000000001';

    private Connection $conn;
    private IocFeedbackService $service;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->conn = self::getContainer()->get(Connection::class);
        $this->service = new IocFeedbackService($this->conn);

        $this->conn->executeStatement('DELETE FROM observed_ioc WHERE indicator_id = :id', ['id' => self::INDICATOR]);
        $this->conn->executeStatement('DELETE FROM ioc_analyst_feedback WHERE indicator_id = :id', ['id' => self::INDICATOR]);
        $this->conn->executeStatement('DELETE FROM indicator WHERE indicator_id = :id', ['id' => self::INDICATOR]);
        $this->conn->executeStatement(
            "INSERT INTO indicator (indicator_id, type, value, value_norm, first_seen, last_seen, occurrences, tlp, created_at, updated_at)
             VALUES (:id, 'domain', 'fb-test.com', 'fb-test.com', NOW(), NOW(), 1, 'AMBER', NOW(), NOW())",
            ['id' => self::INDICATOR],
        );
    }

    protected function tearDown(): void
    {
        $this->conn->executeStatement('DELETE FROM observed_ioc WHERE indicator_id = :id', ['id' => self::INDICATOR]);
        $this->conn->executeStatement('DELETE FROM indicator WHERE indicator_id = :id', ['id' => self::INDICATOR]);
        parent::tearDown();
    }

    /**
     * Seed N observations of the test indicator with the given confidence scores, reusing an
     * existing fixture message to satisfy the observed_ioc.msg_id FK. Returns the obs_ids.
     *
     * @param list<float> $scores
     *
     * @return list<string>
     */
    private function seedObservations(array $scores): array
    {
        $msgId = $this->conn->fetchOne('SELECT msg_id FROM message LIMIT 1');
        self::assertNotFalse($msgId, 'integration fixtures must provide at least one message');

        $obsIds = [];

        foreach ($scores as $i => $score) {
            $obsId = sprintf('ffffffff-0002-4000-8000-%012d', $i + 1);
            $this->conn->executeStatement(
                "INSERT INTO observed_ioc (obs_id, msg_id, indicator_id, confidence_score, context_observation, ts_observed)
                 VALUES (:obs, :msg, :ind, :score, '{}', NOW())",
                ['obs' => $obsId, 'msg' => $msgId, 'ind' => self::INDICATOR, 'score' => $score],
            );
            $obsIds[] = $obsId;
        }

        return $obsIds;
    }

    /** @return list<float> confidence_score of every observation of the test indicator */
    private function observationScores(): array
    {
        return array_map(
            static fn ($v): float => round((float) $v, 2),
            $this->conn->fetchFirstColumn(
                'SELECT confidence_score FROM observed_ioc WHERE indicator_id = :id ORDER BY obs_id',
                ['id' => self::INDICATOR],
            ),
        );
    }

    public function testConfirmedVerdictPinsEveryObservationConfidenceHigh(): void
    {
        $this->seedObservations([0.75, 0.60]);

        $this->service->submit(self::INDICATOR, AnalystVerdict::Confirmed, 'real', 'a1');

        // Confirmed => GREATEST(score, 0.99) for ALL observations of the indicator.
        self::assertSame([0.99, 0.99], $this->observationScores());
    }

    public function testFalsePositiveVerdictDropsEveryObservationConfidence(): void
    {
        $this->seedObservations([0.95, 0.80]);

        $this->service->submit(self::INDICATOR, AnalystVerdict::FalsePositive, 'bogus', 'a1');

        // False-positive => 0.05 for ALL observations.
        self::assertSame([0.05, 0.05], $this->observationScores());
    }

    public function testChangingVerdictRePersistsConfidence(): void
    {
        $this->seedObservations([0.90]);

        $this->service->submit(self::INDICATOR, AnalystVerdict::Confirmed, null, 'a1');
        self::assertSame([0.99], $this->observationScores());

        $this->service->submit(self::INDICATOR, AnalystVerdict::FalsePositive, null, 'a2');
        self::assertSame([0.05], $this->observationScores());
    }

    public function testSubmitThenReadBackVerdict(): void
    {
        self::assertTrue($this->service->indicatorExists(self::INDICATOR));
        self::assertNull($this->service->getVerdict(self::INDICATOR));

        $this->service->submit(self::INDICATOR, AnalystVerdict::Confirmed, 'looks real', 'analyst@example.com');

        self::assertSame(AnalystVerdict::Confirmed, $this->service->getVerdict(self::INDICATOR));
    }

    public function testSubmitUpsertsRatherThanDuplicates(): void
    {
        $this->service->submit(self::INDICATOR, AnalystVerdict::Confirmed, null, 'a1');
        $this->service->submit(self::INDICATOR, AnalystVerdict::FalsePositive, 'changed my mind', 'a2');

        $count = (int) $this->conn->fetchOne(
            'SELECT COUNT(*) FROM ioc_analyst_feedback WHERE indicator_id = :id',
            ['id' => self::INDICATOR],
        );
        self::assertSame(1, $count);
        self::assertSame(AnalystVerdict::FalsePositive, $this->service->getVerdict(self::INDICATOR));
    }

    public function testGetVerdictsBatch(): void
    {
        $this->service->submit(self::INDICATOR, AnalystVerdict::Confirmed, null, 'a1');

        $verdicts = $this->service->getVerdicts([self::INDICATOR, 'ffffffff-0001-4000-8000-000000000099']);

        self::assertSame([self::INDICATOR => AnalystVerdict::Confirmed], $verdicts);
    }

    public function testGetVerdictsEmptyInput(): void
    {
        self::assertSame([], $this->service->getVerdicts([]));
    }
}
