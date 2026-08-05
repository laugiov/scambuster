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
 *
 * Each language exposes a POOL of phrasings rather than one fixed sentence: a
 * single hardcoded fallback, sent every time generation gave up, produced
 * byte-identical outbound messages across conversations — an obvious bot tell.
 * A variation key (conversation id + turn) selects one phrasing deterministically,
 * so different conversations and turns get different fallbacks while a given key
 * stays reproducible for replay/tests.
 */
final class FallbackProvider
{
    /**
     * Accent-free on purpose (matches the transport-safe convention used across
     * the pipeline). Each list has at least five distinct phrasings.
     *
     * @var array<string, list<string>> ISO 639-1 code → fallback message pool
     */
    private const FALLBACKS = [
        'en' => [
            'Thanks for this. Let me look it over and I will come back to you.',
            'Got it. I need a moment to go through this properly, I will be in touch.',
            'Appreciate the details. I will review them and reply shortly.',
            'Noted. I will read through everything and get back to you soon.',
            'Thank you. I will take a proper look and follow up.',
            'Okay, give me a little time to go over this and I will respond.',
        ],
        'fr' => [
            'Merci pour ces informations. Je regarde cela et je reviens vers vous.',
            'Bien recu. Il me faut un moment pour tout parcourir, je vous recontacte.',
            'Merci des precisions. Je regarde et je vous reponds sous peu.',
            'Note. Je prends le temps de tout lire et je reviens vers vous vite.',
            'Merci. Je vais examiner cela correctement et je vous fais un retour.',
        ],
        'es' => [
            'Gracias por la informacion. Lo reviso y le respondo.',
            'Recibido. Necesito un momento para revisarlo, le escribo pronto.',
            'Gracias por los detalles. Los reviso y le contesto en breve.',
            'Anotado. Voy a leerlo todo con calma y le respondo.',
            'Gracias. Lo miro con atencion y le hago llegar una respuesta.',
        ],
        'de' => [
            'Danke dafuer. Ich schaue es mir an und melde mich.',
            'Erhalten. Ich brauche einen Moment, um alles durchzugehen, ich melde mich.',
            'Danke fuer die Details. Ich sehe sie durch und antworte in Kuerze.',
            'Notiert. Ich lese alles in Ruhe und komme darauf zurueck.',
            'Danke. Ich schaue es mir richtig an und gebe Rueckmeldung.',
        ],
        'pt' => [
            'Obrigado por isto. Vou analisar e retorno em seguida.',
            'Recebido. Preciso de um momento para ver tudo, entro em contato.',
            'Obrigado pelos detalhes. Vou rever e respondo em breve.',
            'Anotado. Vou ler tudo com calma e retorno.',
            'Obrigado. Vou dar uma boa olhada e dou um retorno.',
        ],
        'it' => [
            'Grazie per questo. Do un occhiata e le rispondo.',
            'Ricevuto. Mi serve un momento per esaminare tutto, la ricontatto.',
            'Grazie dei dettagli. Li rivedo e rispondo a breve.',
            'Annotato. Leggo tutto con calma e le rispondo.',
            'Grazie. Guardo con attenzione e le faccio sapere.',
        ],
        'nl' => [
            'Bedankt hiervoor. Ik bekijk het en kom bij u terug.',
            'Ontvangen. Ik heb even tijd nodig om alles door te nemen, ik neem contact op.',
            'Bedankt voor de details. Ik bekijk ze en reageer binnenkort.',
            'Genoteerd. Ik lees alles rustig door en kom erop terug.',
            'Bedankt. Ik kijk er goed naar en laat het u weten.',
        ],
    ];

    /** @var list<string> */
    private const UNIVERSAL_FALLBACK = [
        'Thanks for this. Let me look it over and I will come back to you.',
        'Got it. I need a moment to go through this properly, I will be in touch.',
        'Appreciate the details. I will review them and reply shortly.',
        'Noted. I will read through everything and get back to you soon.',
        'Thank you. I will take a proper look and follow up.',
    ];

    /**
     * Get a fallback message in the detected language.
     *
     * The phrasing is chosen by round-robin over the turn number, offset by a
     * per-conversation seed. This gives two guarantees at once:
     *  - consecutive turns in the SAME conversation never repeat (the index
     *    advances by exactly one each turn, so it only wraps, never sticks);
     *  - different conversations start at different offsets, so the same canned
     *    line is not sent across conversations (the original bot tell).
     * It stays fully deterministic (no state, no randomness) for replay/tests.
     *
     * @param string|null $detectedLanguage ISO 639-1 language code (null = unknown)
     * @param string|null $conversationSeed stable per-conversation value (e.g. conv id)
     *                                      that offsets the rotation; null/empty = no offset
     * @param int         $turn             turn number within the conversation; advances the
     *                                      rotation so successive fallbacks differ
     */
    public function getFallback(?string $detectedLanguage, ?string $conversationSeed = null, int $turn = 0): string
    {
        $pool = ($detectedLanguage !== null && $detectedLanguage !== '' && isset(self::FALLBACKS[strtolower($detectedLanguage)]))
            ? self::FALLBACKS[strtolower($detectedLanguage)]
            : self::UNIVERSAL_FALLBACK;

        // & 0x7fffffff keeps the offset non-negative on every platform (crc32 can
        // be negative on 32-bit PHP), so the modulo never yields a negative index.
        $offset = ($conversationSeed === null || $conversationSeed === '')
            ? 0
            : (crc32($conversationSeed) & 0x7fffffff);

        $index = ($offset + max(0, $turn)) % count($pool);

        return $pool[$index];
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
