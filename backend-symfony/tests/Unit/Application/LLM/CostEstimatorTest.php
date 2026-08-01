<?php

declare(strict_types=1);

namespace App\Tests\Unit\Application\LLM;

use App\Application\LLM\CostEstimator;
use PHPUnit\Framework\TestCase;

class CostEstimatorTest extends TestCase
{
    private CostEstimator $estimator;

    protected function setUp(): void
    {
        $this->estimator = new CostEstimator();
    }

    public function testEstimateGpt4oMini(): void
    {
        // gpt-4o-mini: $0.00015/1K input, $0.0006/1K output
        $cost = $this->estimator->estimate('openai', 'gpt-4o-mini', 1000, 500);

        // Expected: (1000/1000 * 0.00015) + (500/1000 * 0.0006) = 0.00015 + 0.0003 = 0.00045
        $this->assertEqualsWithDelta(0.00045, $cost, 0.000001);
    }

    public function testEstimateGpt4o(): void
    {
        // gpt-4o: $0.0025/1K input, $0.01/1K output
        $cost = $this->estimator->estimate('openai', 'gpt-4o', 100, 50);

        // Expected: (100/1000 * 0.0025) + (50/1000 * 0.01) = 0.00025 + 0.0005 = 0.00075
        $this->assertEqualsWithDelta(0.00075, $cost, 0.000001);
    }

    public function testEstimateClaudeHaiku(): void
    {
        // claude-haiku: $0.0008/1K input, $0.004/1K output
        $cost = $this->estimator->estimate('anthropic', 'claude-haiku-4-5-20251001', 200, 100);

        // Expected: (200/1000 * 0.0008) + (100/1000 * 0.004) = 0.00016 + 0.0004 = 0.00056
        $this->assertEqualsWithDelta(0.00056, $cost, 0.000001);
    }

    public function testEstimateOllamaIsZero(): void
    {
        $cost = $this->estimator->estimate('ollama', 'llama3', 5000, 2000);
        $this->assertSame(0.0, $cost);
    }

    public function testEstimateMockIsZero(): void
    {
        $cost = $this->estimator->estimate('mock', 'anything', 1000, 500);
        $this->assertSame(0.0, $cost);
    }

    public function testEstimateUnknownModelUsesConservativePrice(): void
    {
        // Unknown model falls back to gpt-4o pricing
        $cost = $this->estimator->estimate('openai', 'gpt-5-future', 1000, 1000);

        // Expected: gpt-4o rates (0.0025 + 0.01) = 0.0125
        $this->assertEqualsWithDelta(0.0125, $cost, 0.000001);
    }

    public function testEstimateZeroTokens(): void
    {
        $cost = $this->estimator->estimate('openai', 'gpt-4o-mini', 0, 0);
        $this->assertSame(0.0, $cost);
    }

    public function testApproximateTokens(): void
    {
        // 100 chars -> ~25 tokens
        $tokens = $this->estimator->approximateTokens(str_repeat('a', 100));
        $this->assertSame(25, $tokens);
    }

    public function testApproximateTokensMinimumOne(): void
    {
        $tokens = $this->estimator->approximateTokens('');
        $this->assertSame(1, $tokens);
    }

    public function testEstimateReturnsAtMost6Decimals(): void
    {
        $cost = $this->estimator->estimate('openai', 'gpt-4o-mini', 1, 1);
        $decimals = strlen(explode('.', (string) $cost)[1] ?? '');
        $this->assertLessThanOrEqual(6, $decimals);
    }
}
