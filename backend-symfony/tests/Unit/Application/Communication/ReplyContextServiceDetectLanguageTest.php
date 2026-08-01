<?php

declare(strict_types=1);

namespace App\Tests\Unit\Application\Communication;

use App\Application\Communication\ReplyContextService;
use PHPUnit\Framework\TestCase;

/**
 * Tests for ReplyContextService::detectLanguageFromContext.
 *
 * Uses Reflection to call the public method without constructing
 * all final dependencies that are not needed for language detection.
 *
 * Targets uncovered lines 264, 266-267, 270, 274.
 */
class ReplyContextServiceDetectLanguageTest extends TestCase
{
    /**
     * Build a partially-constructed ReplyContextService using Reflection
     * to bypass the final PersonaOptimizer dependency.
     */
    private function buildServiceViaReflection(?\App\Application\LLM\LanguageDetector $langDetector = null): ReplyContextService
    {
        $ref = new \ReflectionClass(ReplyContextService::class);
        /** @var ReplyContextService $service */
        $service = $ref->newInstanceWithoutConstructor();

        // Set only the properties needed for detectLanguageFromContext
        $loggerProp = $ref->getProperty('logger');
        $loggerProp->setValue($service, new \Psr\Log\NullLogger());

        $ldProp = $ref->getProperty('languageDetector');
        $ldProp->setValue($service, $langDetector);

        return $service;
    }

    public function testDetectsLanguageFromStoredLangDetect(): void
    {
        $service = $this->buildServiceViaReflection();

        $context = [
            'last_messages' => [
                ['direction' => 'in', 'body_text' => 'Bonjour', 'lang_detect' => 'fr'],
            ],
        ];

        $this->assertSame('fr', $service->detectLanguageFromContext($context));
    }

    public function testDetectsLanguageFromLastInboundMessage(): void
    {
        $service = $this->buildServiceViaReflection();

        $context = [
            'last_messages' => [
                ['direction' => 'in', 'body_text' => 'English', 'lang_detect' => 'en'],
                ['direction' => 'out', 'body_text' => 'Reply', 'lang_detect' => 'en'],
                ['direction' => 'in', 'body_text' => 'Hola amigo', 'lang_detect' => 'es'],
            ],
        ];

        $this->assertSame('es', $service->detectLanguageFromContext($context));
    }

    public function testFallsBackToEnglishWhenNoMessages(): void
    {
        $service = $this->buildServiceViaReflection();
        $this->assertSame('en', $service->detectLanguageFromContext(['last_messages' => []]));
    }

    public function testFallsBackToEnglishWhenNoInboundMessages(): void
    {
        $service = $this->buildServiceViaReflection();

        $context = [
            'last_messages' => [
                ['direction' => 'out', 'body_text' => 'Reply only'],
            ],
        ];

        $this->assertSame('en', $service->detectLanguageFromContext($context));
    }

    public function testFallsBackToTrigramDetectorWhenLangDetectInvalid(): void
    {
        $langDetector = new \App\Application\LLM\LanguageDetector();
        $service = $this->buildServiceViaReflection($langDetector);

        $context = [
            'last_messages' => [
                // lang_detect is not 2 chars, so falls to trigram detector
                ['direction' => 'in', 'body_text' => 'Bonjour comment allez-vous', 'lang_detect' => 'invalid'],
            ],
        ];

        // LanguageDetector should detect French from "Bonjour comment allez-vous"
        $result = $service->detectLanguageFromContext($context);
        $this->assertSame('fr', $result);
    }

    public function testFallsBackToTrigramDetectorWhenLangDetectNull(): void
    {
        $langDetector = new \App\Application\LLM\LanguageDetector();
        $service = $this->buildServiceViaReflection($langDetector);

        $context = [
            'last_messages' => [
                ['direction' => 'in', 'body_text' => 'Good morning, how are you doing today?', 'lang_detect' => null],
            ],
        ];

        $this->assertSame('en', $service->detectLanguageFromContext($context));
    }

    public function testFallsBackToEnglishWhenNoDetectorAndInvalidLang(): void
    {
        $service = $this->buildServiceViaReflection(null);

        $context = [
            'last_messages' => [
                ['direction' => 'in', 'body_text' => 'Some text', 'lang_detect' => 'too_long'],
            ],
        ];

        $this->assertSame('en', $service->detectLanguageFromContext($context));
    }

    public function testFallsBackToEnglishWhenEmptyBodyAndNoDetector(): void
    {
        $service = $this->buildServiceViaReflection(null);

        $context = [
            'last_messages' => [
                ['direction' => 'in', 'body_text' => '', 'lang_detect' => null],
            ],
        ];

        $this->assertSame('en', $service->detectLanguageFromContext($context));
    }
}
