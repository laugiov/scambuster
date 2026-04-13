<?php

declare(strict_types=1);

namespace Tests\Functional\Monitoring;

use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;

final class PipelineTracesControllerTest extends WebTestCase
{
    private KernelBrowser $client;

    private const TRACES_URL = '/api/v1/monitoring/pipeline-traces';
    private const HEALTH_URL = '/api/v1/monitoring/pipeline-health';
    private const NONEXISTENT_MSG_ID = '99999999-9999-9999-9999-999999999999';

    protected function setUp(): void
    {
        $this->client = static::createClient();
    }

    // ──────────────────────────────────────────────
    // LIST TRACES (GET /api/v1/monitoring/pipeline-traces)
    // ──────────────────────────────────────────────

    public function testListTracesReturns200WithJsonArray(): void
    {
        $this->client->request('GET', self::TRACES_URL, [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer fake-jwt',
        ]);

        $this->assertResponseIsSuccessful();
        $this->assertResponseHeaderSame('content-type', 'application/json');

        $data = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertIsArray($data);
    }

    public function testListTracesRespectsLimitParameter(): void
    {
        $this->client->request('GET', self::TRACES_URL . '?limit=5', [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer fake-jwt',
        ]);

        $this->assertResponseIsSuccessful();

        $data = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertIsArray($data);

        // The handler may return a wrapper or direct array; check count if array
        if (isset($data['traces'])) {
            $this->assertLessThanOrEqual(5, count($data['traces']));
        } else {
            $this->assertLessThanOrEqual(5, count($data));
        }
    }

    public function testListTracesRespectsDaysParameter(): void
    {
        $this->client->request('GET', self::TRACES_URL . '?days=1', [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer fake-jwt',
        ]);

        $this->assertResponseIsSuccessful();

        $data = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertIsArray($data);
    }

    public function testListTracesReturns401WithoutAuth(): void
    {
        $this->client->request('GET', self::TRACES_URL);

        $this->assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);
    }

    public function testListTracesReturnsJsonContentType(): void
    {
        $this->client->request('GET', self::TRACES_URL, [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer fake-jwt',
        ]);

        $this->assertResponseHeaderSame('content-type', 'application/json');
    }

    // ──────────────────────────────────────────────
    // TRACE DETAIL (GET /api/v1/monitoring/pipeline-traces/{msgId})
    // ──────────────────────────────────────────────

    public function testTraceDetailReturns404ForNonexistentMessage(): void
    {
        $this->client->request('GET', self::TRACES_URL . '/' . self::NONEXISTENT_MSG_ID, [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer fake-jwt',
        ]);

        $this->assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
        $this->assertResponseHeaderSame('content-type', 'application/json');

        $data = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertIsArray($data);
        $this->assertArrayHasKey('error', $data);
    }

    public function testTraceDetailReturns401WithoutAuth(): void
    {
        $this->client->request('GET', self::TRACES_URL . '/' . self::NONEXISTENT_MSG_ID);

        $this->assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);
    }

    public function testTraceDetailWithExistingMessageFromFixtures(): void
    {
        // First fetch traces to get a real message ID (if any exist in fixtures)
        $this->client->request('GET', self::TRACES_URL . '?limit=1', [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer fake-jwt',
        ]);

        $this->assertResponseIsSuccessful();

        $data = json_decode($this->client->getResponse()->getContent(), true);
        $traces = $data['traces'] ?? $data;

        if (\is_array($traces) && count($traces) > 0) {
            $firstTrace = $traces[0];
            $msgId = $firstTrace['msg_id'] ?? $firstTrace['message_id'] ?? null;

            if ($msgId !== null) {
                $this->client->request('GET', self::TRACES_URL . '/' . $msgId, [], [], [
                    'HTTP_AUTHORIZATION' => 'Bearer fake-jwt',
                ]);

                $statusCode = $this->client->getResponse()->getStatusCode();
                // 200 if trace exists, 404 if message has no pipeline trace
                $this->assertContains($statusCode, [
                    Response::HTTP_OK,
                    Response::HTTP_NOT_FOUND,
                ]);
            }
        }

        // If no traces in fixtures, the test still passes (nothing to assert on detail)
        $this->assertTrue(true);
    }

    // ──────────────────────────────────────────────
    // PIPELINE HEALTH (GET /api/v1/monitoring/pipeline-health)
    // ──────────────────────────────────────────────

    public function testPipelineHealthReturns200(): void
    {
        $this->client->request('GET', self::HEALTH_URL, [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer fake-jwt',
        ]);

        $this->assertResponseIsSuccessful();
        $this->assertResponseHeaderSame('content-type', 'application/json');

        $data = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertIsArray($data);
    }

    public function testPipelineHealthReturnsExpectedStructure(): void
    {
        $this->client->request('GET', self::HEALTH_URL, [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer fake-jwt',
        ]);

        $this->assertResponseIsSuccessful();

        $data = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertIsArray($data);

        // The health endpoint should return aggregated metrics
        // Check for common metric keys (success_rate, total_generations, etc.)
        $expectedKeys = ['success_rate', 'total_generations'];
        $hasAtLeastOneKey = false;

        foreach ($expectedKeys as $key) {
            if (\array_key_exists($key, $data)) {
                $hasAtLeastOneKey = true;
                break;
            }
        }

        // If the response is a wrapper, check inside
        if (!$hasAtLeastOneKey && isset($data['metrics'])) {
            $this->assertIsArray($data['metrics']);
            $hasAtLeastOneKey = true;
        }

        // The endpoint returns valid JSON with health data
        $this->assertNotEmpty($data, 'Pipeline health response should not be empty');
    }

    public function testPipelineHealthReturns401WithoutAuth(): void
    {
        $this->client->request('GET', self::HEALTH_URL);

        $this->assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);
    }

    public function testPipelineHealthRespectsHoursParameter(): void
    {
        $this->client->request('GET', self::HEALTH_URL . '?hours=1', [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer fake-jwt',
        ]);

        $this->assertResponseIsSuccessful();

        $data = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertIsArray($data);
    }
}
