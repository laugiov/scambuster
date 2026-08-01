<?php

declare(strict_types=1);

namespace App\Tests\Unit\Application\LLM;

use App\Application\LLM\FallbackProvider;
use PHPUnit\Framework\TestCase;

class FallbackProviderTest extends TestCase
{
    private FallbackProvider $provider;

    protected function setUp(): void
    {
        $this->provider = new FallbackProvider();
    }

    public function testFrenchFallbackIsFrench(): void
    {
        self::assertStringContainsString('Merci', $this->provider->getFallback('fr'));
    }

    public function testSpanishFallbackIsSpanish(): void
    {
        self::assertStringContainsString('Gracias', $this->provider->getFallback('es'));
    }

    public function testGermanFallbackIsGerman(): void
    {
        self::assertStringContainsString('Danke', $this->provider->getFallback('de'));
    }

    public function testCaseInsensitive(): void
    {
        self::assertStringContainsString('Merci', $this->provider->getFallback('FR'));
    }

    public function testNullEmptyUnknownLanguageUseUniversalPool(): void
    {
        // With the same seed + turn, unknown/empty/null all resolve to the universal pool.
        self::assertSame($this->provider->getFallback(null, 'k', 1), $this->provider->getFallback('', 'k', 1));
        self::assertSame($this->provider->getFallback(null, 'k', 1), $this->provider->getFallback('zh', 'k', 1));
    }

    public function testNoSeedNoTurnIsStable(): void
    {
        $a = $this->provider->getFallback('en');
        $b = $this->provider->getFallback('en', null);
        $c = $this->provider->getFallback('en', null, 0);

        self::assertSame($a, $b);
        self::assertSame($a, $c);
        self::assertNotSame('', $a);
    }

    public function testSameSeedAndTurnIsDeterministic(): void
    {
        self::assertSame(
            $this->provider->getFallback('en', 'conv-123', 4),
            $this->provider->getFallback('en', 'conv-123', 4),
        );
    }

    public function testConsecutiveTurnsNeverRepeatInSameConversation(): void
    {
        // Round-robin: the index advances by exactly one each turn, so within a
        // single conversation two successive fallbacks are always different.
        $previous = null;

        for ($turn = 0; $turn < 12; $turn++) {
            $current = $this->provider->getFallback('en', 'conv-fixed', $turn);
            self::assertNotSame($previous, $current, "Turn {$turn} repeated the previous fallback");
            $previous = $current;
        }
    }

    public function testPoolYieldsAtLeastFiveDistinctVariants(): void
    {
        $seen = [];

        // Same conversation, walking the turns exercises the whole pool.
        for ($turn = 0; $turn < 40; $turn++) {
            $seen[$this->provider->getFallback('en', 'conv-fixed', $turn)] = true;
        }

        // A single hardcoded fallback would give exactly 1 distinct value.
        self::assertGreaterThanOrEqual(5, count($seen), 'English pool must offer >=5 phrasings');
    }

    public function testDifferentConversationsCanGetDifferentFallbacks(): void
    {
        // Same turn, different conversation seeds → the per-conversation offset
        // must be able to shift the phrasing (kills the cross-conversation tell).
        $a = $this->provider->getFallback('en', 'convA', 1);
        $found = false;

        foreach (['convB', 'convC', 'convD', 'convE', 'convF'] as $seed) {
            if ($this->provider->getFallback('en', $seed, 1) !== $a) {
                $found = true;
                break;
            }
        }

        self::assertTrue($found, 'Different conversation seeds must be able to yield different fallbacks');
    }

    public function testSevenLanguagesSupported(): void
    {
        $languages = $this->provider->getSupportedLanguages();

        self::assertCount(7, $languages);
        foreach (['en', 'fr', 'es', 'de', 'pt', 'it', 'nl'] as $lang) {
            self::assertContains($lang, $languages);
        }
    }

    public function testIsLanguageSupported(): void
    {
        self::assertTrue($this->provider->isLanguageSupported('en'));
        self::assertTrue($this->provider->isLanguageSupported('fr'));
        self::assertFalse($this->provider->isLanguageSupported('zh'));
    }
}
