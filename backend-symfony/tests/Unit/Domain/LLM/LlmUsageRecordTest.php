<?php

declare(strict_types=1);

namespace App\Tests\Unit\Domain\LLM;

use App\Domain\LLM\LlmUsageRecord;
use PHPUnit\Framework\TestCase;

class LlmUsageRecordTest extends TestCase
{
    public function testConstructionWithAllFields(): void
    {
        $record = new LlmUsageRecord(
            provider: 'openai',
            model: 'gpt-4o-mini',
            purpose: 'reply_generation',
            promptTokens: 100,
            completionTokens: 50,
            estimatedCostUsd: 0.00045,
            conversationId: 'conv-123'
        );

        $this->assertSame('openai', $record->getProvider());
        $this->assertSame('gpt-4o-mini', $record->getModel());
        $this->assertSame('reply_generation', $record->getPurpose());
        $this->assertSame(100, $record->getPromptTokens());
        $this->assertSame(50, $record->getCompletionTokens());
        $this->assertEqualsWithDelta(0.00045, $record->getEstimatedCostUsd(), 0.000001);
        $this->assertSame('conv-123', $record->getConversationId());
        $this->assertInstanceOf(\DateTimeImmutable::class, $record->getCreatedAt());
    }

    public function testConstructionWithoutConversationId(): void
    {
        $record = new LlmUsageRecord(
            provider: 'anthropic',
            model: 'claude-haiku-4-5-20251001',
            purpose: 'classification',
            promptTokens: 200,
            completionTokens: 30,
            estimatedCostUsd: 0.00028
        );

        $this->assertNull($record->getConversationId());
        $this->assertSame(0, $record->getId());
    }

    public function testNegativeTokensThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Token counts cannot be negative');

        new LlmUsageRecord('openai', 'gpt-4o-mini', 'test', -1, 50, 0.001);
    }

    public function testNegativeCompletionTokensThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new LlmUsageRecord('openai', 'gpt-4o-mini', 'test', 50, -1, 0.001);
    }

    public function testNegativeCostThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Estimated cost cannot be negative');

        new LlmUsageRecord('openai', 'gpt-4o-mini', 'test', 100, 50, -0.001);
    }

    public function testZeroTokensAndCostIsValid(): void
    {
        $record = new LlmUsageRecord('mock', 'mock', 'test', 0, 0, 0.0);

        $this->assertSame(0, $record->getPromptTokens());
        $this->assertSame(0, $record->getCompletionTokens());
        $this->assertEqualsWithDelta(0.0, $record->getEstimatedCostUsd(), 0.000001);
    }

    public function testCostStoredWithSixDecimals(): void
    {
        $record = new LlmUsageRecord('openai', 'gpt-4o-mini', 'test', 100, 50, 0.123456789);

        // Should be truncated to 6 decimals
        $this->assertEqualsWithDelta(0.123457, $record->getEstimatedCostUsd(), 0.000001);
    }
}
