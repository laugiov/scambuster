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

    public function testEnglishFallback(): void
    {
        $result = $this->provider->getFallback('en');

        $this->assertStringContainsString('Thank you', $result);
        $this->assertStringContainsString('message', $result);
    }

    public function testFrenchFallback(): void
    {
        $result = $this->provider->getFallback('fr');

        $this->assertStringContainsString('Merci', $result);
        $this->assertStringContainsString('message', $result);
    }

    public function testSpanishFallback(): void
    {
        $result = $this->provider->getFallback('es');

        $this->assertStringContainsString('Gracias', $result);
    }

    public function testGermanFallback(): void
    {
        $result = $this->provider->getFallback('de');

        $this->assertStringContainsString('Dank', $result);
    }

    public function testNullLanguageDefaultsToEnglish(): void
    {
        $result = $this->provider->getFallback(null);

        $this->assertStringContainsString('Thank you', $result);
    }

    public function testEmptyLanguageDefaultsToEnglish(): void
    {
        $result = $this->provider->getFallback('');

        $this->assertStringContainsString('Thank you', $result);
    }

    public function testUnknownLanguageDefaultsToEnglish(): void
    {
        $result = $this->provider->getFallback('zh');

        $this->assertStringContainsString('Thank you', $result);
    }

    public function testCaseInsensitive(): void
    {
        $result = $this->provider->getFallback('FR');

        $this->assertStringContainsString('Merci', $result);
    }

    public function testSevenLanguagesSupported(): void
    {
        $languages = $this->provider->getSupportedLanguages();

        $this->assertCount(7, $languages);
        $this->assertContains('en', $languages);
        $this->assertContains('fr', $languages);
        $this->assertContains('es', $languages);
        $this->assertContains('de', $languages);
        $this->assertContains('pt', $languages);
        $this->assertContains('it', $languages);
        $this->assertContains('nl', $languages);
    }

    public function testIsLanguageSupported(): void
    {
        $this->assertTrue($this->provider->isLanguageSupported('en'));
        $this->assertTrue($this->provider->isLanguageSupported('fr'));
        $this->assertFalse($this->provider->isLanguageSupported('zh'));
        $this->assertFalse($this->provider->isLanguageSupported('ja'));
    }
}
