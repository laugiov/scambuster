<?php

declare(strict_types=1);

namespace App\Application\Guard;

/**
 * Loads the frozen canary baseline — the gate's trust anchor — as a validated aggregate array.
 * A single implementation shared by both façades (the `guard:check` CLI and the async worker)
 * so the integrity discipline can never drift between them.
 *
 * It fails CLOSED (throws {@see CanaryBaselineException}) on a missing, unreadable, malformed,
 * or mis-shaped baseline, and — critically — when a `.sha256` companion is present but does not
 * match: a hand-edited baseline would silently lower the bar, so it is rejected. A missing
 * companion is tolerated (older baselines / ad-hoc runs).
 */
final readonly class CanaryBaselineProvider
{
    public function __construct(
        private string $baselinePath,
    ) {
    }

    /**
     * @param string|null $path override path; null uses the configured default
     *
     * @throws CanaryBaselineException
     *
     * @return array<string, mixed> the decoded, integrity-checked baseline aggregate
     */
    public function load(?string $path = null): array
    {
        $path ??= $this->baselinePath;

        if ($path === '' || !is_file($path)) {
            throw new CanaryBaselineException("Baseline not found: {$path}");
        }

        $raw = file_get_contents($path);

        if ($raw === false) {
            throw new CanaryBaselineException("Baseline unreadable: {$path}");
        }

        $this->assertIntegrity($path, $raw);

        try {
            $decoded = json_decode($raw, true, 512, \JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new CanaryBaselineException("Baseline is not valid JSON ({$path}): {$e->getMessage()}");
        }

        if (!is_array($decoded) || !isset($decoded['meta'], $decoded['violation_rates'])) {
            throw new CanaryBaselineException("Not a canary baseline (missing meta/violation_rates): {$path}");
        }

        /** @var array<string, mixed> $decoded */
        return $decoded;
    }

    /**
     * Verify the baseline against its `.sha256` companion when present. A mismatch means the
     * trust anchor was altered outside the reviewed `guard:baseline` path — fail closed.
     */
    private function assertIntegrity(string $path, string $raw): void
    {
        $shaPath = $path . '.sha256';

        if (!is_file($shaPath)) {
            return;
        }

        $rawSha = file_get_contents($shaPath);

        if ($rawSha === false) {
            throw new CanaryBaselineException("Baseline .sha256 unreadable: {$shaPath}");
        }

        $expected = strtolower(trim(explode(' ', trim($rawSha))[0]));
        $actual = hash('sha256', $raw);

        if ($expected !== $actual) {
            throw new CanaryBaselineException("Baseline integrity check FAILED — {$path} does not match its .sha256 (regenerate with the guard-baseline make target, do not hand-edit).");
        }
    }
}
