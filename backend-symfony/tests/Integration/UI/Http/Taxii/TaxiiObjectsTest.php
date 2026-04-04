<?php

declare(strict_types=1);

namespace Tests\Integration\UI\Http\Taxii;

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

    public function testLimitParameterWorks(): void
    {
        $this->client->request('GET', '/api/v1/taxii2/api/collections/a1b2c3d4-0001-4000-8000-000000000001/objects/', [
            'limit' => '1',
        ], [], [
            'HTTP_AUTHORIZATION' => 'Bearer fake-jwt',
        ]);

        $this->assertResponseIsSuccessful();

        $data = json_decode((string) $this->client->getResponse()->getContent(), true);
        $this->assertLessThanOrEqual(1, \count($data['objects']));
    }
}
