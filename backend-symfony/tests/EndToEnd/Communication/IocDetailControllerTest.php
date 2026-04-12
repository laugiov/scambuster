<?php

declare(strict_types=1);

namespace Tests\EndToEnd\Communication;

use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class IocDetailControllerTest extends WebTestCase
{
    private KernelBrowser $client;

    private const INDICATOR_ID = '11111111-1111-1111-1111-111111111111';
    private const NONEXISTENT_ID = '99999999-9999-9999-9999-999999999999';
    private const BASE_URL = '/api/v1/iocs';

    protected function setUp(): void
    {
        $this->client = static::createClient();
    }

    public function testDetailReturns200ForExistingIndicator(): void
    {
        // First, ensure the indicator exists in the indicator table
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
                 VALUES (:id, :type, :value, :valueNorm, :firstSeen, :lastSeen, 2, :enrichment, :score, :tlp, :createdAt, :updatedAt)',
                [
                    'id' => self::INDICATOR_ID,
                    'type' => 'domain',
                    'value' => 'evil-phishing.com',
                    'valueNorm' => 'evil-phishing[.]com',
                    'firstSeen' => $now,
                    'lastSeen' => $now,
                    'enrichment' => '{"virustotal":{"malicious":3,"suspicious":0}}',
                    'score' => '{"vt":70,"urlscan":0,"agg":70,"explain":"VT flagged malicious by 3 engines"}',
                    'tlp' => 'AMBER',
                    'createdAt' => $now,
                    'updatedAt' => $now,
                ]
            );
        }

        $this->client->request('GET', self::BASE_URL . '/' . self::INDICATOR_ID . '/detail', [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer fake-jwt',
        ]);

        $this->assertResponseIsSuccessful();
        $this->assertResponseHeaderSame('content-type', 'application/json');

        $data = json_decode((string) $this->client->getResponse()->getContent(), true);
        $this->assertIsArray($data);
        $this->assertSame(self::INDICATOR_ID, $data['indicator_id']);
        $this->assertSame('domain', $data['type']);
        $this->assertSame('evil-phishing.com', $data['value']);
        $this->assertArrayHasKey('first_seen', $data);
        $this->assertArrayHasKey('last_seen', $data);
        $this->assertArrayHasKey('occurrences', $data);
        $this->assertArrayHasKey('confidence', $data);
        $this->assertArrayHasKey('decay_factor', $data);
        $this->assertArrayHasKey('effective_score', $data);
        $this->assertArrayHasKey('category', $data);
        $this->assertArrayHasKey('observations', $data);
        $this->assertIsArray($data['observations']);
        $this->assertArrayHasKey('related_iocs', $data);
        $this->assertIsArray($data['related_iocs']);
    }

    public function testDetailReturns404ForNonexistentIndicator(): void
    {
        $this->client->request('GET', self::BASE_URL . '/' . self::NONEXISTENT_ID . '/detail', [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer fake-jwt',
        ]);

        $this->assertResponseStatusCodeSame(404);

        $data = json_decode((string) $this->client->getResponse()->getContent(), true);
        $this->assertIsArray($data);
        $this->assertArrayHasKey('error', $data);
    }

    public function testDetailIncludesObservationsWithConversationLinks(): void
    {
        // Ensure indicator exists
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
                    'score' => '{}',
                    'tlp' => 'AMBER',
                    'createdAt' => $now,
                    'updatedAt' => $now,
                ]
            );
        }

        $this->client->request('GET', self::BASE_URL . '/' . self::INDICATOR_ID . '/detail', [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer fake-jwt',
        ]);

        $this->assertResponseIsSuccessful();

        $data = json_decode((string) $this->client->getResponse()->getContent(), true);

        // The fixture creates an observed_ioc with this indicator_id
        // Each observation should have conv_id and extraction_method
        foreach ($data['observations'] as $obs) {
            $this->assertArrayHasKey('conv_id', $obs);
            $this->assertArrayHasKey('extraction_method', $obs);
            $this->assertArrayHasKey('ts_observed', $obs);
        }
    }

    public function testDetailReturnsScoreAndEnrichmentData(): void
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
                    'enrichment' => '{"virustotal":{"malicious":5}}',
                    'score' => '{"vt":70,"urlscan":0,"agg":70,"explain":"VT malicious"}',
                    'tlp' => 'AMBER',
                    'createdAt' => $now,
                    'updatedAt' => $now,
                ]
            );
        }

        $this->client->request('GET', self::BASE_URL . '/' . self::INDICATOR_ID . '/detail', [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer fake-jwt',
        ]);

        $this->assertResponseIsSuccessful();

        $data = json_decode((string) $this->client->getResponse()->getContent(), true);
        $this->assertArrayHasKey('score', $data);
        $this->assertArrayHasKey('enrichment', $data);
        $this->assertArrayHasKey('tlp', $data);
        $this->assertSame('AMBER', $data['tlp']);
    }
}
