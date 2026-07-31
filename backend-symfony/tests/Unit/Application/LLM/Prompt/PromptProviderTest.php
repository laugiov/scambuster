<?php

declare(strict_types=1);

namespace App\Tests\Unit\Application\LLM\Prompt;

use App\Application\LLM\Prompt\PromptProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

final class PromptProviderTest extends TestCase
{
    private string $dir;
    private PromptProvider $provider;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/scambuster_prompts_' . bin2hex(random_bytes(6));
        mkdir($this->dir, 0777, true);
        $this->provider = new PromptProvider($this->dir, new NullLogger());
    }

    protected function tearDown(): void
    {
        // Clean files + any stray dirs created by a test.
        foreach (glob($this->dir . '/*') ?: [] as $path) {
            if (is_dir($path)) {
                @rmdir($path);
            } else {
                @unlink($path);
            }
        }
        @rmdir($this->dir);
    }

    private function writeOverride(string $key, string $content): void
    {
        file_put_contents($this->dir . '/' . $key . '.txt', $content);
    }

    // ─── resolution: default vs override ────────────────────────────────

    public function testReturnsInlineDefaultWhenNoOverrideFile(): void
    {
        $out = $this->provider->resolve('greeting', ['{{NAME}}' => 'Bob'], 'Hello {{NAME}}, from default.');

        self::assertSame('Hello Bob, from default.', $out);
    }

    public function testReturnsOverrideWhenPresentAndValid(): void
    {
        $this->writeOverride('greeting', 'Hi {{NAME}}, from override.');

        $out = $this->provider->resolve('greeting', ['{{NAME}}' => 'Bob'], 'Hello {{NAME}}, from default.', ['{{NAME}}']);

        self::assertSame('Hi Bob, from override.', $out);
    }

    public function testOverrideUsedWhenNoRequiredPlaceholdersDeclared(): void
    {
        $this->writeOverride('greeting', 'Totally custom text, no tokens.');

        $out = $this->provider->resolve('greeting', ['{{NAME}}' => 'Bob'], 'default');

        self::assertSame('Totally custom text, no tokens.', $out);
    }

    // ─── fail-to-default (the G-1 fix) ──────────────────────────────────

    public function testFallsBackToDefaultWhenOverrideMissesARequiredPlaceholder(): void
    {
        $this->writeOverride('greeting', 'Hi there, from override.'); // no {{NAME}}

        $out = $this->provider->resolve('greeting', ['{{NAME}}' => 'Bob'], 'Hello {{NAME}}, from default.', ['{{NAME}}']);

        self::assertSame('Hello Bob, from default.', $out);
    }

    public function testFallsBackWhenAnyOneOfSeveralRequiredPlaceholdersMissing(): void
    {
        $this->writeOverride('x', 'has {{A}} but not the other one');

        $out = $this->provider->resolve('x', ['{{A}}' => '1', '{{B}}' => '2'], 'DEFAULT {{A}} {{B}}', ['{{A}}', '{{B}}']);

        self::assertSame('DEFAULT 1 2', $out);
    }

    public function testFallToDefaultLogsAWarning(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('warning')
            ->with(self::stringContains('missing required placeholder'), self::callback(
                static fn (array $ctx): bool => ($ctx['key'] ?? null) === 'x' && ($ctx['missing'] ?? null) === '{{B}}',
            ));
        $provider = new PromptProvider($this->dir, $logger);
        $this->writeOverride('x', 'only {{A}} here');

        $provider->resolve('x', ['{{A}}' => '1'], 'default', ['{{A}}', '{{B}}']);
    }

    // ─── substitution semantics (must match the prior inline str_replace) ─

    public function testSubstitutesOnlyKnownTokensLeavesUnknownLiteral(): void
    {
        $out = $this->provider->resolve('x', ['{{A}}' => '1'], 'a={{A}} b={{B}}');

        self::assertSame('a=1 b={{B}}', $out);
    }

    public function testSubstitutionUsesStrReplaceSemanticsNotStrtr(): void
    {
        // The prior enricher code did str_replace(array_keys, array_values, $t):
        // tokens are applied left-to-right, and a replacement done in an earlier
        // pass is itself subject to a later token's replacement. This locks that
        // exact behaviour so the ContextualEnricher re-point is byte-identical.
        $vars = ['{{A}}' => 'x{{B}}y', '{{B}}' => 'Z'];
        $template = 'start {{A}} end';

        $out = $this->provider->resolve('nofile', $vars, $template);

        self::assertSame(
            str_replace(array_keys($vars), array_values($vars), $template),
            $out,
        );
        // Concretely: {{A}} -> "x{{B}}y", then {{B}} -> "Z" cascades into it.
        self::assertSame('start xZy end', $out);
    }

    public function testEmptyVarsMapReturnsTemplateUnchanged(): void
    {
        $out = $this->provider->resolve('nofile', [], 'unchanged {{TOKEN}} text');

        self::assertSame('unchanged {{TOKEN}} text', $out);
    }

    // ─── key sanitization (path-traversal / injection safety) ───────────

    /**
     * @dataProvider unsafeKeyProvider
     */
    public function testUnsafeKeyNeverResolvesAnOverride(string $unsafeKey): void
    {
        // A real file exists that a naive path join could reach.
        $this->writeOverride('evil', 'SHOULD NEVER LOAD');

        $out = $this->provider->resolve($unsafeKey, [], 'DEFAULT');

        self::assertSame('DEFAULT', $out);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function unsafeKeyProvider(): array
    {
        return [
            'parent traversal' => ['../evil'],
            'subpath' => ['a/evil'],
            'empty' => [''],
            'uppercase' => ['Evil'],
            'space' => ['has space'],
            'dot' => ['a.b'],
            'dash' => ['a-b'],
            'backslash' => ['a\\b'],
            'null byte' => ["evil\0"],
            'leading slash' => ['/evil'],
        ];
    }

    /**
     * @dataProvider safeKeyProvider
     */
    public function testSafeKeyResolvesItsOverride(string $safeKey): void
    {
        $this->writeOverride($safeKey, "loaded {$safeKey}");

        $out = $this->provider->resolve($safeKey, [], 'DEFAULT');

        self::assertSame("loaded {$safeKey}", $out);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function safeKeyProvider(): array
    {
        return [
            'simple' => ['base_rules'],
            'snake' => ['contextual_enrichment'],
            'single char' => ['a'],
            'alnum underscore' => ['a1_b2_c3'],
            'digits' => ['scam42'],
        ];
    }

    // ─── never throws / degrades safely ─────────────────────────────────

    public function testNeverThrowsWhenPathIsADirectory(): void
    {
        mkdir($this->dir . '/adir.txt', 0777, true);

        $out = $this->provider->resolve('adir', [], 'DEFAULT');

        self::assertSame('DEFAULT', $out);
    }

    public function testEmptyOverrideFileFallsBackToDefault(): void
    {
        $this->writeOverride('empty', '');

        self::assertSame('DEFAULT', $this->provider->resolve('empty', [], 'DEFAULT'));
    }

    public function testMissingPromptDirDegradesToDefault(): void
    {
        $provider = new PromptProvider($this->dir . '/does_not_exist', new NullLogger());

        self::assertSame('DEFAULT', $provider->resolve('anything', [], 'DEFAULT'));
    }

    public function testResolveIsDeterministicAcrossCalls(): void
    {
        $this->writeOverride('greeting', 'Hi {{NAME}}');

        $a = $this->provider->resolve('greeting', ['{{NAME}}' => 'Bob'], 'default', ['{{NAME}}']);
        $b = $this->provider->resolve('greeting', ['{{NAME}}' => 'Bob'], 'default', ['{{NAME}}']);

        self::assertSame($a, $b);
        self::assertSame('Hi Bob', $a);
    }
}
