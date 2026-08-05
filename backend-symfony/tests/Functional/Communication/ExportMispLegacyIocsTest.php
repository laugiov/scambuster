<?php

declare(strict_types=1);

namespace App\Tests\Functional\Communication;

use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;

/**
 * IOC observations written outside the ingest path carry no `misp` export
 * metadata: the seeded demo dataset, and anything stored before
 * app:migrate-iocs-export-metadata was run.
 *
 * The controller used to skip those silently, so the endpoint answered 200 with
 * a well-formed MISP Event holding zero attributes — on a demo install that was
 * 100% of the IOCs, and nothing in the response said so. This test pins the
 * fallback: the mapping is derived on the fly and no IOC is ever dropped.
 */
final class ExportMispLegacyIocsTest extends WebTestCase
{
    // Own fixtures rather than the shared ones: other functional tests delete
    // seeded conversations, which made this test pass alone and 404 in the
    // full suite.
    private const CONV_ID = 'dddd2222-eeee-4000-8000-ffff00000001';
    private const MSG_ID = 'dddd2222-eeee-4000-8000-ffff00000002';
    private const INDICATOR_ID = 'dddd2222-eeee-4000-8000-ffff00001111';
    private const OBS_ID = 'dddd2222-eeee-4000-8000-ffff00002222';
    private const VALUE = 'legacy-misp-fallback.example.com';

    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        // dama/doctrine-test-bundle wraps the test in a transaction; a kernel
        // reboot would open a second connection that cannot see the seed row.
        $this->client->disableReboot();
        $this->seedIocWithoutExportMetadata();
    }

    protected function tearDown(): void
    {
        $conn = $this->connection();
        $conn->executeStatement('DELETE FROM observed_ioc WHERE obs_id = ?', [self::OBS_ID]);
        $conn->executeStatement('DELETE FROM indicator WHERE indicator_id = ?', [self::INDICATOR_ID]);
        $conn->executeStatement('DELETE FROM message WHERE msg_id = ?', [self::MSG_ID]);
        $conn->executeStatement('DELETE FROM conversation WHERE conv_id = ?', [self::CONV_ID]);

        parent::tearDown();
    }

    public function testIocWithoutStoredMispMetadataIsStillExported(): void
    {
        $attributes = $this->exportAttributes();

        $match = null;

        foreach ($attributes as $attribute) {
            if (($attribute['value'] ?? null) === self::VALUE) {
                $match = $attribute;

                break;
            }
        }

        self::assertNotNull($match, 'the IOC must appear in the Event, not be silently skipped');
        self::assertSame('domain', $match['type'], 'the MISP type must be derived from the IOC type');
        self::assertSame('Network activity', $match['category']);
        self::assertTrue($match['to_ids']);
    }

    public function testEventIsNeverEmptyWhenTheConversationHasIocs(): void
    {
        self::assertNotSame([], $this->exportAttributes());
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function exportAttributes(): array
    {
        $this->client->request('GET', '/api/v1/conversations/' . self::CONV_ID . '/export/misp', [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer fake-admin-jwt',
        ]);

        $response = $this->client->getResponse();
        self::assertSame(Response::HTTP_OK, $response->getStatusCode());

        /** @var array<string, mixed> $data */
        $data = json_decode((string) $response->getContent(), true, 512, \JSON_THROW_ON_ERROR);
        /** @var array<string, mixed> $event */
        $event = $data['Event'] ?? [];

        /** @var list<array<string, mixed>> $attributes */
        $attributes = \is_array($event['Attribute'] ?? null) ? $event['Attribute'] : [];

        return $attributes;
    }

    /**
     * Deliberately stores a context WITHOUT the `misp` key, reproducing what the
     * demo loader writes.
     */
    private function seedIocWithoutExportMetadata(): void
    {
        $conn = $this->connection();
        $now = (new \DateTimeImmutable())->format('Y-m-d H:i:s');

        $conn->executeStatement('DELETE FROM observed_ioc WHERE obs_id = ?', [self::OBS_ID]);
        $conn->executeStatement('DELETE FROM indicator WHERE indicator_id = ?', [self::INDICATOR_ID]);
        $conn->executeStatement('DELETE FROM message WHERE msg_id = ?', [self::MSG_ID]);
        $conn->executeStatement('DELETE FROM conversation WHERE conv_id = ?', [self::CONV_ID]);

        // Reference rows, not test data: reused rather than created.
        $channelId = $conn->fetchOne('SELECT channel_id FROM lkp_channel ORDER BY channel_id LIMIT 1');
        $scamTypeId = $conn->fetchOne('SELECT scam_type_id FROM lkp_scam_type ORDER BY scam_type_id LIMIT 1');
        $accountId = $conn->fetchOne('SELECT account_id FROM mail_account ORDER BY account_id LIMIT 1');
        $directionIn = $conn->fetchOne("SELECT dir_id FROM lkp_direction WHERE code = 'in'");

        $conn->executeStatement(
            'INSERT INTO conversation (conv_id, primary_channel_id, scam_type_id, account_id, status, score_risk,
                                       ts_first, ts_last, stix_id, created_at, updated_at, engagement_duration_sec,
                                       turns_count, delivery, tlp)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 0, 1, ?, ?)',
            [self::CONV_ID, $channelId, $scamTypeId, $accountId, 'open', 50, $now, $now,
                'stix-misp-fallback-' . self::CONV_ID, $now, $now, 'email', 'AMBER']
        );

        $conn->executeStatement(
            'INSERT INTO message (msg_id, conv_id, channel_id, direction, lang_detect, body_text, headers,
                                  ts_msg, ts_ingest, composite_hash)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [self::MSG_ID, self::CONV_ID, $channelId, $directionIn, 'en', 'legacy misp fallback body', '{}',
                $now, $now, 'hash-' . self::MSG_ID]
        );

        $conn->executeStatement(
            'INSERT INTO indicator (indicator_id, type, value, value_norm, first_seen, last_seen, occurrences, enrichment, score, tlp, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, 1, ?, ?, ?, ?, ?)',
            [self::INDICATOR_ID, 'domain', self::VALUE, self::VALUE, $now, $now, '{}', '{"vt":0,"urlscan":0,"agg":0}', 'AMBER', $now, $now]
        );

        $context = json_encode([
            'type' => 'domain',
            'value' => self::VALUE,
            'value_norm' => self::VALUE,
            'tlp' => 'AMBER',
            'source' => 'extraction',
        ], \JSON_THROW_ON_ERROR);

        $conn->executeStatement(
            'INSERT INTO observed_ioc (obs_id, msg_id, indicator_id, context_observation, ts_observed, confidence_score)
             VALUES (?, ?, ?, ?, ?, ?)',
            [self::OBS_ID, self::MSG_ID, self::INDICATOR_ID, $context, $now, 0.9]
        );
    }

    private function connection(): Connection
    {
        /** @var Connection $conn */
        $conn = static::getContainer()->get('doctrine.dbal.default_connection');

        return $conn;
    }
}
