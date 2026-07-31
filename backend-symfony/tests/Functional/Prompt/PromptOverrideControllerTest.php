<?php

declare(strict_types=1);

namespace Tests\Functional\Prompt;

use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;

/**
 * Functional coverage of the prompt-override admin API: RBAC, list/get/upsert/delete,
 * and write-time validation. DB writes are rolled back per test by the test bundle.
 */
final class PromptOverrideControllerTest extends WebTestCase
{
    private const AUTH = ['HTTP_AUTHORIZATION' => 'Bearer fake-jwt', 'CONTENT_TYPE' => 'application/json'];

    private const ENRICHMENT_BODY = 'scam={{SCAM_TYPE}} p={{PERSONA_CODE}} rt={{REVELATION_TURN}} '
        . 'tt={{TOTAL_TURNS}} ioc={{IOC_TYPES}} prev={{PREVIOUS_INBOUND}} '
        . 'stim={{STIMULUS_MESSAGE}} rev={{REVELATION_MESSAGE}}';

    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = static::createClient();
    }

    private function json(): array
    {
        return json_decode($this->client->getResponse()->getContent(), true);
    }

    // ─── RBAC ──────────────────────────────────────────────────────────

    public function testListRequiresAuthentication(): void
    {
        $this->client->request('GET', '/api/v1/prompt-overrides');
        $this->assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);
    }

    public function testUpsertRequiresAuthentication(): void
    {
        $this->client->request('PUT', '/api/v1/prompt-overrides/reward_judge', [], [], ['CONTENT_TYPE' => 'application/json'], json_encode(['body' => 'x']));
        $this->assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);
    }

    // ─── list / get ────────────────────────────────────────────────────

    public function testListReturnsCatalogRows(): void
    {
        $this->client->request('GET', '/api/v1/prompt-overrides', [], [], self::AUTH);

        $this->assertResponseIsSuccessful();
        $data = $this->json();
        self::assertTrue($data['success']);
        $keys = array_column($data['data'], 'key');
        self::assertContains('reward_judge', $keys);
        self::assertContains('contextual_enrichment', $keys);
    }

    public function testGetUnknownKeyReturns404(): void
    {
        $this->client->request('GET', '/api/v1/prompt-overrides/does_not_exist', [], [], self::AUTH);
        $this->assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
    }

    // ─── upsert validation ─────────────────────────────────────────────

    public function testUpsertUnknownKeyReturns404(): void
    {
        $this->client->request('PUT', '/api/v1/prompt-overrides/nope', [], [], self::AUTH, json_encode(['body' => 'x']));
        $this->assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
    }

    public function testUpsertUnknownFieldReturns422(): void
    {
        $this->client->request('PUT', '/api/v1/prompt-overrides/reward_judge', [], [], self::AUTH, json_encode(['body' => 'x', 'evil' => 1]));
        $this->assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
    }

    public function testUpsertMissingBodyReturns422(): void
    {
        $this->client->request('PUT', '/api/v1/prompt-overrides/reward_judge', [], [], self::AUTH, json_encode(['enabled' => true]));
        $this->assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
    }

    public function testUpsertBodyMissingRequiredPlaceholderReturns422(): void
    {
        $this->client->request('PUT', '/api/v1/prompt-overrides/contextual_enrichment', [], [], self::AUTH, json_encode(['body' => 'no tokens here']));
        $this->assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
        self::assertStringContainsString('{{SCAM_TYPE}}', $this->json()['error']);
    }

    // ─── upsert / get / delete happy path ──────────────────────────────

    public function testUpsertThenGetThenDeleteRewardJudge(): void
    {
        // upsert (reward_judge has no required tokens)
        $this->client->request('PUT', '/api/v1/prompt-overrides/reward_judge', [], [], self::AUTH, json_encode(['body' => 'MY CUSTOM RUBRIC', 'enabled' => true]));
        $this->assertResponseIsSuccessful();
        $upserted = $this->json()['data'];
        self::assertTrue($upserted['has_override']);
        self::assertTrue($upserted['active']);
        self::assertSame('MY CUSTOM RUBRIC', $upserted['body']);

        // get reflects it
        $this->client->request('GET', '/api/v1/prompt-overrides/reward_judge', [], [], self::AUTH);
        $this->assertResponseIsSuccessful();
        self::assertSame('MY CUSTOM RUBRIC', $this->json()['data']['body']);

        // delete
        $this->client->request('DELETE', '/api/v1/prompt-overrides/reward_judge', [], [], self::AUTH);
        $this->assertResponseIsSuccessful();
        self::assertTrue($this->json()['data']['removed']);

        // gone
        $this->client->request('GET', '/api/v1/prompt-overrides/reward_judge', [], [], self::AUTH);
        self::assertFalse($this->json()['data']['has_override']);
    }

    public function testUpsertValidEnrichmentOverrideSucceeds(): void
    {
        $this->client->request('PUT', '/api/v1/prompt-overrides/contextual_enrichment', [], [], self::AUTH, json_encode(['body' => self::ENRICHMENT_BODY]));

        $this->assertResponseIsSuccessful();
        self::assertTrue($this->json()['data']['active']);
    }

    public function testDeleteUnknownKeyReturns404(): void
    {
        $this->client->request('DELETE', '/api/v1/prompt-overrides/nope', [], [], self::AUTH);
        $this->assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
    }
}
