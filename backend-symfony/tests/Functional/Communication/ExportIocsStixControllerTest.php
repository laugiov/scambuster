<?php

declare(strict_types=1);

namespace Tests\Functional\Communication;

use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class ExportIocsStixControllerTest extends WebTestCase
{
    use \App\Tests\Support\CorroboratesIoc;

    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = static::createClient();
    }

    public function testExportReturns400WithoutIndicatorIds(): void
    {
        $this->client->request('POST', '/api/v1/iocs/export/stix', [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer fake-jwt',
            'CONTENT_TYPE' => 'application/json',
        ], '{}');

        $this->assertResponseStatusCodeSame(400);
    }

    public function testExportReturns400WithEmptyArray(): void
    {
        $this->client->request('POST', '/api/v1/iocs/export/stix', [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer fake-jwt',
            'CONTENT_TYPE' => 'application/json',
        ], '{"indicator_ids": []}');

        $this->assertResponseStatusCodeSame(400);
    }

    public function testExportReturnsValidBundleForKnownIndicator(): void
    {
        // Ensure indicator exists
        $container = static::getContainer();
        /** @var \Doctrine\DBAL\Connection $conn */
        $conn = $container->get('doctrine.dbal.default_connection');

        $indicatorId = '11111111-1111-1111-1111-111111111111';
        $existing = $conn->fetchAssociative('SELECT 1 FROM indicator WHERE indicator_id = ?', [$indicatorId]);

        if (!$existing) {
            $now = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
            $conn->executeStatement(
                'INSERT INTO indicator (indicator_id, type, value, value_norm, first_seen, last_seen, occurrences, enrichment, score, tlp, created_at, updated_at)
                 VALUES (?, ?, ?, ?, ?, ?, 1, ?, ?, ?, ?, ?)',
                [$indicatorId, 'domain', 'evil.com', 'evil[.]com', $now, $now, '{}', '{"vt":0,"urlscan":0,"agg":0}', 'AMBER', $now, $now]
            );
        }

        $this->corroborateIndicator($conn, $indicatorId);

        $this->client->request('POST', '/api/v1/iocs/export/stix', [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer fake-jwt',
            'CONTENT_TYPE' => 'application/json',
        ], json_encode(['indicator_ids' => [$indicatorId]]));

        $this->assertResponseIsSuccessful();

        $data = json_decode((string) $this->client->getResponse()->getContent(), true);
        $this->assertSame('bundle', $data['type']);
        $this->assertArrayNotHasKey('spec_version', $data);

        $types = array_column($data['objects'], 'type');
        $this->assertContains('marking-definition', $types);
        $this->assertContains('identity', $types);
        $this->assertContains('indicator', $types);
        $this->assertContains('report', $types);
    }
}
