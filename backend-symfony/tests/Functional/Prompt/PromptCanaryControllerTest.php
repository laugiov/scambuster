<?php

declare(strict_types=1);

namespace Tests\Functional\Prompt;

use App\Application\Guard\CanaryAvailability;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;

/**
 * Functional coverage of the async "validate this prompt" API: RBAC, candidate validation,
 * job creation (202 + id), and polling. DB writes are rolled back per test by the test bundle.
 */
final class PromptCanaryControllerTest extends WebTestCase
{
    private const AUTH = ['HTTP_AUTHORIZATION' => 'Bearer fake-jwt', 'CONTENT_TYPE' => 'application/json'];

    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = static::createClient();
    }

    /**
     * @return array<string, mixed>
     */
    private function json(): array
    {
        return json_decode((string) $this->client->getResponse()->getContent(), true);
    }

    // ─── RBAC ──────────────────────────────────────────────────────────

    public function testRequestRequiresAuthentication(): void
    {
        $this->client->request('POST', '/api/v1/prompt-overrides/reward_judge/canary', [], [], ['CONTENT_TYPE' => 'application/json'], json_encode(['body' => 'x']));
        $this->assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);
    }

    public function testPollRequiresAuthentication(): void
    {
        $this->client->request('GET', '/api/v1/prompt-overrides/canary/1');
        $this->assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);
    }

    // ─── request validation ────────────────────────────────────────────

    public function testRequestUnknownKeyReturns404(): void
    {
        $this->client->request('POST', '/api/v1/prompt-overrides/nope/canary', [], [], self::AUTH, json_encode(['body' => 'x']));
        $this->assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
    }

    public function testRequestMissingBodyReturns422(): void
    {
        $this->client->request('POST', '/api/v1/prompt-overrides/reward_judge/canary', [], [], self::AUTH, json_encode(['enabled' => true]));
        $this->assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
    }

    public function testRequestUnknownFieldReturns422(): void
    {
        $this->client->request('POST', '/api/v1/prompt-overrides/reward_judge/canary', [], [], self::AUTH, json_encode(['body' => 'x', 'evil' => 1]));
        $this->assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
    }

    public function testRequestInvalidCandidateReturns422(): void
    {
        // contextual_enrichment requires placeholders; a body without them is rejected before enqueue.
        $this->client->request('POST', '/api/v1/prompt-overrides/contextual_enrichment/canary', [], [], self::AUTH, json_encode(['body' => 'no tokens here']));
        $this->assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
        self::assertStringContainsString('{{SCAM_TYPE}}', $this->json()['error']);
    }

    public function testRequestUnavailableProviderReturns503(): void
    {
        // A deployment with no live model provider (e.g. mock/demo) must refuse a direct POST
        // rather than enqueue a job that could only hang — the server-side backstop for the UI's
        // hidden "Validate" button. disableReboot keeps the swapped-in service for the request.
        $this->client->disableReboot();
        static::getContainer()->set(CanaryAvailability::class, new CanaryAvailability('mock', '', ''));

        $this->client->request('POST', '/api/v1/prompt-overrides/reward_judge/canary', [], [], self::AUTH, json_encode(['body' => 'MY CANDIDATE RUBRIC']));

        $this->assertResponseStatusCodeSame(Response::HTTP_SERVICE_UNAVAILABLE);
        self::assertFalse($this->json()['success']);
    }

    // ─── request → poll happy path ─────────────────────────────────────

    public function testValidCandidateCreatesPendingJobAndPolls(): void
    {
        $this->client->request('POST', '/api/v1/prompt-overrides/reward_judge/canary', [], [], self::AUTH, json_encode(['body' => 'MY CANDIDATE RUBRIC']));

        $this->assertResponseStatusCodeSame(Response::HTTP_ACCEPTED);
        $created = $this->json();
        self::assertTrue($created['success']);
        self::assertIsInt($created['data']['job_id']);
        self::assertSame('pending', $created['data']['status']);

        $jobId = $created['data']['job_id'];

        // Poll it — still pending (no worker in this test), verdict not yet available.
        $this->client->request('GET', "/api/v1/prompt-overrides/canary/{$jobId}", [], [], self::AUTH);
        $this->assertResponseIsSuccessful();
        $polled = $this->json()['data'];
        self::assertSame($jobId, $polled['job_id']);
        self::assertSame('reward_judge', $polled['prompt_key']);
        self::assertSame('pending', $polled['status']);
        self::assertNull($polled['verdict']);
        self::assertNull($polled['finished_at']);
    }

    // ─── latest-for-key (UI re-attach on reload) ───────────────────────

    public function testLatestRequiresAuthentication(): void
    {
        $this->client->request('GET', '/api/v1/prompt-overrides/reward_judge/canary/latest');
        $this->assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);
    }

    public function testLatestIsNullForANeverValidatedKey(): void
    {
        $this->client->request('GET', '/api/v1/prompt-overrides/persona_style_rules/canary/latest', [], [], self::AUTH);
        $this->assertResponseIsSuccessful();
        self::assertNull($this->json()['data']);
    }

    public function testLatestReturnsTheMostRecentJobWithItsCandidateBody(): void
    {
        $this->client->request('POST', '/api/v1/prompt-overrides/reward_judge/canary', [], [], self::AUTH, json_encode(['body' => 'REATTACH CANDIDATE BODY']));
        $this->assertResponseStatusCodeSame(Response::HTTP_ACCEPTED);
        $jobId = $this->json()['data']['job_id'];

        $this->client->request('GET', '/api/v1/prompt-overrides/reward_judge/canary/latest', [], [], self::AUTH);
        $this->assertResponseIsSuccessful();
        $latest = $this->json()['data'];

        self::assertNotNull($latest);
        self::assertSame($jobId, $latest['job_id']);
        self::assertSame('reward_judge', $latest['prompt_key']);
        self::assertSame('REATTACH CANDIDATE BODY', $latest['candidate_body']);
        self::assertSame('pending', $latest['status']);
    }

    public function testPollUnknownJobReturns404(): void
    {
        $this->client->request('GET', '/api/v1/prompt-overrides/canary/999999999', [], [], self::AUTH);
        $this->assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
    }

    public function testPollOverRangeJobIdReturns404NotServerError(): void
    {
        // A digit string larger than PHP_INT_MAX must not overflow the int coercion (HTTP 500);
        // the bounded route requirement means it simply does not match → a clean 404.
        $this->client->request('GET', '/api/v1/prompt-overrides/canary/99999999999999999999', [], [], self::AUTH);
        $this->assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
    }
}
