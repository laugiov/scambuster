<?php

declare(strict_types=1);

namespace App\Application\LLM;

/**
 * Provides language-aware fallback messages when reply generation fails.
 *
 * Used after all 3 generation attempts are exhausted. The fallback must:
 * - Match the detected language of the conversation
 * - Be safe and generic (no IOC-specific content)
 * - Maintain the conversation (invite further response)
 * - Default to English for unknown/unsupported languages
 */
final class FallbackProvider
{
    /** @var array<string, string> ISO 639-1 code → fallback message */
    private const FALLBACKS = [
        'en' => 'Thank you for your message. I will read it carefully and get back to you shortly.',
        'fr' => 'Merci pour votre message. Je vais le relire attentivement et vous repondre rapidement.',
        'es' => 'Gracias por su mensaje. Lo leere con atencion y le respondere pronto.',
        'de' => 'Vielen Dank fuer Ihre Nachricht. Ich werde sie sorgfaeltig lesen und mich bald melden.',
        'pt' => 'Obrigado pela sua mensagem. Vou le-la com atencao e responder em breve.',
        'it' => 'Grazie per il suo messaggio. Lo leggero attentamente e le rispondero a breve.',
        'nl' => 'Bedankt voor uw bericht. Ik zal het zorgvuldig lezen en snel reageren.',
    ];

    private const UNIVERSAL_FALLBACK = 'Thank you for your message. I will read it carefully and get back to you shortly.';

    /**
     * Get a fallback message in the detected language.
     *
     * @param string|null $detectedLanguage ISO 639-1 language code (null = unknown)
     */
    public function getFallback(?string $detectedLanguage): string
    {
        if ($detectedLanguage === null || $detectedLanguage === '') {
            return self::UNIVERSAL_FALLBACK;
        }

        return self::FALLBACKS[strtolower($detectedLanguage)] ?? self::UNIVERSAL_FALLBACK;
    }

    /**
     * Check if a language is supported for fallback.
     */
    public function isLanguageSupported(string $languageCode): bool
    {
        return isset(self::FALLBACKS[strtolower($languageCode)]);
    }

    /**
     * @return list<string> Supported language codes
     */
    public function getSupportedLanguages(): array
    {
        return array_keys(self::FALLBACKS);
    }
}
