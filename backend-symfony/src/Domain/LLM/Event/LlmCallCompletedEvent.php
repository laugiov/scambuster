<?php

declare(strict_types=1);

namespace App\Domain\LLM\Event;

/**
 * Dispatched after each successful LLM API call.
 *
 * Carries token usage and cost estimation for persistence.
 * Consumed by LlmUsageListener to track costs.
 */
final readonly class LlmCallCompletedEvent
{
    public function __construct(
        private string $provider,
        private string $model,
        private string $purpose,
        private int $promptTokens,
        private int $completionTokens,
        private ?string $conversationId = null
    ) {
    }

    public function getProvider(): string
    {
        return $this->provider;
    }

    public function getModel(): string
    {
        return $this->model;
    }

    public function getPurpose(): string
    {
        return $this->purpose;
    }

    public function getPromptTokens(): int
    {
        return $this->promptTokens;
    }

    public function getCompletionTokens(): int
    {
        return $this->completionTokens;
    }

    public function getConversationId(): ?string
    {
        return $this->conversationId;
    }
}
