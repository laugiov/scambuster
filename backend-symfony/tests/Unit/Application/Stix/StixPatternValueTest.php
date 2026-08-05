<?php

declare(strict_types=1);

namespace App\Tests\Unit\Application\Stix;

use App\Application\Stix\StixPatternValue;
use PHPUnit\Framework\TestCase;

/**
 * D2 — STIX 2.1 pattern string-literal escaping.
 *
 * In STIX 2.1 comparison patterns a string literal is single-quoted and the
 * backslash is the escape character. The historical escaper doubled the quote
 * but NOT the backslash, so an attacker-influenced value containing `\` could
 * corrupt or break out of the emitted pattern. The escaper must double the
 * backslash before escaping the quote.
 */
final class StixPatternValueTest extends TestCase
{
    /**
     * @return iterable<string, array{0: string, 1: string}>
     */
    public static function escapeProvider(): iterable
    {
        // input => expected escaped body (what sits between the single quotes)
        yield 'plain value unchanged' => ['evil.example.com', 'evil.example.com'];
        yield 'single quote is escaped' => ["a'b", "a\\'b"];
        yield 'trailing backslash is doubled' => ['foo\\', 'foo\\\\'];
        yield 'interior backslash is doubled' => ['a\\b', 'a\\\\b'];
        yield 'backslash then quote' => ["a\\'b", "a\\\\\\'b"];
        yield 'quote then backslash' => ["a'\\b", "a\\'\\\\b"];
        yield 'empty' => ['', ''];
    }

    /**
     * @dataProvider escapeProvider
     */
    public function testEscape(string $input, string $expected): void
    {
        self::assertSame($expected, StixPatternValue::escape($input));
    }

    /**
     * The escaped value, wrapped in a single-quoted STIX literal, must be
     * syntactically balanced: doubling the backslash first guarantees the
     * closing quote can never be neutralized by a trailing `\`.
     */
    public function testTrailingBackslashDoesNotEatTheClosingQuote(): void
    {
        $body = StixPatternValue::escape('foo\\');
        $pattern = "[domain-name:value = '" . $body . "']";

        self::assertSame("[domain-name:value = 'foo\\\\']", $pattern);

        // The closing quote is not escaped iff the run of backslashes ending the
        // body is even. `foo\` escapes to `foo\\` — two trailing backslashes.
        preg_match('/\\\\*$/', $body, $m);
        $trailing = $m[0] ?? '';
        self::assertSame(0, \strlen($trailing) % 2, 'trailing backslash run must be even so the quote closes');
    }
}
