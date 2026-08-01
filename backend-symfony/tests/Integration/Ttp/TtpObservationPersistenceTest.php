<?php

declare(strict_types=1);

namespace App\Tests\Integration\Ttp;

use App\Application\Ttp\TtpObservationUpsertService;
use Doctrine\DBAL\Connection;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

class TtpObservationPersistenceTest extends KernelTestCase
{
    private const FIXTURE_MSG_ID = '00000000-0000-0000-0000-000000000001';

    private Connection $connection;

    private TtpObservationUpsertService $upsertService;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->connection = self::getContainer()->get(Connection::class);
        // Constructed directly: the service has no consumer yet, so the compiled
        // container would have removed the private service definition.
        $this->upsertService = new TtpObservationUpsertService($this->connection, new NullLogger());
    }

    public function testUpsertIsIdempotentOnMessageAndTtp(): void
    {
        $convId = $this->connection->fetchOne(
            'SELECT conv_id FROM message WHERE msg_id = :msgId',
            ['msgId' => self::FIXTURE_MSG_ID]
        );
        $this->assertIsString($convId, 'Fixture message must exist in the test database');

        $ttpId = $this->connection->fetchOne("SELECT ttp_id FROM lkp_ttp WHERE code = 'SB-T001'");
        $this->assertNotFalse($ttpId, 'lkp_ttp must be seeded with SB-T001');
        $ttpId = (int) $ttpId;

        // Clean slate for the pair (DAMA rolls everything back after the test).
        $this->connection->executeStatement(
            'DELETE FROM ttp_observation WHERE msg_id = :msgId AND ttp_id = :ttpId',
            ['msgId' => self::FIXTURE_MSG_ID, 'ttpId' => $ttpId]
        );

        $row = [
            'msg_id' => self::FIXTURE_MSG_ID,
            'conv_id' => $convId,
            'ttp_id' => $ttpId,
            'confidence' => 0.875,
            'evidence' => 'You have been selected as the beneficiary of $10.5M',
            'evidence_start' => 0,
            'evidence_end' => 52,
            'status' => 'confirmed',
            'taxonomy_version' => '1.0',
            'extraction_model' => 'test-model',
            'prompt_version' => 'v1',
        ];

        $this->assertTrue($this->upsertService->upsert($row), 'First upsert must insert a row');

        $duplicate = $row;
        $duplicate['evidence'] = 'A different quote for the same (message, TTP) pair';
        $duplicate['confidence'] = 0.5;
        $this->assertFalse($this->upsertService->upsert($duplicate), 'Second upsert of the same (msg_id, ttp_id) must be a no-op');

        $count = (int) $this->connection->fetchOne(
            'SELECT COUNT(*) FROM ttp_observation WHERE msg_id = :msgId AND ttp_id = :ttpId',
            ['msgId' => self::FIXTURE_MSG_ID, 'ttpId' => $ttpId]
        );
        $this->assertSame(1, $count, 'Exactly one observation row must exist for the pair');

        // The first write wins: the conflicting insert must not have altered the row,
        // and the DB-side defaults (obs_id, created_at) must have been applied.
        $stored = $this->connection->fetchAssociative(
            'SELECT obs_id, evidence, confidence, created_at FROM ttp_observation WHERE msg_id = :msgId AND ttp_id = :ttpId',
            ['msgId' => self::FIXTURE_MSG_ID, 'ttpId' => $ttpId]
        );
        $this->assertNotFalse($stored);
        $this->assertSame($row['evidence'], $stored['evidence']);
        $this->assertNotEmpty($stored['obs_id']);
        $this->assertNotEmpty($stored['created_at']);
    }
}
