<?php

declare(strict_types=1);

namespace App\Domain\Validation;

/**
 * Result of an OperationalLeakageDetector check.
 *
 * Indicates whether the LLM-generated text leaks operational information
 * about the platform (n8n, ScamBuster, orchestrator, etc.).
 *
 * Immutable value object. Created by the second-LLM detector and read
 * by the orchestrator's retry loop.
 */
final readonly class LeakageDetectionResult
{
    /**
     * @param array<int, string> $signals Optional list of matched terms or signal labels
     */
    public function __construct(
        public bool $leakDetected,
        public ?string $reason = null,
        public array $signals = [],
    ) {
    }
}
