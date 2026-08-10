<?php

declare(strict_types=1);

namespace Tests\Functional\Taxii;

use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class TaxiiObjectsTest extends WebTestCase
{
    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = static::createClient();
    }

    public function testReturnsObjectsForIocCollection(): void
    {
        $container = static::getContainer();
        /** @var \Doctrine\DBAL\Connection $conn */
        $conn = $container->get('doctrine.dbal.default_connection');

        $indicatorId = 'aaaa1111-bbbb-4000-8000-ccccddddeeee';
        $existing = $conn->fetchAssociative('SELECT 1 FROM indicator WHERE indicator_id = ?', [$indicatorId]);

        if (!$existing) {
            $now = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
            $conn->executeStatement(
                'INSERT INTO indicator (indicator_id, type, value, value_norm, first_seen, last_seen, occurrences, enrichment, score, tlp, created_at, updated_at)
                 VALUES (?, ?, ?, ?, ?, ?, 1, ?, ?, ?, ?, ?)',
                [$indicatorId, 'domain', 'taxii-test.example.com', 'taxii-test[.]example[.]com', $now, $now, '{}', '{"vt":0,"urlscan":0,"agg":0}', 'AMBER', $now, $now]
            );
        }

        $this->client->request('GET', '/api/v1/taxii2/api/collections/a1b2c3d4-0001-4000-8000-000000000001/objects/', [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer fake-jwt',
        ]);

        $this->assertResponseIsSuccessful();

        $data = json_decode((string) $this->client->getResponse()->getContent(), true);
        $this->assertIsArray($data);
        $this->assertArrayHasKey('more', $data);
        $this->assertArrayHasKey('objects', $data);
        $this->assertIsBool($data['more']);
        $this->assertIsArray($data['objects']);
        $this->assertNotEmpty($data['objects']);

        // Verify STIX indicator structure
        $firstObject = $data['objects'][0];
        $this->assertSame('indicator', $firstObject['type']);
        $this->assertSame('2.1', $firstObject['spec_version']);
        $this->assertArrayHasKey('pattern', $firstObject);
        $this->assertSame('stix', $firstObject['pattern_type']);
    }

    public function testReturns404ForUnknownCollection(): void
    {
        $this->client->request('GET', '/api/v1/taxii2/api/collections/00000000-0000-0000-0000-000000000000/objects/', [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer fake-jwt',
        ]);

        $this->assertResponseStatusCodeSame(404);
    }

    public function testAddedAfterFiltersResults(): void
    {
        $container = static::getContainer();
        /** @var \Doctrine\DBAL\Connection $conn */
        $conn = $container->get('doctrine.dbal.default_connection');

        // Insert an indicator with a known old date
        $oldId = 'aaaa2222-bbbb-4000-8000-ccccddddeeee';
        $existing = $conn->fetchAssociative('SELECT 1 FROM indicator WHERE indicator_id = ?', [$oldId]);

        if (!$existing) {
            $oldDate = '2020-01-01 00:00:00';
            $conn->executeStatement(
                'INSERT INTO indicator (indicator_id, type, value, value_norm, first_seen, last_seen, occurrences, enrichment, score, tlp, created_at, updated_at)
                 VALUES (?, ?, ?, ?, ?, ?, 1, ?, ?, ?, ?, ?)',
                [$oldId, 'domain', 'old.example.com', 'old[.]example[.]com', $oldDate, $oldDate, '{}', '{"vt":0,"urlscan":0,"agg":0}', 'AMBER', $oldDate, $oldDate]
            );
        }

        // Request with added_after in the future: should return no results from the old indicator
        $futureDate = (new \DateTimeImmutable('+1 year'))->format(\DateTimeInterface::ATOM);
        $this->client->request('GET', '/api/v1/taxii2/api/collections/a1b2c3d4-0001-4000-8000-000000000001/objects/', [
            'added_after' => $futureDate,
        ], [], [
            'HTTP_AUTHORIZATION' => 'Bearer fake-jwt',
        ]);

        $this->assertResponseIsSuccessful();

        $data = json_decode((string) $this->client->getResponse()->getContent(), true);
        $this->assertEmpty($data['objects']);
    }

    /**
     * The shared TAXII feed must not export header-noise / non-actionable IOC
     * types (message_id `@scambuster.local`, subject, auth-results) — only
     * actionable intel. Aligns the feed with the actionability policy.
     */
    public function testFeedExcludesNonActionableHeaderTypes(): void
    {
        $container = static::getContainer();
        /** @var \Doctrine\DBAL\Connection $conn */
        $conn = $container->get('doctrine.dbal.default_connection');
        $now = (new \DateTimeImmutable())->format('Y-m-d H:i:s');

        $rows = [
            ['dddd0001-0000-4000-8000-000000000001', 'iban', 'GB29NWBK60161331926819', 'GB29NWBK60161331926819'],
            ['dddd0002-0000-4000-8000-000000000002', 'message_id', '<probe-xyz@scambuster.local>', 'probe-xyz@scambuster.local'],
            ['dddd0003-0000-4000-8000-000000000003', 'subject', 'NONACTIONABLE-SUBJECT-PROBE', 'NONACTIONABLE-SUBJECT-PROBE'],
        ];

        foreach ($rows as [$id, $type, $value, $norm]) {
            if (!$conn->fetchAssociative('SELECT 1 FROM indicator WHERE indicator_id = ?', [$id])) {
                $conn->executeStatement(
                    'INSERT INTO indicator (indicator_id, type, value, value_norm, first_seen, last_seen, occurrences, enrichment, score, tlp, created_at, updated_at)
                     VALUES (?, ?, ?, ?, ?, ?, 1, ?, ?, ?, ?, ?)',
                    [$id, $type, $value, $norm, $now, $now, '{}', '{"vt":0,"urlscan":0,"agg":0}', 'AMBER', $now, $now]
                );
            }
        }

        // Financial IOCs are export-held until an analyst confirms them (possible
        // mule/victim accounts — see IocExportPolicy); confirm the IBAN so this
        // test keeps proving that actionable financial intel DOES ship.
        $conn->executeStatement(
            "INSERT INTO ioc_analyst_feedback (indicator_id, verdict, note, analyst_id, created_at)
             VALUES ('dddd0001-0000-4000-8000-000000000001', 'confirmed', NULL, 'taxii-test', NOW())
             ON CONFLICT (indicator_id) DO UPDATE SET verdict = 'confirmed'",
        );

        $this->client->request('GET', '/api/v1/taxii2/api/collections/a1b2c3d4-0001-4000-8000-000000000001/objects/', [
            'limit' => '500',
        ], [], [
            'HTTP_AUTHORIZATION' => 'Bearer fake-jwt',
        ]);

        $this->assertResponseIsSuccessful();
        $blob = (string) $this->client->getResponse()->getContent();

        $this->assertStringContainsString('GB29NWBK60161331926819', $blob, 'Confirmed IBAN must be in the feed');
        $this->assertStringNotContainsString('scambuster.local', $blob, 'Honeypot message_id must never reach the shared feed');
        $this->assertStringNotContainsString('NONACTIONABLE-SUBJECT-PROBE', $blob, 'subject header noise must not be exported');
    }

    public function testLimitParameterWorks(): void
    {
        $this->client->request('GET', '/api/v1/taxii2/api/collections/a1b2c3d4-0001-4000-8000-000000000001/objects/', [
            'limit' => '1',
        ], [], [
            'HTTP_AUTHORIZATION' => 'Bearer fake-jwt',
        ]);

        $this->assertResponseIsSuccessful();

        $data = json_decode((string) $this->client->getResponse()->getContent(), true);
        // limit applies to indicators; enrichment adds threat-actor + attack-pattern + relationships
        $indicators = array_filter($data['objects'], fn (array $o) => ($o['type'] ?? '') === 'indicator');
        $this->assertLessThanOrEqual(1, \count($indicators));
    }
}
