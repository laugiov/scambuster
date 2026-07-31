<?php

declare(strict_types=1);

namespace App\Tests\Unit\Application\LLM\Prompt;

use App\Application\LLM\Prompt\PromptOverrideSource;
use App\Application\LLM\Prompt\PromptProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Locks the DB-override layer of PromptProvider: precedence DB → file → default, with
 * per-candidate validation and fail-safe handling of the source.
 */
final class PromptProviderOverrideSourceTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/scambuster_dbsrc_' . bin2hex(random_bytes(6));
        mkdir($this->dir, 0777, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/*') ?: [] as $p) {
            @unlink($p);
        }
        @rmdir($this->dir);
    }

    private function source(?string $body, bool $throws = false): PromptOverrideSource
    {
        return new class($body, $throws) implements PromptOverrideSource {
            public function __construct(private ?string $body, private bool $throws)
            {
            }

            public function get(string $key): ?string
            {
                if ($this->throws) {
                    throw new \RuntimeException('db down');
                }

                return $this->body;
            }
        };
    }

    private function writeFile(string $key, string $content): void
    {
        file_put_contents($this->dir . '/' . $key . '.txt', $content);
    }

    public function testDbOverrideWinsOverFileAndDefault(): void
    {
        $this->writeFile('k', 'FILE {{A}}');
        $provider = new PromptProvider($this->dir, new NullLogger(), $this->source('DB {{A}}'));

        self::assertSame('DB x', $provider->resolve('k', ['{{A}}' => 'x'], 'DEFAULT {{A}}', ['{{A}}']));
    }

    public function testFallsThroughToFileWhenNoDbOverride(): void
    {
        $this->writeFile('k', 'FILE {{A}}');
        $provider = new PromptProvider($this->dir, new NullLogger(), $this->source(null));

        self::assertSame('FILE x', $provider->resolve('k', ['{{A}}' => 'x'], 'DEFAULT', ['{{A}}']));
    }

    public function testFallsThroughToDefaultWhenNeitherDbNorFile(): void
    {
        $provider = new PromptProvider($this->dir, new NullLogger(), $this->source(null));

        self::assertSame('DEFAULT x', $provider->resolve('k', ['{{A}}' => 'x'], 'DEFAULT {{A}}', ['{{A}}']));
    }

    public function testInvalidDbOverrideFallsThroughToValidFile(): void
    {
        // DB override drops the required token → skip it → use the valid file.
        $this->writeFile('k', 'FILE has {{A}}');
        $provider = new PromptProvider($this->dir, new NullLogger(), $this->source('DB missing the token'));

        self::assertSame('FILE has x', $provider->resolve('k', ['{{A}}' => 'x'], 'DEFAULT', ['{{A}}']));
    }

    public function testInvalidDbAndInvalidFileFallThroughToDefault(): void
    {
        $this->writeFile('k', 'FILE also missing');
        $provider = new PromptProvider($this->dir, new NullLogger(), $this->source('DB missing'));

        self::assertSame('DEFAULT', $provider->resolve('k', [], 'DEFAULT', ['{{A}}']));
    }

    public function testSourceThrowIsFailSafeAndFallsThroughToFile(): void
    {
        $this->writeFile('k', 'FILE {{A}}');
        $provider = new PromptProvider($this->dir, new NullLogger(), $this->source(null, throws: true));

        self::assertSame('FILE x', $provider->resolve('k', ['{{A}}' => 'x'], 'DEFAULT', ['{{A}}']));
    }

    public function testEmptyDbBodyIsTreatedAsNoOverride(): void
    {
        $this->writeFile('k', 'FILE {{A}}');
        $provider = new PromptProvider($this->dir, new NullLogger(), $this->source(''));

        self::assertSame('FILE x', $provider->resolve('k', ['{{A}}' => 'x'], 'DEFAULT', ['{{A}}']));
    }

    public function testNullSourceIsByteIdenticalToFileFirstBehaviour(): void
    {
        // The default (no source) path must equal the file→default behaviour.
        $this->writeFile('k', 'FILE {{A}}');
        $withNullSource = new PromptProvider($this->dir, new NullLogger(), null);
        $withoutSourceArg = new PromptProvider($this->dir, new NullLogger());

        self::assertSame(
            $withoutSourceArg->resolve('k', ['{{A}}' => 'x'], 'DEFAULT', ['{{A}}']),
            $withNullSource->resolve('k', ['{{A}}' => 'x'], 'DEFAULT', ['{{A}}']),
        );
        self::assertSame('FILE x', $withNullSource->resolve('k', ['{{A}}' => 'x'], 'DEFAULT', ['{{A}}']));
    }
}
