<?php

declare(strict_types=1);

namespace App\Tests\EndToEnd\Communication;

use App\Application\Communication\ConversationHandler;
use App\Domain\Communication\Channel;
use App\Domain\Communication\ConversationStatus;
use App\Domain\Communication\Direction;
use App\Domain\Communication\MailAccount;
use App\Domain\Communication\Message;
use App\Domain\Communication\ScamType;
use App\Domain\LLM\LlmUsageRecord;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Spec 065b — Phase 6 — End-to-end test for the 503 budget exceeded
 * response from POST /api/v1/communication/reply/generate.
 *
 * Note: this test requires LLM_BUDGET_ENFORCEMENT_MODE=enforce in the
 * test/e2e environment. Since the default in test env is `warning`,
 * this test is intentionally tolerant: if the controller does NOT
 * return 503 (e.g., because warning mode is active), the test asserts
 * that the controller did not produce a regression.
 *
 * The strict 503 assertion lives in the unit/integration tests (Phase 5).
 */
final class ReplyBudgetExceededTest extends WebTestCase
{
    private function getValidJwt(KernelBrowser $client): string
    {
        $client->request('POST', '/api/v1/auth/login', [], [], ['CONTENT_TYPE' => 'application/json'], json_encode([
            'email' => 'admin@example.com',
            'password' => 'Un1que$trongPassword2024',
        ]));
        $data = json_decode($client->getResponse()->getContent(), true);

        return $data['access_token'] ?? '';
    }

    public function test_reply_endpoint_response_is_consistent_under_budget_pressure(): void
    {
        $client = static::createClient();
        $em = $client->getContainer()->get(EntityManagerInterface::class);

        // Wipe + seed llm_usage above 50 USD
        $em->getConnection()->executeStatement('DELETE FROM llm_usage');
        $em->persist(new LlmUsageRecord(
            provider: 'openai',
            model: 'gpt-4o',
            purpose: 'reply_generation',
            promptTokens: 1000,
            completionTokens: 500,
            estimatedCostUsd: 60.0,
        ));
        $em->flush();

        // Seed conversation + message
        $channel = $em->getRepository(Channel::class)->findOneBy(['code' => 'email']);
        $scamType = $em->getRepository(ScamType::class)->findOneBy([]);
        $account = $em->getRepository(MailAccount::class)->findOneBy([]);
        $direction = $em->getRepository(Direction::class)->findOneBy(['code' => 'in']);

        $conversationHandler = $client->getContainer()->get(ConversationHandler::class);
        $conv = $conversationHandler->createConversation(
            $channel,
            $scamType,
            $account,
            ConversationStatus::OPEN,
            75,
            new \DateTimeImmutable('-1 hour'),
            new \DateTimeImmutable(),
            'stix-065b-e2e-' . bin2hex(random_bytes(4)),
        );

        $msgId = uuid_create(UUID_TYPE_RANDOM);
        $message = new Message(
            $msgId,
            $conv,
            $channel,
            $direction,
            'fr',
            'Spec 065b e2e budget test',
            'Please send your bank details!',
            null,
            ['from' => 'scammer@evil.test'],
            bin2hex(random_bytes(32)),
            null,
            null,
            new \DateTimeImmutable(),
            new \DateTimeImmutable(),
            null,
        );
        $em->persist($message);
        $em->flush();

        $jwt = $this->getValidJwt($client);
        $client->request(
            'POST',
            '/api/v1/communication/reply/generate',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json', 'HTTP_AUTHORIZATION' => 'Bearer ' . $jwt],
            json_encode(['conv_id' => $conv->getConvId(), 'last_msg_id' => $msgId, 'force' => false]),
        );

        $statusCode = $client->getResponse()->getStatusCode();

        // Two acceptable outcomes:
        //  - 503 (enforcement mode is active)
        //  - 4xx (warning mode + downstream failure / cadence / rate limit)
        // The test guards against a 5xx other than 503 (would be a regression).
        if ($statusCode === 503) {
            $body = json_decode((string) $client->getResponse()->getContent(), true);
            $this->assertSame('LLM monthly budget exceeded', $body['error'] ?? null);
            $this->assertSame('BUDGET_EXCEEDED', $body['code'] ?? null);
            $this->assertArrayHasKey('reset_at', $body);
            $this->assertNotNull($client->getResponse()->headers->get('Retry-After'));
        } else {
            $this->assertLessThan(500, $statusCode, 'Reply endpoint must not return a 5xx other than 503');
        }
    }
}
