<?php

declare(strict_types=1);

namespace App\Tests\Unit\Application\Guard;

use App\Application\Guard\SafetyInvariantOracle;
use PHPUnit\Framework\TestCase;

/**
 * Build-time lock: the committed baseline must stay in sync with the committed oracle. If the
 * oracle's rule set changes without regenerating the baseline, every real validation would fail
 * CLOSED at runtime ("oracle rule set changed"). This surfaces that drift at CI instead — a
 * changed oracle forces regenerating (and reviewing) the frozen baseline in the same commit.
 */
final class GuardBaselineIntegrityTest extends TestCase
{
    private function baselinePath(): string
    {
        return \dirname(__DIR__, 4) . '/tests/Smoke/guard-baseline.json';
    }

    public function testFrozenBaselineFingerprintMatchesCurrentOracle(): void
    {
        $path = $this->baselinePath();
        self::assertFileExists($path, 'frozen guard baseline is missing');

        /** @var array{meta?: array{oracle_fingerprint?: string}} $baseline */
        $baseline = json_decode((string) file_get_contents($path), true, 512, \JSON_THROW_ON_ERROR);

        self::assertSame(
            SafetyInvariantOracle::fingerprint(),
            $baseline['meta']['oracle_fingerprint'] ?? null,
            'Frozen guard-baseline.json is stale for the current oracle rule set — regenerate it '
            . '(re-score the existing smoke summary, or `make guard-baseline`) and re-freeze it.',
        );
    }

    public function testFrozenBaselineChecksumMatchesContent(): void
    {
        $path = $this->baselinePath();
        $shaPath = $path . '.sha256';
        self::assertFileExists($shaPath, 'baseline .sha256 companion is missing');

        $actual = hash('sha256', (string) file_get_contents($path));

        self::assertStringStartsWith(
            $actual,
            trim((string) file_get_contents($shaPath)),
            'guard-baseline.json.sha256 is stale — regenerate it alongside the baseline.',
        );
    }
}
