<?php

declare(strict_types=1);

namespace App\Tests\Unit\Application\LLM;

use App\Application\LLM\WordCounter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the single pipeline-wide word-counting rule.
 *
 * The rule is Unicode whitespace tokenization: a "word" is any run of
 * non-whitespace characters. This matches what an LLM (and a human)
 * understands when instructed "write at least N words", and it is
 * language-agnostic: digits, uppercase accented letters, elisions and
 * hyphenated compounds each count as the tokens a reader would count.
 *
 * Production regression pinned here: a 36-token French reply was
 * rejected as 33 words because str_word_count ignored digit tokens and
 * uppercase accented initials, shipping a fallback placeholder instead
 * of a valid reply.
 */
class WordCounterTest extends TestCase
{
    /**
     * @return iterable<string, array{string, int}>
     */
    public static function provideTexts(): iterable
    {
        yield 'plain english' => ['The quick brown fox jumps over the lazy dog', 9];

        yield 'french elision counts as one token' => ["Merci pour votre message d\u{2019}expédition", 5];

        yield 'uppercase accented initials count' => ['École ouverte À tous les élèves', 6];

        yield 'digit tokens count as words' => ['Le colis 12345 arrive le 12 juillet 2026', 8];

        yield 'hyphenated compound is one token' => ['Un rendez-vous très important', 4];

        yield 'mixed whitespace runs collapse' => ["un  deux\ttrois\n\nquatre", 4];

        yield 'leading and trailing whitespace ignored' => ["  bonjour monsieur  \n", 2];

        yield 'empty string' => ['', 0];

        yield 'whitespace only' => ["  \t\n  ", 0];

        yield 'french spaced punctuation counts as token' => ['Bonjour, merci pour ton message !', 6];

        yield 'non-breaking space splits tokens' => ["cent\u{00A0}euros", 2];
    }

    #[DataProvider('provideTexts')]
    public function testCountsWhitespaceTokens(string $text, int $expected): void
    {
        $this->assertSame($expected, WordCounter::count($text));
    }

    public function testFrenchReplyNearFloorCountsLikeTheGeneratorTargets(): void
    {
        // 36 whitespace tokens including digits, an uppercase accented
        // initial, an elision and a hyphenated verb — the shape the
        // generator produces when told "Target length: 35-150 words".
        // Must count 36 (str_word_count variants returned 33).
        $text = "Étant donné que je n\u{2019}ai rien commandé récemment, je suis un peu inquiet "
            . 'concernant le colis 4512 annoncé pour le 12 juillet. Pouvez-vous me confirmer '
            . 'le numéro de suivi exact et la date de livraison prévue?';

        $this->assertSame(36, WordCounter::count($text));
    }
}
