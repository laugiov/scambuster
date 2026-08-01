<?php

declare(strict_types=1);

namespace App\Infrastructure\EventListener;

use App\Application\LLM\CostEstimator;
use App\Domain\LLM\Event\LlmCallCompletedEvent;
use App\Domain\LLM\LlmUsageRecord;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

/**
 * Persists LLM usage records when LlmCallCompletedEvent is dispatched.
 *
 * Calculates cost via CostEstimator and stores in llm_usage table.
 * Non-blocking: errors are logged but do not interrupt the LLM flow.
 */
#[AsEventListener(event: LlmCallCompletedEvent::class)]
final readonly class LlmUsageListener
{
    public function __construct(
        private EntityManagerInterface $em,
        private CostEstimator $costEstimator,
        private LoggerInterface $logger
    ) {
    }
    public function __invoke(LlmCallCompletedEvent $event): void
    {
        try {
            $cost = $this->costEstimator->estimate(
                $event->getProvider(),
                $event->getModel(),
                $event->getPromptTokens(),
                $event->getCompletionTokens()
            );

            $record = new LlmUsageRecord(
                provider: $event->getProvider(),
                model: $event->getModel(),
                purpose: $event->getPurpose(),
                promptTokens: $event->getPromptTokens(),
                completionTokens: $event->getCompletionTokens(),
                estimatedCostUsd: $cost,
                conversationId: $event->getConversationId()
            );

            $this->em->persist($record);
            $this->em->flush();

            $this->logger->debug('LLM usage recorded', [
                'provider' => $event->getProvider(),
                'model' => $event->getModel(),
                'purpose' => $event->getPurpose(),
                'cost_usd' => $cost,
                'prompt_tokens' => $event->getPromptTokens(),
                'completion_tokens' => $event->getCompletionTokens(),
            ]);
        } catch (\Throwable $e) {
            $this->logger->warning('Failed to record LLM usage', [
                'error' => $e->getMessage(),
                'provider' => $event->getProvider(),
            ]);
        }
    }
}
