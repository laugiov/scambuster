<?php

declare(strict_types=1);

namespace App\Application\LLM;

/**
 * Estimates LLM call cost based on model and token usage.
 *
 * Pricing is per 1K tokens (input/output), updated 2026-03-22.
 * Ollama and mock providers always return $0.
 */
final class CostEstimator
{
    /**
     * Pricing per 1,000 tokens [input, output] in USD.
     * Last verified: 2026-03-22.
     *
     * @var array<string, array{input: float, output: float}>
     */
    private const PRICING = [
        // OpenAI
        'gpt-4o-mini' => ['input' => 0.00015, 'output' => 0.0006],
        'gpt-4o-mini-2024-07-18' => ['input' => 0.00015, 'output' => 0.0006],
        'gpt-4o' => ['input' => 0.0025, 'output' => 0.01],
        'gpt-4o-2024-08-06' => ['input' => 0.0025, 'output' => 0.01],

        // Anthropic
        'claude-haiku-4-5-20251001' => ['input' => 0.0008, 'output' => 0.004],
        'claude-sonnet-4-6-20250514' => ['input' => 0.003, 'output' => 0.015],
        'claude-opus-4-6-20250610' => ['input' => 0.015, 'output' => 0.075],
    ];

    /**
     * Estimate the cost of an LLM call in USD.
     *
     * @return float Estimated cost in USD (6 decimal precision)
     */
    public function estimate(string $provider, string $model, int $promptTokens, int $completionTokens): float
    {
        // Local providers (ollama, mock) have zero cost
        if (in_array($provider, ['ollama', 'mock'], true)) {
            return 0.0;
        }

        $pricing = self::PRICING[$model] ?? null;

        if ($pricing === null) {
            // Unknown model: use conservative estimate (gpt-4o pricing)
            $pricing = ['input' => 0.0025, 'output' => 0.01];
        }

        $inputCost = ($promptTokens / 1000.0) * $pricing['input'];
        $outputCost = ($completionTokens / 1000.0) * $pricing['output'];

        return round($inputCost + $outputCost, 6);
    }

    /**
     * Approximate token count from text length.
     * Used when the API does not return exact token counts.
     *
     * Approximation: 1 token ~ 4 characters (English).
     */
    public function approximateTokens(string $text): int
    {
        return max(1, (int) ceil(strlen($text) / 4));
    }
}
