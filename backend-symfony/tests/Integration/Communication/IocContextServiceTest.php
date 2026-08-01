<?php

declare(strict_types=1);

namespace App\Tests\Integration\Communication;

use App\Application\Communication\IocContextService;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * TDD tests for IocContextService.
 * Written BEFORE implementation -- must FAIL until IocContextService is created.
 */
class IocContextServiceTest extends KernelTestCase
{
    private Connection $connection;
    private IocContextService $service;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = static::getContainer();
        $this->connection = $container->get(Connection::class);
        $this->service = new IocContextService(
            $this->connection,
            new \Psr\Log\NullLogger(),
        );
    }

    public function testHeaderIocsAreSkipped(): void
    {
        // Header IOC types should get status = 'skipped'
        $headerTypes = ['message_id', 'subject', 'spf_result', 'dkim_result', 'dmarc_result', 'x_mailer', 'return_path'];

        foreach ($headerTypes as $type) {
            $this->assertTrue(
                IocContextService::isHeaderIocType($type),
                "Expected '{$type}' to be identified as header IOC type"
            );
        }
    }

    public function testNonHeaderIocsAreNotSkipped(): void
    {
        $nonHeaderTypes = ['url', 'domain', 'email', 'iban', 'phone', 'wallet_btc', 'ipv4'];

        foreach ($nonHeaderTypes as $type) {
            $this->assertFalse(
                IocContextService::isHeaderIocType($type),
                "Expected '{$type}' to NOT be identified as header IOC type"
            );
        }
    }

    public function testTurnRatioZeroWhenTotalIsZero(): void
    {
        $ratio = IocContextService::computeTurnRatio(0, 0);
        $this->assertSame(0.0, $ratio, 'Turn ratio should be 0.0 when total turns is 0');
    }

    public function testTurnRatioComputedCorrectly(): void
    {
        $ratio = IocContextService::computeTurnRatio(4, 10);
        $this->assertEqualsWithDelta(0.4, $ratio, 0.001);
    }

    public function testTurnRatioOneWhenTurnEqualsTotal(): void
    {
        $ratio = IocContextService::computeTurnRatio(10, 10);
        $this->assertEqualsWithDelta(1.0, $ratio, 0.001);
    }

    public function testEngagementHoursFromSeconds(): void
    {
        $hours = IocContextService::secondsToHours(175320);
        $this->assertEqualsWithDelta(48.7, $hours, 0.1);
    }

    public function testEngagementHoursZeroForZeroSeconds(): void
    {
        $hours = IocContextService::secondsToHours(0);
        $this->assertSame(0.0, $hours);
    }

    public function testComputeAndPersistCreatesRows(): void
    {
        // IocContextTestFixtures creates url/iban/phone IOCs
        $row = $this->connection->fetchAssociative(
            'SELECT oi.obs_id, oi.indicator_id, oi.msg_id,
                    i.type AS ioc_type
             FROM observed_ioc oi
             JOIN indicator i ON oi.indicator_id = i.indicator_id
             WHERE i.type NOT IN (\'message_id\',\'subject\',\'spf_result\',\'dkim_result\',\'dmarc_result\',\'x_mailer\',\'return_path\')
             LIMIT 1'
        );

        $this->assertNotFalse($row, 'IocContextTestFixtures should create non-header IOCs. Run make fixtures first.');

        $msgId = $row['msg_id'];
        $obsIocData = [
            [
                'obs_id' => $row['obs_id'],
                'indicator_id' => $row['indicator_id'],
                'ioc_type' => $row['ioc_type'],
            ],
        ];

        $this->service->computeAndPersistForMessage($msgId, $obsIocData);

        // Verify row was created
        $contextRow = $this->connection->fetchAssociative(
            'SELECT * FROM ioc_context WHERE obs_id = :obsId',
            ['obsId' => $row['obs_id']]
        );

        $this->assertNotFalse($contextRow, 'ioc_context row should exist after compute');
        $this->assertSame('structural', $contextRow['enrichment_status']);
        $this->assertNotNull($contextRow['scam_type_code']);
        $this->assertNotNull($contextRow['computed_at']);
    }

    public function testDuplicateCallDoesNotFail(): void
    {
        $row = $this->connection->fetchAssociative(
            'SELECT oi.obs_id, oi.indicator_id, oi.msg_id,
                    i.type AS ioc_type
             FROM observed_ioc oi
             JOIN indicator i ON oi.indicator_id = i.indicator_id
             WHERE i.type NOT IN (\'message_id\',\'subject\',\'spf_result\',\'dkim_result\',\'dmarc_result\',\'x_mailer\',\'return_path\')
             LIMIT 1'
        );

        $this->assertNotFalse($row, 'IocContextTestFixtures should create non-header IOCs.');

        $msgId = $row['msg_id'];
        $obsIocData = [
            [
                'obs_id' => $row['obs_id'],
                'indicator_id' => $row['indicator_id'],
                'ioc_type' => $row['ioc_type'],
            ],
        ];

        // Call twice -- should not throw
        $this->service->computeAndPersistForMessage($msgId, $obsIocData);
        $this->service->computeAndPersistForMessage($msgId, $obsIocData);

        // Verify still only 1 row (UNIQUE constraint on obs_id)
        $count = $this->connection->fetchOne(
            'SELECT COUNT(*) FROM ioc_context WHERE obs_id = :obsId',
            ['obsId' => $row['obs_id']]
        );

        $this->assertSame(1, (int) $count);
    }

    public function testRevelationTurnIsPositive(): void
    {
        $row = $this->connection->fetchAssociative(
            'SELECT oi.obs_id, oi.indicator_id, oi.msg_id,
                    i.type AS ioc_type
             FROM observed_ioc oi
             JOIN indicator i ON oi.indicator_id = i.indicator_id
             WHERE i.type NOT IN (\'message_id\',\'subject\',\'spf_result\',\'dkim_result\',\'dmarc_result\',\'x_mailer\',\'return_path\')
             LIMIT 1'
        );

        $this->assertNotFalse($row, 'IocContextTestFixtures should create non-header IOCs.');

        $this->service->computeAndPersistForMessage($row['msg_id'], [
            ['obs_id' => $row['obs_id'], 'indicator_id' => $row['indicator_id'], 'ioc_type' => $row['ioc_type']],
        ]);

        $contextRow = $this->connection->fetchAssociative(
            'SELECT revelation_turn, total_turns, revelation_turn_ratio FROM ioc_context WHERE obs_id = :obsId',
            ['obsId' => $row['obs_id']]
        );

        $this->assertNotFalse($contextRow);
        $turn = (int) $contextRow['revelation_turn'];
        $total = (int) $contextRow['total_turns'];
        $ratio = (float) $contextRow['revelation_turn_ratio'];

        // Turn index is >= 1 for inbound messages (COUNT + 1), or 1 for first message
        $this->assertGreaterThanOrEqual(1, $turn, 'revelation_turn must be >= 1');
        $this->assertGreaterThanOrEqual(0, $total, 'total_turns must be >= 0');
        $this->assertGreaterThanOrEqual(0.0, $ratio, 'ratio must be >= 0.0');
        if ($total > 0) {
            $this->assertLessThanOrEqual(1.0, $ratio, 'ratio must be <= 1.0 when total > 0');
        }
    }
}
