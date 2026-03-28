<?php

declare(strict_types=1);

namespace App\Domain\LLM;

/**
 * Trace of a single pipeline component's participation in a reply generation.
 *
 * Captures: what ran, how long it took, what it produced, and why it was skipped/failed.
 */
final class ComponentTrace
{
    public function __construct(
        public readonly string $name,
        public readonly string $status,
        public readonly float $durationMs,
        public readonly ?float $cost = null,
        /** @var array<string, mixed> */
        public readonly array $output = [],
        public readonly ?string $error = null,
        public readonly ?string $skipReason = null,
    ) {
    }

    /**
     * @param array<string, mixed> $output
     */
    public static function ran(string $name, float $durationMs, array $output = [], ?float $cost = null): self
    {
        return new self($name, 'ran', $durationMs, $cost, $output);
    }

    public static function skipped(string $name, string $reason): self
    {
        return new self($name, 'skipped', 0.0, null, [], null, $reason);
    }

    public static function error(string $name, string $errorMessage, float $durationMs = 0.0): self
    {
        return new self($name, 'error', $durationMs, null, [], $errorMessage);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $data = [
            'name' => $this->name,
            'status' => $this->status,
            'duration_ms' => round($this->durationMs, 2),
        ];

        if ($this->cost !== null) {
            $data['cost'] = $this->cost;
        }

        if (!empty($this->output)) {
            $data['output'] = $this->output;
        }

        if ($this->error !== null) {
            $data['error'] = $this->error;
        }

        if ($this->skipReason !== null) {
            $data['skip_reason'] = $this->skipReason;
        }

        return $data;
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        /** @var string $name */
        $name = $data['name'] ?? 'unknown';
        /** @var string $status */
        $status = $data['status'] ?? 'ran';
        /** @var float $durationMs */
        $durationMs = $data['duration_ms'] ?? 0.0;

        return new self(
            name: $name,
            status: $status,
            durationMs: (float) $durationMs,
            cost: isset($data['cost']) && \is_numeric($data['cost']) ? (float) $data['cost'] : null,
            output: (array) ($data['output'] ?? []),
            error: isset($data['error']) && \is_string($data['error']) ? $data['error'] : null,
            skipReason: isset($data['skip_reason']) && \is_string($data['skip_reason']) ? $data['skip_reason'] : null,
        );
    }
}
