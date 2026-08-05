<?php

declare(strict_types=1);

namespace Tests\Functional\Communication;

use App\Application\Communication\TtpManager;
use App\Application\LLM\Port\LLMClientInterface;
use App\Application\LLM\Prompt\PromptProvider;
use App\Application\LLM\TtpExtractor;
use App\Application\Ttp\TtpHandler;
use App\Application\Ttp\TtpObservationUpsertService;
use App\Domain\Communication\Policy\TtpExtractionPolicy;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;

/**
 * Functional coverage of POST /extract-ttps: RBAC, not-found and direction
 * guards, the FakeLLMClient-driven happy path (confirmed/review split,
 * DB persistence, no evidence verbatim in the response), idempotent re-runs
 * and the feature-flag 503. DB writes are rolled back per test.
 */
final class ExtractTtpsControllerTest extends WebTestCase
{
    private const AUTH = ['HTTP_AUTHORIZATION' => 'Bearer fake-jwt', 'CONTENT_TYPE' => 'application/json'];

    // Fixture messages (MessageFixtures): first conversation, inbound + outbound + soft-deleted.
    private const MSG_INBOUND = '00000000-0000-0000-0000-000000000001';
    private const MSG_OUTBOUND = '00000000-0000-0000-0000-000000000101';
    private const MSG_SOFT_DELETED = '00000000-0000-0000-0000-999999999999';

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

    private function post(string $msgId, ?string $body = '{}', array $server = self::AUTH): void
    {
        $this->client->request('POST', "/api/v1/communication/message/{$msgId}/extract-ttps", [], [], $server, $body);
    }

    // ─── RBAC ──────────────────────────────────────────────────────────

