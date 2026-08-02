<?php

declare(strict_types=1);

namespace App\Tests\Integration\Communication;

use App\Application\Communication\IocHandler;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Bug fix — Related IOCs header filter alignment.
 *
 * The IOC detail endpoint returns `related_iocs` (used by the "Related IOCs (N)"
 * badge in the frontend IocDetail page) computed from a SQL query that
 * historically did NOT exclude header IOC types (`message_id`, `subject`,
 * `dkim_result`, `dmarc_result`, `spf_result`, `x_mailer`, `return_path`).
 *
 * The co-occurrence graph (separate query in `getCoOccurrenceGraph`) DOES
 * exclude these. Result: badge says "8" but graph displays only 1 node, which
 * confuses analysts.
 *
 * Both queries must apply the same filter so the badge count matches the graph.
 */
final class IocDetailRelatedIocsHeaderFilterTest extends KernelTestCase
{
    private Connection $conn;
    private IocHandler $handler;

    /** @var list<string> */
    private array $createdMsgIds = [];

    /** @var list<string> */
    private array $createdIndicatorIds = [];

    private string $testRunId;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->conn = self::getContainer()->get(Connection::class);
        $this->handler = self::getContainer()->get(IocHandler::class);
        $this->testRunId = bin2hex(random_bytes(4));
    }

    protected function tearDown(): void
    {
        if (!empty($this->createdIndicatorIds)) {
            $this->conn->executeStatement(
                'DELETE FROM observed_ioc WHERE indicator_id IN (?)',
                [$this->createdIndicatorIds],
                [\Doctrine\DBAL\ArrayParameterType::STRING]
            );
            $this->conn->executeStatement(
                'DELETE FROM indicator WHERE indicator_id IN (?)',
                [$this->createdIndicatorIds],
                [\Doctrine\DBAL\ArrayParameterType::STRING]
            );
        }

        if (!empty($this->createdMsgIds)) {
            $this->conn->executeStatement(
                'DELETE FROM message WHERE msg_id IN (?)',
                [$this->createdMsgIds],
                [\Doctrine\DBAL\ArrayParameterType::STRING]
            );
        }

        parent::tearDown();
    }

    public function testRelatedIocsExcludesHeaderTypes(): void
    {
        // Setup: 1 incoming message in an existing fixture conversation, with
        // 1 "real" IOC (the central) + 1 real co-occurring IOC (a phone) +
        // several header IOCs (message_id, subject, spf_result, dkim_result, dmarc_result).
        $convId = $this->conn->fetchOne('SELECT conv_id FROM conversation LIMIT 1');
        $channelId = $this->conn->fetchOne('SELECT channel_id FROM lkp_channel LIMIT 1');
        $inDir = $this->conn->fetchOne("SELECT dir_id FROM lkp_direction WHERE code = 'in'");

        $msgId = uuid_create(UUID_TYPE_RANDOM);
        $this->createdMsgIds[] = $msgId;
        $this->conn->executeStatement(
            "INSERT INTO message (msg_id, conv_id, channel_id, direction, lang_detect, subject, body_text,
                headers, composite_hash, ts_msg, ts_ingest, external_message_id)
             VALUES (:msgId, :convId, :channelId, :direction, 'en', 'header-filter test', 'body',
                '{}'::json, :hash, NOW(), NOW(), :extId)",
            [
                'msgId' => $msgId, 'convId' => $convId, 'channelId' => $channelId, 'direction' => $inDir,
                'hash' => bin2hex(random_bytes(32)),
                'extId' => "header-filter-test-{$this->testRunId}",
            ]
        );

        // Create indicators: 1 central + 1 real co-occurring + 5 header types
        $central = $this->insertIndicatorAndObservation('phone', "+15555550{$this->testRunId}", $msgId);
        $this->insertIndicatorAndObservation('wallet_btc', "bc1qtest{$this->testRunId}", $msgId);

        // Header noise that MUST be filtered out
        $this->insertIndicatorAndObservation('message_id', "<filter-test-{$this->testRunId}@example.com>", $msgId);
        $this->insertIndicatorAndObservation('subject', "filter-test subject {$this->testRunId}", $msgId);
        $this->insertIndicatorAndObservation('spf_result', "PASS-{$this->testRunId}", $msgId);
        $this->insertIndicatorAndObservation('dkim_result', "PASS-{$this->testRunId}", $msgId);
        $this->insertIndicatorAndObservation('dmarc_result', "PASS-{$this->testRunId}", $msgId);

        // Act
        $detail = $this->handler->getIocDetail($central);

        // Assert
        $this->assertIsArray($detail);
        $this->assertArrayHasKey('related_iocs', $detail);

        $relatedTypes = array_map(fn (array $r) => $r['type'], $detail['related_iocs']);

        // Header types must be ABSENT
        $this->assertNotContains('message_id', $relatedTypes, 'Badge must not count header type message_id');
        $this->assertNotContains('subject', $relatedTypes, 'Badge must not count header type subject');
        $this->assertNotContains('spf_result', $relatedTypes, 'Badge must not count header type spf_result');
        $this->assertNotContains('dkim_result', $relatedTypes, 'Badge must not count header type dkim_result');
        $this->assertNotContains('dmarc_result', $relatedTypes, 'Badge must not count header type dmarc_result');

        // Real IOCs must be PRESENT (the wallet)
        $this->assertContains('wallet_btc', $relatedTypes, 'Badge must still count real IOC types');
    }

    private function insertIndicatorAndObservation(string $type, string $value, string $msgId): string
    {
        $id = uuid_create(UUID_TYPE_RANDOM);
        $this->createdIndicatorIds[] = $id;

        $this->conn->executeStatement(
            "INSERT INTO indicator (indicator_id, type, value, value_norm, first_seen, last_seen,
                occurrences, tlp, created_at, updated_at)
             VALUES (:id, :type, :value, :value, NOW(), NOW(), 1, 'AMBER', NOW(), NOW())",
            ['id' => $id, 'type' => $type, 'value' => $value]
        );

        $this->conn->executeStatement(
            "INSERT INTO observed_ioc (obs_id, msg_id, indicator_id, context_observation, ts_observed)
             VALUES (:obsId, :msgId, :indId, '{}'::json, NOW())",
            [
                'obsId' => uuid_create(UUID_TYPE_RANDOM),
                'msgId' => $msgId,
                'indId' => $id,
            ]
        );

        return $id;
    }
}
