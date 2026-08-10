<?php

declare(strict_types=1);

namespace App\Tests\Integration\Communication;

use App\Application\Communication\IocHandler;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * The analyst verdict — and the resulting export-hold status for financial
 * IOCs — must be visible in the IOC read APIs, otherwise the UI cannot show
 * an analyst what is waiting for review nor let them release it.
 */
final class IocVerdictExposureTest extends KernelTestCase
{
    private const IBAN_HELD = 'ffffff01-0000-4000-8000-000000000001';
    private const IBAN_CONFIRMED = 'ffffff01-0000-4000-8000-000000000002';
    private const DOMAIN_FP = 'ffffff01-0000-4000-8000-000000000003';
    private const DOMAIN_PLAIN = 'ffffff01-0000-4000-8000-000000000004';

    private const ROWS = [
        self::IBAN_HELD => ['iban', 'IT60X0542811101000000123456'],
        self::IBAN_CONFIRMED => ['iban', 'GB82WEST12345698765432'],
        self::DOMAIN_FP => ['domain', 'verdict-fp.example'],
        self::DOMAIN_PLAIN => ['domain', 'verdict-plain.example'],
    ];

    private IocHandler $handler;
    private Connection $conn;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->handler = self::getContainer()->get(IocHandler::class);
        $this->conn = self::getContainer()->get(Connection::class);

        $msgId = $this->conn->fetchOne('SELECT msg_id FROM message LIMIT 1');
        self::assertNotFalse($msgId, 'integration fixtures must provide at least one message');

        $i = 0;

        foreach (self::ROWS as $id => [$type, $value]) {
            $this->conn->executeStatement('DELETE FROM ioc_analyst_feedback WHERE indicator_id = :id', ['id' => $id]);
            $this->conn->executeStatement('DELETE FROM observed_ioc WHERE indicator_id = :id', ['id' => $id]);
            $this->conn->executeStatement('DELETE FROM indicator WHERE indicator_id = :id', ['id' => $id]);
            $this->conn->executeStatement(
                "INSERT INTO indicator (indicator_id, type, value, value_norm, first_seen, last_seen, occurrences, tlp, created_at, updated_at)
                 VALUES (:id, :type, :value, :value, NOW(), NOW(), 1, 'AMBER', NOW(), NOW())",
                ['id' => $id, 'type' => $type, 'value' => $value],
            );
            $this->conn->executeStatement(
                "INSERT INTO observed_ioc (obs_id, msg_id, indicator_id, confidence_score, context_observation, ts_observed)
                 VALUES (:obs, :msg, :id, 0.8, '{}', NOW())",
                ['obs' => sprintf('ffffff02-0000-4000-8000-%012d', ++$i), 'msg' => $msgId, 'id' => $id],
            );
        }

        $this->recordVerdict(self::IBAN_CONFIRMED, 'confirmed');
        $this->recordVerdict(self::DOMAIN_FP, 'false_positive');
    }

    private function recordVerdict(string $indicatorId, string $verdict): void
    {
        $this->conn->executeStatement(
            "INSERT INTO ioc_analyst_feedback (indicator_id, verdict, note, analyst_id, created_at)
             VALUES (:id, :verdict, 'test', 'verdict-exposure-test', NOW())
             ON CONFLICT (indicator_id) DO UPDATE SET verdict = EXCLUDED.verdict",
            ['id' => $indicatorId, 'verdict' => $verdict],
        );
    }

    /**
     * @return array<string, array<string, mixed>> list rows keyed by ioc_id (first observation wins)
     */
    private function listRowsByIndicator(): array
    {
        $byId = [];

        foreach ($this->handler->getAllIocsWithConfidence() as $row) {
            $id = \is_string($row['ioc_id'] ?? null) ? $row['ioc_id'] : '';

            if ($id !== '' && !isset($byId[$id])) {
                $byId[$id] = $row;
            }
        }

        return $byId;
    }

    public function testListExposesVerdictAndExportHold(): void
    {
        $rows = $this->listRowsByIndicator();

        self::assertArrayHasKey(self::IBAN_HELD, $rows);
        self::assertNull($rows[self::IBAN_HELD]['analyst_verdict'], 'no verdict yet');
        self::assertTrue($rows[self::IBAN_HELD]['export_held'], 'an unreviewed IBAN is held from export');

        self::assertSame('confirmed', $rows[self::IBAN_CONFIRMED]['analyst_verdict']);
        self::assertFalse($rows[self::IBAN_CONFIRMED]['export_held'], 'a confirmed IBAN is released');

        self::assertSame('false_positive', $rows[self::DOMAIN_FP]['analyst_verdict']);
        self::assertFalse($rows[self::DOMAIN_FP]['export_held'], 'a non-financial IOC is never "held" (FP has its own badge)');

        self::assertNull($rows[self::DOMAIN_PLAIN]['analyst_verdict']);
        self::assertFalse($rows[self::DOMAIN_PLAIN]['export_held']);
    }

    public function testDetailExposesVerdictAndExportHold(): void
    {
        $held = $this->handler->getIocDetail(self::IBAN_HELD);
        self::assertNull($held['analyst_verdict']);
        self::assertTrue($held['export_held']);

        self::assertNull($held['analyst_note'], 'no feedback row yet → no note');

        $confirmed = $this->handler->getIocDetail(self::IBAN_CONFIRMED);
        self::assertSame('confirmed', $confirmed['analyst_verdict']);
        self::assertFalse($confirmed['export_held']);
        self::assertSame('test', $confirmed['analyst_note'], 'the recorded note must round-trip to the detail view');

        $fp = $this->handler->getIocDetail(self::DOMAIN_FP);
        self::assertSame('false_positive', $fp['analyst_verdict']);
        self::assertFalse($fp['export_held']);
    }
}
