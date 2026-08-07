<?php

declare(strict_types=1);

namespace App\Tests\Integration\Communication;

use App\Application\Communication\IocHandler;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Confidence is corroborated by DISTINCT sources, not raw repetition.
 *
 * Re-seeing the same value inside one conversation (the poisoning vector — an
 * adversary re-posting a fabricated IBAN across a baited thread) must NOT raise
 * confidence; only a second, independent conversation does.
 */
final class IocConfidenceCorroborationTest extends KernelTestCase
{
    private Connection $conn;
    private IocHandler $handler;

    /** @var list<string> */
    private array $msgIds = [];
    private string $indicatorId = '';
    private string $runId = '';

    protected function setUp(): void
    {
        self::bootKernel();
        $this->conn = self::getContainer()->get(Connection::class);
        $this->handler = self::getContainer()->get(IocHandler::class);
        $this->runId = bin2hex(random_bytes(4));
    }

    protected function tearDown(): void
    {
        if ($this->indicatorId !== '') {
            $this->conn->executeStatement('DELETE FROM observed_ioc WHERE indicator_id = ?', [$this->indicatorId]);
            $this->conn->executeStatement('DELETE FROM indicator WHERE indicator_id = ?', [$this->indicatorId]);
        }

        if ($this->msgIds !== []) {
            $this->conn->executeStatement('DELETE FROM message WHERE msg_id IN (?)', [$this->msgIds], [ArrayParameterType::STRING]);
        }

        parent::tearDown();
    }

    public function testConfidenceCorroboratedByDistinctSourcesNotRepetition(): void
    {
        /** @var list<string> $convIds */
        $convIds = $this->conn->fetchFirstColumn('SELECT conv_id FROM conversation LIMIT 2');

        if (\count($convIds) < 2) {
            self::markTestSkipped('needs at least two seeded conversations');
        }

        [$convA, $convB] = $convIds;
        $channelId = $this->conn->fetchOne('SELECT channel_id FROM lkp_channel LIMIT 1');
        $inDir = $this->conn->fetchOne("SELECT dir_id FROM lkp_direction WHERE code = 'in'");

        // One IBAN indicator.
        $this->indicatorId = uuid_create(UUID_TYPE_RANDOM);
        $iban = 'GB29NWBK60161331926819';
        $this->conn->executeStatement(
            "INSERT INTO indicator (indicator_id, type, value, value_norm, first_seen, last_seen, occurrences, tlp, created_at, updated_at)
             VALUES (:id, 'iban', :v, :v, NOW(), NOW(), 1, 'AMBER', NOW(), NOW())",
            ['id' => $this->indicatorId, 'v' => $iban]
        );

        // Two observations of the SAME value inside ONE conversation (repetition).
        $this->observe($convA, $channelId, $inDir);
        $this->observe($convA, $channelId, $inDir);

        $detail = $this->handler->getIocDetail($this->indicatorId);
        self::assertIsArray($detail);
        self::assertEqualsWithDelta(0.80, $detail['confidence'], 0.0001, 'repetition within one conversation must not boost confidence');

        // A second, independent conversation corroborates → +0.05.
        $this->observe($convB, $channelId, $inDir);

        $detail = $this->handler->getIocDetail($this->indicatorId);
        self::assertEqualsWithDelta(0.85, $detail['confidence'], 0.0001, 'a second independent source corroborates (+0.05)');
    }

    /**
     * Executes the exact distinct-source SQL of BOTH call sites against seeded
     * data — the upsert-path query (UNION the current conversation, run before the
     * observed_ioc row is persisted) was previously only reasoned about.
     */
    public function testDistinctSourceSqlMatchesBothCallSites(): void
    {
        /** @var list<string> $convIds */
        $convIds = $this->conn->fetchFirstColumn('SELECT conv_id FROM conversation LIMIT 2');

        if (\count($convIds) < 2) {
            self::markTestSkipped('needs at least two seeded conversations');
        }

        [$convA, $convB] = $convIds;
        $channelId = $this->conn->fetchOne('SELECT channel_id FROM lkp_channel LIMIT 1');
        $inDir = $this->conn->fetchOne("SELECT dir_id FROM lkp_direction WHERE code = 'in'");

        $this->indicatorId = uuid_create(UUID_TYPE_RANDOM);
        $this->conn->executeStatement(
            "INSERT INTO indicator (indicator_id, type, value, value_norm, first_seen, last_seen, occurrences, tlp, created_at, updated_at)
             VALUES (:id, 'iban', 'GB29NWBK60161331926819', 'GB29NWBK60161331926819', NOW(), NOW(), 1, 'AMBER', NOW(), NOW())",
            ['id' => $this->indicatorId]
        );
        $this->observe($convA, $channelId, $inDir); // one prior observation, in convA

        // Upsert-path SQL (verbatim from IocUpsertService): current conversation
        // is UNIONed in because its observed_ioc row is not persisted yet.
        $upsertSql = 'SELECT COUNT(DISTINCT conv_id) FROM (
                SELECT m.conv_id::text AS conv_id
                FROM observed_ioc oi JOIN message m ON oi.msg_id = m.msg_id
                WHERE oi.indicator_id = :id
                UNION
                SELECT :conv
            ) s';

        $sameConv = (int) $this->conn->fetchOne($upsertSql, ['id' => $this->indicatorId, 'conv' => $convA]);
        $newConv = (int) $this->conn->fetchOne($upsertSql, ['id' => $this->indicatorId, 'conv' => $convB]);
        self::assertSame(1, $sameConv, 'a second observation in the SAME conversation is not a new source');
        self::assertSame(2, $newConv, 'an observation in a DIFFERENT conversation is a new source');

        // Read-path SQL (verbatim from IocQueryService).
        $read = (int) $this->conn->fetchOne(
            'SELECT COUNT(DISTINCT m.conv_id) FROM observed_ioc oi JOIN message m ON oi.msg_id = m.msg_id WHERE oi.indicator_id = :id',
            ['id' => $this->indicatorId]
        );
        self::assertSame(1, $read, 'only convA has observed the value so far');
    }

    private function observe(string $convId, int|string $channelId, int|string $inDir): void
    {
        $msgId = uuid_create(UUID_TYPE_RANDOM);
        $this->msgIds[] = $msgId;
        $this->conn->executeStatement(
            "INSERT INTO message (msg_id, conv_id, channel_id, direction, lang_detect, subject, body_text,
                headers, composite_hash, ts_msg, ts_ingest, external_message_id)
             VALUES (:msgId, :convId, :channelId, :direction, 'en', 'corroboration test', 'body',
                '{}'::json, :hash, NOW(), NOW(), :extId)",
            [
                'msgId' => $msgId, 'convId' => $convId, 'channelId' => $channelId, 'direction' => $inDir,
                'hash' => bin2hex(random_bytes(32)), 'extId' => 'corrob-' . $this->runId . '-' . \count($this->msgIds),
            ]
        );
        $this->conn->executeStatement(
            "INSERT INTO observed_ioc (obs_id, msg_id, indicator_id, context_observation, ts_observed)
             VALUES (:obsId, :msgId, :indId, '{}'::json, NOW())",
            ['obsId' => uuid_create(UUID_TYPE_RANDOM), 'msgId' => $msgId, 'indId' => $this->indicatorId]
        );
    }
}
