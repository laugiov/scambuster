<?php

declare(strict_types=1);

namespace Tests\Functional\Communication;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

class IocContextControllerTest extends WebTestCase
{
    private \Symfony\Bundle\FrameworkBundle\KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = static::createClient();
    }

    private function authenticatedGet(string $url): void
    {
        $this->client->request('GET', $url, [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer fake-jwt',
        ]);
    }

    public function testRequiresAuth(): void
    {
        $this->client->request('GET', '/api/v1/iocs/00000000-0000-0000-0000-000000000000/context');
        $this->assertResponseStatusCodeSame(401);
    }

    public function testReturns404ForUnknownIndicator(): void
    {
        $this->authenticatedGet('/api/v1/iocs/99999999-9999-9999-9999-999999999999/context');
        $this->assertResponseStatusCodeSame(404);
    }

    public function testReturnsEmptyContextsForIndicatorWithoutContext(): void
    {
        // Use fixture indicator that exists but has no ioc_context row
        $container = static::getContainer();
        $conn = $container->get(\Doctrine\DBAL\Connection::class);

        $indicatorId = $conn->fetchOne('SELECT indicator_id FROM indicator LIMIT 1');

        if (!$indicatorId) {
            $this->markTestSkipped('No indicators in test database');
        }

        // Delete any existing context for this indicator
        $conn->executeStatement('DELETE FROM ioc_context WHERE indicator_id = :id', ['id' => $indicatorId]);

        $this->authenticatedGet('/api/v1/iocs/' . $indicatorId . '/context');
        $this->assertResponseStatusCodeSame(200);

        $data = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertIsArray($data);
        $this->assertSame($indicatorId, $data['indicator_id']);
        $this->assertIsArray($data['contexts']);
        $this->assertEmpty($data['contexts']);
    }

    public function testReturnsStructuralContextWhenAvailable(): void
    {
        $container = static::getContainer();
        $conn = $container->get(\Doctrine\DBAL\Connection::class);

        // Get an IOC and compute its context
        $row = $conn->fetchAssociative(
            'SELECT oi.obs_id, oi.indicator_id, oi.msg_id, i.type AS ioc_type'
            . ' FROM observed_ioc oi'
            . ' JOIN indicator i ON oi.indicator_id = i.indicator_id'
            . " WHERE i.type NOT IN ('message_id','subject','spf_result','dkim_result','dmarc_result','x_mailer','return_path')"
            . ' LIMIT 1'
        );

        if (!$row) {
            $this->markTestSkipped('No non-header IOCs in test database');
        }

        // Compute context
        $service = new \App\Application\Communication\IocContextService(
            $conn,
            new \Psr\Log\NullLogger(),
        );
        $service->computeAndPersistForMessage($row['msg_id'], [
            ['obs_id' => $row['obs_id'], 'indicator_id' => $row['indicator_id'], 'ioc_type' => $row['ioc_type']],
        ]);

        $this->authenticatedGet('/api/v1/iocs/' . $row['indicator_id'] . '/context');
        $this->assertResponseStatusCodeSame(200);

        $data = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertNotEmpty($data['contexts']);

        $context = $data['contexts'][0];
        $this->assertSame('structural', $context['enrichment_status']);
        $this->assertArrayHasKey('structural', $context);
        $this->assertNull($context['semantic']);
        $this->assertNotNull($context['structural']['scam_type']);
        $this->assertNotNull($context['structural']['revelation_turn']);
        $this->assertIsArray($context['structural']['co_revealed_types']);
    }

    public function testReturnsJsonContentType(): void
    {
        $container = static::getContainer();
        $conn = $container->get(\Doctrine\DBAL\Connection::class);

        $indicatorId = $conn->fetchOne('SELECT indicator_id FROM indicator LIMIT 1');

        if (!$indicatorId) {
            $this->markTestSkipped('No indicators in test database');
        }

        $this->authenticatedGet('/api/v1/iocs/' . $indicatorId . '/context');
        $this->assertResponseHeaderSame('content-type', 'application/json');
    }
}
