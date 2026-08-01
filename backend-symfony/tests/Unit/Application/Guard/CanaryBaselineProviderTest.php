<?php

declare(strict_types=1);

namespace App\Tests\Unit\Application\Guard;

use App\Application\Guard\CanaryBaselineException;
use App\Application\Guard\CanaryBaselineProvider;
use PHPUnit\Framework\TestCase;

final class CanaryBaselineProviderTest extends TestCase
{
    private string $dir = '';

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/guard_baseline_provider_' . bin2hex(random_bytes(6));
        mkdir($this->dir, 0777, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/*') ?: [] as $f) {
            @unlink($f);
        }
        @rmdir($this->dir);
    }

    /**
     * @param array<string, mixed> $baseline
     */
    private function freeze(array $baseline, bool $withSha = true): string
    {
        $path = $this->dir . '/baseline.json';
        $json = json_encode($baseline, \JSON_PRETTY_PRINT | \JSON_THROW_ON_ERROR) . "\n";
        file_put_contents($path, $json);

        if ($withSha) {
            file_put_contents($path . '.sha256', hash('sha256', $json) . '  baseline.json' . "\n");
        }

        return $path;
    }

    /**
     * @return array<string, mixed>
     */
    private function validBaseline(): array
    {
        return [
            'metrics' => ['approved_rate' => 1.0, 'fallback_rate' => 0.0, 'attempts_avg' => 1.9],
            'violation_rates' => ['payment_token' => 0.33],
            'meta' => ['oracle_fingerprint' => 'abc123', 'out_texts_scored' => 85, 'runs' => 85, 'errors' => 0],
        ];
    }

    public function testLoadsAValidIntegrityCheckedBaseline(): void
    {
        $path = $this->freeze($this->validBaseline());
        $loaded = (new CanaryBaselineProvider($path))->load();

        self::assertArrayHasKey('meta', $loaded);
        self::assertArrayHasKey('violation_rates', $loaded);
    }

    public function testMissingFileFailsClosed(): void
    {
        $this->expectException(CanaryBaselineException::class);
        (new CanaryBaselineProvider($this->dir . '/nope.json'))->load();
    }

    public function testTamperedBaselineFailsClosed(): void
    {
        $path = $this->freeze($this->validBaseline());
        // Hand-edit the baseline without regenerating its .sha256.
        file_put_contents($path, (string) file_get_contents($path) . ' ');

        $this->expectException(CanaryBaselineException::class);
        $this->expectExceptionMessageMatches('/integrity/i');
        (new CanaryBaselineProvider($path))->load();
    }

    public function testMissingShaCompanionIsTolerated(): void
    {
        $path = $this->freeze($this->validBaseline(), withSha: false);
        $loaded = (new CanaryBaselineProvider($path))->load();

        self::assertArrayHasKey('meta', $loaded);
    }

    public function testInvalidJsonFailsClosed(): void
    {
        $path = $this->dir . '/baseline.json';
        file_put_contents($path, '{not json');

        $this->expectException(CanaryBaselineException::class);
        (new CanaryBaselineProvider($path))->load();
    }

    public function testWrongShapeFailsClosed(): void
    {
        $path = $this->freeze(['not' => 'a baseline']);

        $this->expectException(CanaryBaselineException::class);
        (new CanaryBaselineProvider($path))->load();
    }

    public function testExplicitPathOverridesTheDefault(): void
    {
        $path = $this->freeze($this->validBaseline());
        $loaded = (new CanaryBaselineProvider($this->dir . '/some-default.json'))->load($path);

        self::assertArrayHasKey('meta', $loaded);
    }
}
