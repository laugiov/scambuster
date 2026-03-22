<?php

declare(strict_types=1);

namespace App\Domain\LLM\Event;

/**
 * Dispatched after each successful LLM API call.
 *
 * Carries token usage and cost estimation for persistence.
 * Consumed by LlmUsageListener to track costs.
 */
final class LlmCallCompletedEvent
{
    public function __construct(
        private readonly string $provider,
        private readonly string $model,
        private readonly string $purpose,
        private readonly int $promptTokens,
        private readonly int $completionTokens,
        private readonly ?string $conversationId = null
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
