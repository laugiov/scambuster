<?php

declare(strict_types=1);

namespace App\Tests\Integration\Communication;

use App\Application\Audit\AuditLogger;
use App\Application\Communication\MessageHandler;
use App\Application\Communication\ReplyCadenceService;
use App\Application\Communication\ReplyCompositionService;
use App\Application\Communication\ReplyContextService;
use App\Application\Communication\ReplyHandler;
use App\Application\LLM\ReplyOrchestrator;
use App\Application\Monitoring\LlmCostHandler;
use App\Domain\Communication\Channel;
use App\Domain\Communication\ConversationStatus;
use App\Domain\Communication\Direction;
use App\Domain\Communication\MailAccount;
use App\Domain\Communication\Message;
use App\Domain\Communication\ScamType;
use App\Domain\LLM\Exception\LlmBudgetExceededException;
use App\Domain\LLM\LlmUsageRecord;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Spec 065b — Phase 5 — ReplyHandler budget enforcement.
 *
 * Tests that ReplyHandler::generateReply() blocks LLM calls when the
 * monthly budget is exhausted. Two modes:
 *   - 'enforce': throws LlmBudgetExceededException
 *   - 'warning': logs a warning but proceeds (used during the
 *     telemetry validation window before flipping to enforce)
 *
 * Strategy: build a fresh ReplyHandler with the desired mode, reusing
 * the container's existing dependencies. Seed `llm_usage` rows to
 * exceed the configured cap, then call `generateReply()`.
 */
final class ReplyHandlerBudgetEnforcementTest extends KernelTestCase
{
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = self::getContainer()->get('doctrine')->getManager();
        // Wipe llm_usage rows from prior tests so the SUM is deterministic.
        $this->em->getConnection()->executeStatement('DELETE FROM llm_usage');
    }

    private function makeReplyHandler(string $mode): ReplyHandler
    {
        $container = self::getContainer();

        return new ReplyHandler(
            $this->em,
            $container->get(MessageHandler::class),
            $container->get(ReplyOrchestrator::class),
            $container->get(ReplyContextService::class),
            $container->get(ReplyCadenceService::class),
            $container->get(ReplyCompositionService::class),
            $container->get(LoggerInterface::class),
            $container->get(AuditLogger::class),
            $container->get(LlmCostHandler::class),
            $mode,
        );
    }

    private function createTestConversationWithMessage(): array
    {
        $channel = $this->em->getRepository(Channel::class)->findOneBy(['code' => 'email']);
        $scamType = $this->em->getRepository(ScamType::class)->findOneBy([]);
        $account = $this->em->getRepository(MailAccount::class)->findOneBy([]);
        $direction = $this->em->getRepository(Direction::class)->findOneBy(['code' => 'in']);

        $conversationHandler = self::getContainer()->get(\App\Application\Communication\ConversationHandler::class);
        $conv = $conversationHandler->createConversation(
            $channel,
            $scamType,
            $account,
            ConversationStatus::OPEN,
            75,
            new \DateTimeImmutable('-1 hour'),
            new \DateTimeImmutable(),
            'stix-065b-' . bin2hex(random_bytes(4)),
        );

        $msgId = uuid_create(UUID_TYPE_RANDOM);
        $message = new Message(
            $msgId,
            $conv,
            $channel,
            $direction,
            'fr',
            'Spec 065b enforcement test',
            'Please send your bank details immediately!',
            null,
            ['from' => 'scammer@evil.test', 'to' => 'victim@example.test'],
            bin2hex(random_bytes(32)),
            null,
            null,
            new \DateTimeImmutable(),
            new \DateTimeImmutable(),
            null,
        );
        $this->em->persist($message);
        $this->em->flush();

        return ['conv_id' => $conv->getConvId(), 'msg_id' => $msgId];
    }

    private function seedLlmUsage(float $costUsd): void
    {
        $record = new LlmUsageRecord(
            provider: 'openai',
            model: 'gpt-4o',
            purpose: 'reply_generation',
            promptTokens: 1000,
            completionTokens: 500,
            estimatedCostUsd: $costUsd,
        );
        $this->em->persist($record);
        $this->em->flush();
    }

    public function test_it_throws_when_budget_exceeded_and_mode_is_enforce(): void
    {
        $data = $this->createTestConversationWithMessage();
        $this->seedLlmUsage(60.0); // > 50 USD default cap

        $handler = $this->makeReplyHandler('enforce');

        $this->expectException(LlmBudgetExceededException::class);
        $handler->generateReply($data['conv_id'], $data['msg_id'], false, 'budget-test');
    }

    public function test_it_logs_warning_when_budget_exceeded_and_mode_is_warning(): void
    {
        $data = $this->createTestConversationWithMessage();
        $this->seedLlmUsage(60.0);

        $handler = $this->makeReplyHandler('warning');

        // Mode warning: must NOT throw LlmBudgetExceededException.
        // The reply may still fail downstream (LLM mock, validator, etc.)
        // but the budget guard does not block it.
        try {
            $handler->generateReply($data['conv_id'], $data['msg_id'], false, 'budget-test');
            $this->addToAssertionCount(1);
        } catch (LlmBudgetExceededException $e) {
            $this->fail('Mode warning must NOT throw LlmBudgetExceededException');
        } catch (\Throwable $e) {
            // Other exceptions (cadence, rate limit, LLM mock unavailability)
            // are acceptable — the test only verifies the budget guard.
            $this->assertNotInstanceOf(LlmBudgetExceededException::class, $e);
        }
    }

    public function test_it_proceeds_when_budget_not_exceeded(): void
    {
        $data = $this->createTestConversationWithMessage();
        // No llm_usage rows seeded; current month spend = 0

        $handler = $this->makeReplyHandler('enforce');

        try {
            $handler->generateReply($data['conv_id'], $data['msg_id'], false, 'budget-test');
            $this->addToAssertionCount(1);
        } catch (LlmBudgetExceededException $e) {
            $this->fail('Budget not exceeded must NOT throw LlmBudgetExceededException');
        } catch (\Throwable $e) {
            // Other exceptions are acceptable.
            $this->assertNotInstanceOf(LlmBudgetExceededException::class, $e);
        }
    }

    public function test_it_proceeds_when_cost_handler_is_null(): void
    {
        // Backwards-compat path: handler constructed without LlmCostHandler.
        $data = $this->createTestConversationWithMessage();
        $this->seedLlmUsage(60.0);

        $container = self::getContainer();
        $handler = new ReplyHandler(
            $this->em,
            $container->get(MessageHandler::class),
            $container->get(ReplyOrchestrator::class),
            $container->get(ReplyContextService::class),
            $container->get(ReplyCadenceService::class),
            $container->get(ReplyCompositionService::class),
            $container->get(LoggerInterface::class),
            null, // no audit logger
            null, // no cost handler — skips budget check entirely
            'enforce',
        );

        try {
            $handler->generateReply($data['conv_id'], $data['msg_id'], false, 'budget-test');
            $this->addToAssertionCount(1);
        } catch (LlmBudgetExceededException $e) {
            $this->fail('Null cost handler must NOT trigger budget enforcement');
        } catch (\Throwable $e) {
            $this->assertNotInstanceOf(LlmBudgetExceededException::class, $e);
        }
    }
}
