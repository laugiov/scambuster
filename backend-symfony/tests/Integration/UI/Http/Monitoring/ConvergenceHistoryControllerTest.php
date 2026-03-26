<?php

declare(strict_types=1);

namespace Tests\Integration\UI\Http\Monitoring;

use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;

final class ConvergenceHistoryControllerTest extends WebTestCase
{
    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = static::createClient();
    }

    public function testConvergenceHistoryRequiresAuthentication(): void
    {
        $this->client->request('GET', '/api/v1/monitoring/convergence-history');

        $this->assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);
    }

    public function testConvergenceHistoryReturnsExpectedStructure(): void
    {
        $this->client->request('GET', '/api/v1/monitoring/convergence-history', [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer fake-jwt',
        ]);

        $this->assertResponseIsSuccessful();

        $data = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertIsArray($data);
        $this->assertArrayHasKey('period_days', $data);
        $this->assertArrayHasKey('by_scam_type', $data);
        $this->assertSame(30, $data['period_days']);
    }

    public function testConvergenceHistoryByScamTypeIsObject(): void
    {
        $this->client->request('GET', '/api/v1/monitoring/convergence-history', [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer fake-jwt',
        ]);

        $this->assertResponseIsSuccessful();

        $data = json_decode($this->client->getResponse()->getContent(), true);
        // by_scam_type is cast to (object), so it can be an empty object {} or keyed array
        $this->assertIsArray($data['by_scam_type']);
    }

    public function testConvergenceHistoryReturnsJsonContentType(): void
    {
        $this->client->request('GET', '/api/v1/monitoring/convergence-history', [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer fake-jwt',
        ]);

        $this->assertResponseIsSuccessful();
        $this->assertResponseHeaderSame('content-type', 'application/json');
    }

    public function testConvergenceHistoryPeriodDaysIs30(): void
    {
        $this->client->request('GET', '/api/v1/monitoring/convergence-history', [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer fake-jwt',
        ]);

        $this->assertResponseIsSuccessful();

        $data = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertSame(30, $data['period_days']);
        $this->assertIsInt($data['period_days']);
    }

    public function testConvergenceHistoryByScamTypeKeysAreUppercase(): void
    {
        $this->client->request('GET', '/api/v1/monitoring/convergence-history', [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer fake-jwt',
        ]);

        $this->assertResponseIsSuccessful();

        $data = json_decode($this->client->getResponse()->getContent(), true);
        $byScamType = $data['by_scam_type'];

        foreach (array_keys($byScamType) as $key) {
            $this->assertSame(
                strtoupper($key),
                $key,
                "Scam type key '{$key}' should be uppercase"
            );
        }
    }

    public function testConvergenceHistoryByScamTypeEntriesHaveExpectedFields(): void
    {
        $this->client->request('GET', '/api/v1/monitoring/convergence-history', [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer fake-jwt',
        ]);

        $this->assertResponseIsSuccessful();

        $data = json_decode($this->client->getResponse()->getContent(), true);

        foreach ($data['by_scam_type'] as $scamTypeCode => $entries) {
            $this->assertIsArray($entries);

            foreach ($entries as $entry) {
                $this->assertArrayHasKey('date', $entry);
                $this->assertArrayHasKey('dominant_persona', $entry);
                $this->assertArrayHasKey('dominant_pct', $entry);
                $this->assertArrayHasKey('sessions_count', $entry);
                $this->assertArrayHasKey('converged', $entry);
                $this->assertIsBool($entry['converged']);
            }
        }
    }
}
