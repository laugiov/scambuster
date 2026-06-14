<?php

declare(strict_types=1);

namespace Tests\Functional\Communication;

use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;

/**
 * Spec 097 / Slice 1 — Theater endpoint functional contract.
 *
 * Covers: auth, 404 on unknown conv, response structure, dedup invariant
 * vs the existing `/iocs` endpoint.
 */
final class GetConversationTheaterControllerTest extends WebTestCase
{
    private KernelBrowser $client;
    private Connection $conn;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->conn = static::getContainer()->get(Connection::class);
    }

    public function testEndpointRequiresAuth_097S1(): void
    {
        $this->client->request('GET', '/api/v1/communication/conversation/00000000-0000-0000-0000-000000000000/theater');
        $this->assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);
    }

    public function testReturns404OnUnknownConversation_097S1(): void
    {
        $this->client->request(
            'GET',
            '/api/v1/communication/conversation/99999999-9999-9999-9999-999999999999/theater',
            [], [], ['HTTP_AUTHORIZATION' => 'Bearer fake-jwt'],
        );
        $this->assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
    }

    public function testResponseStructureForFixtureConversation_097S1(): void
    {
        $convId = $this->pickConvIdWithMessages();
        if (null === $convId) {
            $this->markTestSkipped('No fixture conversation with messages available');
        }

        $data = $this->authenticatedGet('/api/v1/communication/conversation/' . $convId . '/theater');

        $this->assertArrayHasKey('meta', $data);
        $this->assertArrayHasKey('messages', $data);
        $this->assertArrayHasKey('iocs_by_msg', $data);

        $meta = $data['meta'];
        foreach (['conv_id', 'scam_type', 'status', 'ts_first', 'ts_last', 'messages_count', 'iocs_count', 'long_conversation_truncated'] as $key) {
            $this->assertArrayHasKey($key, $meta, "meta must contain {$key}");
        }
        // Spec 099 S2 — persona_label is now part of meta (human-readable
        // label used by the Theater header). Key is always present; value
        // may be null for legacy conversations without a Persona FK.
        $this->assertArrayHasKey('persona_label', $meta, 'meta must contain persona_label (Spec 099 S2)');
        $this->assertTrue(
            $meta['persona_label'] === null || \is_string($meta['persona_label']),
            'meta.persona_label must be string|null',
        );
        // Spec 099 S6 — Actionable-tier IOC count exposed for cross-check.
        $this->assertArrayHasKey('iocs_count_actionable', $meta, 'meta must contain iocs_count_actionable (Spec 099 S6)');
        $this->assertIsInt($meta['iocs_count_actionable']);
        $this->assertLessThanOrEqual(
            $meta['iocs_count'],
            $meta['iocs_count_actionable'],
            'iocs_count_actionable must be <= iocs_count (subset)',
        );
        $this->assertIsInt($meta['messages_count']);
        $this->assertIsInt($meta['iocs_count']);
        $this->assertIsBool($meta['long_conversation_truncated']);
        $this->assertSame($convId, $meta['conv_id']);
    }

    public function testMessagesAreOrderedAndIndexed_097S1(): void
    {
        $convId = $this->pickConvIdWithMessages();
        if (null === $convId) {
            $this->markTestSkipped('No fixture conversation with messages available');
        }

        $data = $this->authenticatedGet('/api/v1/communication/conversation/' . $convId . '/theater');

        $previousTs = null;
        foreach ($data['messages'] as $i => $msg) {
            $this->assertSame($i + 1, $msg['idx'], 'idx must be 1-based sequential');
            foreach (['msg_id', 'direction', 'ts_msg', 'sender', 'body_text'] as $key) {
                $this->assertArrayHasKey($key, $msg);
            }
            $this->assertContains($msg['direction'], ['in', 'out']);

            $ts = \strtotime($msg['ts_msg']);
            if (null !== $previousTs) {
                $this->assertGreaterThanOrEqual($previousTs, $ts, 'messages must be sorted ASC by ts_msg');
            }
            $previousTs = $ts;
        }
    }

    public function testIocsByMsgMatchExistingIocsEndpoint_097S1(): void
    {
        // CRITICAL regression test (spec §Behavior rule #1): the Theater
        // MUST surface the same IOC set as the existing /iocs endpoint,
        // after dedup by value_norm. We assert the value_norm set equality.
        $convId = $this->pickConvIdWithIocs();
        if (null === $convId) {
            $this->markTestSkipped('No fixture conversation with IOCs available');
        }

        $existing = $this->authenticatedGet('/api/v1/communication/conversation/' . $convId . '/iocs');
        $theater = $this->authenticatedGet('/api/v1/communication/conversation/' . $convId . '/theater');

        $existingValueNorms = array_unique(array_filter(array_map(
            static fn (array $row): string => (string) ($row['value_norm'] ?? ''),
            $existing,
        )));
        sort($existingValueNorms);

        $theaterValueNorms = array_filter(array_map(
            static fn (array $row): string => (string) ($row['value_norm'] ?? ''),
            $theater['iocs_by_msg'],
        ));
        sort($theaterValueNorms);

        $this->assertSame(
            $existingValueNorms,
            $theaterValueNorms,
            'Theater value_norm set must equal /iocs deduplicated value_norm set',
        );
    }

    public function testIocsByMsgEntriesHaveExpectedShape_097S1(): void
    {
        $convId = $this->pickConvIdWithIocs();
        if (null === $convId) {
            $this->markTestSkipped('No fixture conversation with IOCs available');
        }

        $data = $this->authenticatedGet('/api/v1/communication/conversation/' . $convId . '/theater');

        foreach ($data['iocs_by_msg'] as $ioc) {
            foreach (['msg_id', 'obs_id', 'indicator_id', 'type', 'value', 'value_norm', 'category', 'ts_observed', 'revelation_context'] as $key) {
                $this->assertArrayHasKey($key, $ioc);
            }
            // Slice 2: revelation_context is either null OR an array with at least enrichment_status.
            $ctx = $ioc['revelation_context'];

            if (null !== $ctx) {
                $this->assertIsArray($ctx);
                $this->assertArrayHasKey('enrichment_status', $ctx);
            }
            $this->assertContains($ioc['category'], ['financial', 'contact', 'infrastructure', 'other']);
        }
    }

    public function testHumanFactorBlockPresentInResponse_097S2(): void
    {
        $convId = $this->pickConvIdWithMessages();
        if (null === $convId) {
            $this->markTestSkipped('No fixture conv');
        }

        $data = $this->authenticatedGet('/api/v1/communication/conversation/' . $convId . '/theater');
        $this->assertArrayHasKey('human_factor', $data);
        $this->assertArrayHasKey('deterministic', $data['human_factor']);
        $this->assertArrayHasKey('exploratory_llm_signals', $data['human_factor']);
        $this->assertArrayHasKey('enrichment_coverage_pct', $data['meta']);
    }

    public function testReturns404OnSoftDeletedConversation_097S1(): void
    {
        // Find a conversation, then mark it deleted, query, restore.
        $convId = $this->pickConvIdWithMessages();
        if (null === $convId) {
            $this->markTestSkipped('No fixture conversation available');
        }

        $this->conn->executeStatement(
            'UPDATE conversation SET deleted_at = NOW() WHERE conv_id = :id',
            ['id' => $convId],
        );

        try {
            $this->client->request('GET', '/api/v1/communication/conversation/' . $convId . '/theater', [], [], [
                'HTTP_AUTHORIZATION' => 'Bearer fake-jwt',
            ]);
            $this->assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
        } finally {
            $this->conn->executeStatement(
                'UPDATE conversation SET deleted_at = NULL WHERE conv_id = :id',
                ['id' => $convId],
            );
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function authenticatedGet(string $url): array
    {
        $this->client->request('GET', $url, [], [], ['HTTP_AUTHORIZATION' => 'Bearer fake-jwt']);
        $this->assertResponseIsSuccessful();
        $content = $this->client->getResponse()->getContent();
        $this->assertIsString($content);
        $data = json_decode($content, true);
        $this->assertIsArray($data);

        return $data;
    }

    private function pickConvIdWithMessages(): ?string
    {
        // Exclude the 00000000-…-XXX fixture conv IDs reserved by other
        // handler tests (e.g. ScamClassificationHandlerTest) to avoid
        // soft-delete interference between functional test runs.
        $row = $this->conn->fetchOne(
            'SELECT c.conv_id::text FROM conversation c'
            . " WHERE c.deleted_at IS NULL AND c.conv_id::text NOT LIKE '00000000-%'"
            . ' AND EXISTS (SELECT 1 FROM message m WHERE m.conv_id = c.conv_id AND m.deleted_at IS NULL)'
            . ' LIMIT 1',
        );

        return false === $row ? null : (string) $row;
    }

    private function pickConvIdWithIocs(): ?string
    {
        $row = $this->conn->fetchOne(
            'SELECT c.conv_id::text FROM conversation c'
            . " WHERE c.deleted_at IS NULL AND c.conv_id::text NOT LIKE '00000000-%'"
            . ' AND EXISTS ('
            . '   SELECT 1 FROM observed_ioc oi'
            . '   JOIN message m ON oi.msg_id = m.msg_id'
            . '   WHERE m.conv_id = c.conv_id AND m.deleted_at IS NULL'
            . ' )'
            . ' LIMIT 1',
        );

        return false === $row ? null : (string) $row;
    }
}
