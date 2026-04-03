<?php

declare(strict_types=1);

namespace Tests\Integration\UI\Http\Communication;

use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class IocGraphControllerTest extends WebTestCase
{
    private KernelBrowser $client;

    private const INDICATOR_ID = '11111111-1111-1111-1111-111111111111';
    private const NONEXISTENT_ID = '99999999-9999-9999-9999-999999999999';

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->ensureIndicatorExists();
    }

    private function ensureIndicatorExists(): void
    {
        $container = static::getContainer();
        /** @var \Doctrine\DBAL\Connection $conn */
        $conn = $container->get('doctrine.dbal.default_connection');

        $existing = $conn->fetchAssociative(
            'SELECT indicator_id FROM indicator WHERE indicator_id = :id',
            ['id' => self::INDICATOR_ID]
        );

        if (!$existing) {
            $now = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
            $conn->executeStatement(
                'INSERT INTO indicator (indicator_id, type, value, value_norm, first_seen, last_seen, occurrences, enrichment, score, tlp, created_at, updated_at)
                 VALUES (:id, :type, :value, :valueNorm, :firstSeen, :lastSeen, 1, :enrichment, :score, :tlp, :createdAt, :updatedAt)',
                [
                    'id' => self::INDICATOR_ID,
                    'type' => 'domain',
                    'value' => 'evil-phishing.com',
                    'valueNorm' => 'evil-phishing[.]com',
                    'firstSeen' => $now,
                    'lastSeen' => $now,
                    'enrichment' => '{}',
                    'score' => '{"vt":0,"urlscan":0,"agg":0,"explain":"No threats detected"}',
                    'tlp' => 'AMBER',
                    'createdAt' => $now,
                    'updatedAt' => $now,
                ]
            );
        }
    }

    public function testCoOccurrenceReturnsGraphStructure(): void
    {
        $this->client->request('GET', '/api/v1/iocs/co-occurrence', [
            'indicator_id' => self::INDICATOR_ID,
        ], [], [
            'HTTP_AUTHORIZATION' => 'Bearer fake-jwt',
        ]);

        $this->assertResponseIsSuccessful();

        $data = json_decode((string) $this->client->getResponse()->getContent(), true);
        $this->assertIsArray($data);
        $this->assertArrayHasKey('nodes', $data);
        $this->assertArrayHasKey('edges', $data);
        $this->assertIsArray($data['nodes']);
        $this->assertIsArray($data['edges']);

        // Center node should always be present
        if (\count($data['nodes']) > 0) {
            $centerNode = $data['nodes'][0];
            $this->assertArrayHasKey('id', $centerNode);
            $this->assertArrayHasKey('type', $centerNode);
            $this->assertArrayHasKey('value', $centerNode);
            $this->assertArrayHasKey('center', $centerNode);
            $this->assertTrue($centerNode['center']);
        }
    }

    public function testCoOccurrenceReturns400WithoutIndicatorId(): void
    {
        $this->client->request('GET', '/api/v1/iocs/co-occurrence', [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer fake-jwt',
        ]);

        $this->assertResponseStatusCodeSame(400);
    }

    public function testCoOccurrenceReturnsEmptyForNonexistentIndicator(): void
    {
        $this->client->request('GET', '/api/v1/iocs/co-occurrence', [
            'indicator_id' => self::NONEXISTENT_ID,
        ], [], [
            'HTTP_AUTHORIZATION' => 'Bearer fake-jwt',
        ]);

        $this->assertResponseIsSuccessful();

        $data = json_decode((string) $this->client->getResponse()->getContent(), true);
        $this->assertEmpty($data['nodes']);
        $this->assertEmpty($data['edges']);
    }

    public function testEdgesHaveWeightAndConversations(): void
    {
        $this->client->request('GET', '/api/v1/iocs/co-occurrence', [
            'indicator_id' => self::INDICATOR_ID,
        ], [], [
            'HTTP_AUTHORIZATION' => 'Bearer fake-jwt',
        ]);

        $this->assertResponseIsSuccessful();

        $data = json_decode((string) $this->client->getResponse()->getContent(), true);

        foreach ($data['edges'] as $edge) {
            $this->assertArrayHasKey('source', $edge);
            $this->assertArrayHasKey('target', $edge);
            $this->assertArrayHasKey('weight', $edge);
            $this->assertArrayHasKey('conversations', $edge);
            $this->assertSame(self::INDICATOR_ID, $edge['source']);
        }
    }
}
