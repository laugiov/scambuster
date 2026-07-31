<?php

declare(strict_types=1);

namespace App\Domain\LLM;

/**
 * Complete trace of one reply generation pipeline execution.
 *
 * Captures every component's participation with timing, cost, and output.
 * Stored in message.headers JSON as 'pipeline_trace'.
 */
final class PipelineTrace
{
    /**
     * Components traced at the Orchestrator level (always expected).
     * Sub-components (language_detector, context_analyzer, reciprocity_manager)
     * run inside prompt_builder and are included in its timing.
     *
     * @var string[]
     */
    private const EXPECTED_COMPONENTS = [
        'prompt_builder',
        'policy_guard',
        'reply_validator',
        'ioc_scorer',
    ];

    /** @var string[] Components that may be legitimately skipped */
    private const SKIPPABLE_COMPONENTS = [];

    /** @var ComponentTrace[] */
    private array $components = [];

    public function __construct(
        public readonly string $conversationId,
        public readonly string $persona,
        public readonly string $scamType,
        public readonly string $detectedLanguage = 'en',
        public int $attempts = 0,
        public bool $approved = false,
        public bool $fallbackUsed = false,
        public float $totalCost = 0.0,
    ) {
    }

    public function addComponent(ComponentTrace $trace): void
    {
        $this->components[] = $trace;

        if ($trace->cost !== null) {
            $this->totalCost += $trace->cost;
        }
    }

    public function getTotalDurationMs(): float
    {
        $total = 0.0;

        foreach ($this->components as $c) {
            $total += $c->durationMs;
        }

        return round($total, 2);
    }

    public function getComponentByName(string $name): ?ComponentTrace
    {
        foreach ($this->components as $c) {
            if ($c->name === $name) {
                return $c;
            }
        }

        return null;
    }

    /**
     * List expected components that didn't run and weren't explicitly skipped.
     *
     * @return string[]
     */
    public function getMissingComponents(): array
    {
        $recorded = array_map(fn (ComponentTrace $c): string => $c->name, $this->components);
        $allExpected = array_merge(self::EXPECTED_COMPONENTS, self::SKIPPABLE_COMPONENTS);

        return array_values(array_diff($allExpected, $recorded));
    }

    /**
     * True if any component errored or an expected component is missing.
     */
    public function hasAlerts(): bool
    {
        foreach ($this->components as $c) {
            if ($c->status === 'error') {
                return true;
            }
        }

        // Check for missing non-skippable components
        $recorded = array_map(fn (ComponentTrace $c): string => $c->name, $this->components);

        foreach (self::EXPECTED_COMPONENTS as $expected) {
            if (!in_array($expected, $recorded, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'conversation_id' => $this->conversationId,
            'persona' => $this->persona,
            'scam_type' => $this->scamType,
            'detected_language' => $this->detectedLanguage,
            'total_duration_ms' => $this->getTotalDurationMs(),
            'total_cost' => round($this->totalCost, 6),
            'attempts' => $this->attempts,
            'approved' => $this->approved,
            'fallback_used' => $this->fallbackUsed,
            'component_count' => count($this->components),
            'has_alerts' => $this->hasAlerts(),
            'components' => array_map(fn (ComponentTrace $c): array => $c->toArray(), $this->components),
            'created_at' => date(\DATE_ATOM),
        ];
    }

    /**
     * Compact summary for list API (no component details).
     *
     * @return array<string, mixed>
     */
    public function toSummary(): array
    {
        return [
            'conversation_id' => $this->conversationId,
            'persona' => $this->persona,
            'scam_type' => $this->scamType,
            'total_duration_ms' => $this->getTotalDurationMs(),
            'total_cost' => round($this->totalCost, 6),
            'attempts' => $this->attempts,
            'approved' => $this->approved,
            'fallback_used' => $this->fallbackUsed,
            'component_count' => count($this->components),
            'has_alerts' => $this->hasAlerts(),
        ];
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        /** @var string $convId */
        $convId = $data['conversation_id'] ?? '';
        /** @var string $persona */
        $persona = $data['persona'] ?? '';
        /** @var string $scamType */
        $scamType = $data['scam_type'] ?? '';
        /** @var string $lang */
        $lang = $data['detected_language'] ?? 'en';

        $trace = new self(
            conversationId: $convId,
            persona: $persona,
            scamType: $scamType,
            detectedLanguage: $lang,
            attempts: (int) (is_numeric($data['attempts'] ?? null) ? $data['attempts'] : 0), // @phpstan-ignore-line
            approved: (bool) ($data['approved'] ?? false),
            fallbackUsed: (bool) ($data['fallback_used'] ?? false),
            totalCost: (float) (is_numeric($data['total_cost'] ?? null) ? $data['total_cost'] : 0), // @phpstan-ignore-line
        );

        /** @var array<int, array<string, mixed>> $components */
        $components = $data['components'] ?? [];

        foreach ($components as $componentData) {
            $trace->components[] = ComponentTrace::fromArray($componentData);
        }

        return $trace;
    }

    /**
     * @return ComponentTrace[]
     */
    public function getComponents(): array
    {
        return $this->components;
    }
}
