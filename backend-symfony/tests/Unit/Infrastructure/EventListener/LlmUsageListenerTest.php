<?php

declare(strict_types=1);

namespace App\Tests\Unit\Infrastructure\EventListener;

use App\Application\LLM\CostEstimator;
use App\Domain\LLM\Event\LlmCallCompletedEvent;
use App\Domain\LLM\LlmUsageRecord;
use App\Infrastructure\EventListener\LlmUsageListener;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Unit tests for LlmUsageListener.
 *
 * CostEstimator is final, so we use the real instance (no dependencies).
 */
final class LlmUsageListenerTest extends TestCase
{
    private EntityManagerInterface&MockObject $em;
    private CostEstimator $costEstimator;
    private LoggerInterface&MockObject $logger;
    private LlmUsageListener $listener;

    protected function setUp(): void
    {
        $this->em = $this->createMock(EntityManagerInterface::class);
        $this->costEstimator = new CostEstimator();
        $this->logger = $this->createMock(LoggerInterface::class);

        $this->listener = new LlmUsageListener(
            $this->em,
            $this->costEstimator,
            $this->logger
        );
    }

    public function testInvokePersistsUsageRecord(): void
    {
        $event = new LlmCallCompletedEvent(
            provider: 'openai',
            model: 'gpt-4o-mini',
            purpose: 'reply_generation',
            promptTokens: 500,
            completionTokens: 200,
            conversationId: 'conv-123'
        );

        $this->em
            ->expects($this->once())
            ->method('persist')
            ->with($this->isInstanceOf(LlmUsageRecord::class));

        $this->em
            ->expects($this->once())
            ->method('flush');

        $this->logger
            ->expects($this->once())
            ->method('debug')
            ->with('LLM usage recorded', $this->isType('array'));

        ($this->listener)($event);
    }

    public function testInvokeLogsWarningOnPersistException(): void
    {
        $event = new LlmCallCompletedEvent(
            provider: 'openai',
            model: 'gpt-4o-mini',
            purpose: 'reply_generation',
            promptTokens: 100,
            completionTokens: 50,
        );

        $this->em
            ->method('persist')
            ->willThrowException(new \RuntimeException('DB connection lost'));

        $this->logger
            ->expects($this->once())
            ->method('warning')
            ->with('Failed to record LLM usage', $this->callback(function (array $context): bool {
                return $context['error'] === 'DB connection lost' && $context['provider'] === 'openai';
            }));

        // Should not throw
        ($this->listener)($event);
    }

    public function testInvokeWithNullConversationId(): void
    {
        $event = new LlmCallCompletedEvent(
            provider: 'openai',
            model: 'gpt-4o-mini',
            purpose: 'classification',
            promptTokens: 300,
            completionTokens: 100,
            conversationId: null
        );

        $this->em
            ->expects($this->once())
            ->method('persist')
            ->with($this->isInstanceOf(LlmUsageRecord::class));

        $this->em
            ->expects($this->once())
            ->method('flush');

        ($this->listener)($event);
    }

    public function testInvokeCalculatesCorrectCostForKnownModel(): void
    {
        $event = new LlmCallCompletedEvent(
            provider: 'openai',
            model: 'gpt-4o-mini',
            purpose: 'test',
            promptTokens: 1000,
            completionTokens: 1000,
        );

        $persistedRecord = null;
        $this->em
            ->expects($this->once())
            ->method('persist')
            ->with($this->callback(function ($record) use (&$persistedRecord): bool {
                $persistedRecord = $record;

                return $record instanceof LlmUsageRecord;
            }));

        $this->em->method('flush');

        ($this->listener)($event);

        // gpt-4o-mini: input=0.00015/1K, output=0.0006/1K
        // 1000 prompt tokens = 0.00015, 1000 completion tokens = 0.0006
        // Total = 0.00075
        $this->assertNotNull($persistedRecord);
    }

    public function testInvokeLogsDebugWithCorrectContext(): void
    {
        $event = new LlmCallCompletedEvent(
            provider: 'openai',
            model: 'gpt-4o',
            purpose: 'policy_guard',
            promptTokens: 800,
            completionTokens: 150,
        );

        $this->em->method('persist');
        $this->em->method('flush');

        $this->logger
            ->expects($this->once())
            ->method('debug')
            ->with('LLM usage recorded', $this->callback(function (array $context): bool {
                return $context['provider'] === 'openai'
                    && $context['model'] === 'gpt-4o'
                    && $context['purpose'] === 'policy_guard'
                    && $context['prompt_tokens'] === 800
                    && $context['completion_tokens'] === 150;
            }));

        ($this->listener)($event);
    }

    public function testInvokeFlushExceptionDoesNotPropagate(): void
    {
        $event = new LlmCallCompletedEvent(
            provider: 'openai',
            model: 'gpt-4o-mini',
            purpose: 'test',
            promptTokens: 50,
            completionTokens: 25,
        );

        $this->em->method('persist');
        $this->em
            ->method('flush')
            ->willThrowException(new \RuntimeException('Flush failed'));

        $this->logger
            ->expects($this->once())
            ->method('warning');

        // Must not throw
        ($this->listener)($event);
    }

    public function testInvokeWithMockProviderZeroCost(): void
    {
        $event = new LlmCallCompletedEvent(
            provider: 'mock',
            model: 'mock-model',
            purpose: 'test',
            promptTokens: 500,
            completionTokens: 200,
        );

        $this->em
            ->expects($this->once())
            ->method('persist')
            ->with($this->isInstanceOf(LlmUsageRecord::class));

        $this->em
            ->expects($this->once())
            ->method('flush');

        $this->logger
            ->expects($this->once())
            ->method('debug')
            ->with('LLM usage recorded', $this->callback(function (array $context): bool {
                return $context['cost_usd'] === 0.0;
            }));

        ($this->listener)($event);
    }

    public function testInvokeWithAnthropicModel(): void
    {
        $event = new LlmCallCompletedEvent(
            provider: 'anthropic',
            model: 'claude-haiku-4-5-20251001',
            purpose: 'ioc_extraction',
            promptTokens: 1000,
            completionTokens: 400,
        );

        $this->em
            ->expects($this->once())
            ->method('persist')
            ->with($this->isInstanceOf(LlmUsageRecord::class));

        $this->em
            ->expects($this->once())
            ->method('flush');

        ($this->listener)($event);
    }
}