    public function testRequiresAuthentication(): void
    {
        $this->post(self::MSG_INBOUND, '{}', ['CONTENT_TYPE' => 'application/json']);
        $this->assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);
    }

    // ─── not-found / direction guards ──────────────────────────────────

    public function testUnknownMessageReturns404(): void
    {
        $this->post('ffffffff-ffff-ffff-ffff-ffffffffffff');
        $this->assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
        self::assertSame('Message not found', $this->json()['error']);
    }

    public function testSoftDeletedMessageReturns404(): void
    {
        $this->post(self::MSG_SOFT_DELETED);
        $this->assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
        self::assertSame('Message not found', $this->json()['error']);
    }

    public function testOutgoingMessageReturns400(): void
    {
        // Outgoing messages are our own generated replies and must never be tagged.
        $this->post(self::MSG_OUTBOUND);
        $this->assertResponseStatusCodeSame(Response::HTTP_BAD_REQUEST);

        $data = $this->json();
        self::assertStringContainsString('outgoing', strtolower($data['error']));
        self::assertSame(self::MSG_OUTBOUND, $data['msg_id']);
        self::assertSame('out', $data['direction']);
    }

    public function testInvalidJsonBodyReturns400(): void
    {
        $this->post(self::MSG_INBOUND, 'this is not json');
        $this->assertResponseStatusCodeSame(Response::HTTP_BAD_REQUEST);
        self::assertSame('Invalid JSON', $this->json()['error']);
    }

    // ─── happy path + idempotence ──────────────────────────────────────

    public function testHappyPathPersistsSplitsStatusesAndHidesEvidence(): void
    {
        /** @var Connection $connection */
        $connection = static::getContainer()->get(Connection::class);

        // Clean slate for the fixture message (rolled back after the test).
        $connection->executeStatement(
            'DELETE FROM ttp_observation WHERE msg_id = :msgId',
            ['msgId' => self::MSG_INBOUND]
        );

        $this->post(self::MSG_INBOUND, json_encode(['persist' => true]));
        $this->assertResponseIsSuccessful();

        $data = $this->json();
        self::assertSame(self::MSG_INBOUND, $data['msg_id']);
        self::assertSame(2, $data['ttps_found']);
        self::assertSame(2, $data['persisted']);
        self::assertIsInt($data['extraction_time_ms']);
        self::assertCount(2, $data['observations']);

        // The API response must not echo evidence verbatims — DB only.
        foreach ($data['observations'] as $observation) {
            self::assertArrayNotHasKey('evidence', $observation);
        }
        $rawResponse = (string) $this->client->getResponse()->getContent();
        self::assertStringNotContainsString('act now', $rawResponse);
        self::assertStringNotContainsString('no time for contracts', $rawResponse);

        // DB rows: confirmed above threshold, review below, evidence stored,
        // offsets null because the fake evidence is not in the fixture text.
        $rows = $connection->fetchAllAssociative(
            'SELECT t.code, o.status, o.evidence, o.evidence_start, o.evidence_end,
                    o.taxonomy_version, o.extraction_model, o.prompt_version
             FROM ttp_observation o
             JOIN lkp_ttp t ON t.ttp_id = o.ttp_id
             WHERE o.msg_id = :msgId
             ORDER BY t.code',
            ['msgId' => self::MSG_INBOUND]
        );
        self::assertCount(2, $rows);

        [$confirmed, $review] = $rows;
        self::assertSame('SB-T017', $confirmed['code']);
        self::assertSame('confirmed', $confirmed['status']);
        self::assertSame('act now', $confirmed['evidence']);
        self::assertNull($confirmed['evidence_start']);
        self::assertNull($confirmed['evidence_end']);
        self::assertSame('1.0', $confirmed['taxonomy_version']);
        self::assertSame('gpt-4o-mini', $confirmed['extraction_model']);
        self::assertSame('v1', $confirmed['prompt_version']);

        self::assertSame('SB-T022', $review['code']);
        self::assertSame('review', $review['status']);
        self::assertSame('no time for contracts', $review['evidence']);
        self::assertNull($review['evidence_start']);
        self::assertNull($review['evidence_end']);

        // Idempotence: a re-run finds the same TTPs but inserts nothing.
        $this->post(self::MSG_INBOUND);
        $this->assertResponseIsSuccessful();

        $rerun = $this->json();
        self::assertSame(2, $rerun['ttps_found']);
        self::assertSame(0, $rerun['persisted']);

        $count = (int) $connection->fetchOne(
            'SELECT COUNT(*) FROM ttp_observation WHERE msg_id = :msgId',
            ['msgId' => self::MSG_INBOUND]
        );
        self::assertSame(2, $count);
    }

    // ─── feature flag ──────────────────────────────────────────────────

    public function testDisabledDeploymentReturns503(): void
    {
        // Swap in a disabled handler for this request (same per-test override
        // idiom as the CanaryAvailability 503 coverage). The disabled path
        // throws before touching any collaborator, so lightweight stand-ins
        // are sufficient for this smoke of the HTTP mapping.
        $this->client->disableReboot();
        static::getContainer()->set(TtpHandler::class, $this->makeDisabledHandler());

        $this->post(self::MSG_INBOUND);

        $this->assertResponseStatusCodeSame(Response::HTTP_SERVICE_UNAVAILABLE);
        $data = $this->json();
        self::assertFalse($data['success']);
        self::assertSame('TTP extraction is disabled on this deployment', $data['error']);
    }

    private function makeDisabledHandler(): TtpHandler
    {
        $llm = new class () implements LLMClientInterface {
            public function chat(array $messages, array $options = []): string
            {
                return '[]';
            }
        };

        return new TtpHandler(
            $this->createMock(EntityManagerInterface::class),
            $this->createMock(TtpManager::class),
            new TtpExtractor($llm, new NullLogger(), new PromptProvider(sys_get_temp_dir(), new NullLogger())),
            new TtpObservationUpsertService($this->createMock(Connection::class), new NullLogger()),
            new TtpExtractionPolicy(),
            new NullLogger(),
            null,
            enabled: false,
        );
    }
}
