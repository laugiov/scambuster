<?php

declare(strict_types=1);

namespace Tests\Functional\Communication;

use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class ExportIocsFeedControllerTest extends WebTestCase
{
    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = static::createClient();
    }

    private function seedIndicator(): string
    {
        $container = static::getContainer();
        /** @var \Doctrine\DBAL\Connection $conn */
        $conn = $container->get('doctrine.dbal.default_connection');

        $indicatorId = '33333333-3333-4333-8333-3333333333fe';
        $existing = $conn->fetchAssociative('SELECT 1 FROM indicator WHERE indicator_id = ?', [$indicatorId]);

        if (!$existing) {
            $now = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
            $conn->executeStatement(
                'INSERT INTO indicator (indicator_id, type, value, value_norm, first_seen, last_seen, occurrences, enrichment, score, tlp, created_at, updated_at)
                 VALUES (?, ?, ?, ?, ?, ?, 4, ?, ?, ?, ?, ?)',
                [$indicatorId, 'domain', 'feed-export.example', 'feed-export.example', $now, $now, '{}', '{"vt":0,"urlscan":0,"agg":55}', 'AMBER', $now, $now]
            );
        }

        return $indicatorId;
    }

    public function testFeedReturns400WithoutIndicatorIds(): void
    {
        $this->client->request('POST', '/api/v1/iocs/export/feed', [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer fake-jwt',
            'CONTENT_TYPE' => 'application/json',
        ], '{}');

        $this->assertResponseStatusCodeSame(400);
    }

    public function testFeedReturns400ForUnsupportedFormat(): void
    {
        $indicatorId = $this->seedIndicator();

        $this->client->request('POST', '/api/v1/iocs/export/feed', [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer fake-jwt',
            'CONTENT_TYPE' => 'application/json',
        ], json_encode(['indicator_ids' => [$indicatorId], 'format' => 'xml']));

        $this->assertResponseStatusCodeSame(400);
    }

    public function testFeedExportsCsvWithHeaderAndRow(): void
    {
        $indicatorId = $this->seedIndicator();

        $this->client->request('POST', '/api/v1/iocs/export/feed', [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer fake-jwt',
            'CONTENT_TYPE' => 'application/json',
        ], json_encode(['indicator_ids' => [$indicatorId], 'format' => 'csv']));

        $this->assertResponseIsSuccessful();
        $response = $this->client->getResponse();
        $this->assertStringContainsString('text/csv', (string) $response->headers->get('Content-Type'));
        $this->assertStringContainsString('attachment; filename="scambuster-iocs.csv"', (string) $response->headers->get('Content-Disposition'));

        $body = (string) $response->getContent();
        $this->assertStringContainsString('indicator_id,type,value', $body);
        $this->assertStringContainsString('feed-export.example', $body);
        $this->assertStringContainsString('55', $body, 'The aggregate score must surface in the CSV.');
    }

    public function testFeedDefaultsToCsvWhenFormatOmitted(): void
    {
        $indicatorId = $this->seedIndicator();

        $this->client->request('POST', '/api/v1/iocs/export/feed', [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer fake-jwt',
            'CONTENT_TYPE' => 'application/json',
        ], json_encode(['indicator_ids' => [$indicatorId]]));

        $this->assertResponseIsSuccessful();
        $this->assertStringContainsString('text/csv', (string) $this->client->getResponse()->headers->get('Content-Type'));
    }

    public function testFeedExportsNdjsonWithValidJsonLines(): void
    {
        $indicatorId = $this->seedIndicator();

        $this->client->request('POST', '/api/v1/iocs/export/feed', [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer fake-jwt',
            'CONTENT_TYPE' => 'application/json',
        ], json_encode(['indicator_ids' => [$indicatorId], 'format' => 'ndjson']));

        $this->assertResponseIsSuccessful();
        $response = $this->client->getResponse();
        $this->assertStringContainsString('application/x-ndjson', (string) $response->headers->get('Content-Type'));
        $this->assertStringContainsString('attachment; filename="scambuster-iocs.ndjson"', (string) $response->headers->get('Content-Disposition'));

        $lines = array_values(array_filter(explode("\n", (string) $response->getContent()), static fn ($l): bool => $l !== ''));
        $this->assertNotEmpty($lines);

        $found = false;

        foreach ($lines as $line) {
            $decoded = json_decode($line, true);
            $this->assertIsArray($decoded, 'Every NDJSON line must be a valid JSON object.');
            $this->assertArrayHasKey('indicator_id', $decoded);

            if (($decoded['indicator_id'] ?? null) === $indicatorId) {
                $found = true;
                $this->assertSame('feed-export.example', $decoded['value']);
                $this->assertSame(55, $decoded['score']);
            }
        }

        $this->assertTrue($found, 'The seeded indicator must appear in the NDJSON feed.');
    }
}
