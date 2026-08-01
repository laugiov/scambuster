<?php

declare(strict_types=1);

namespace App\Tests\Unit\Application\LLM;

use App\Application\LLM\PolicyGuard;
use App\Application\LLM\PolicyGuardConfig;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Locks the HARM / OPSEC boundary: HARM patterns are compile-time invariants
 * that fire regardless of constructor input, and the only operator-facing knob
 * (`$additionalOpsecPatterns`) can UNION extra OPSEC patterns but can never
 * remove or weaken a HARM pattern.
 */
final class PolicyGuardHarmOpsecInvariantTest extends TestCase
{
    /**
     * @param array<string> $flags
     */
    private static function assertHasFlagPrefix(string $prefix, array $flags): void
    {
        foreach ($flags as $flag) {
            if (str_starts_with($flag, $prefix)) {
                self::assertTrue(true);

                return;
            }
        }

        self::fail(sprintf('Expected a flag starting with "%s"; got: %s', $prefix, implode(', ', $flags)));
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function harmSampleProvider(): array
    {
        return [
            'threat' => [
                'I will kill you and everyone you care about if you do not stop wasting my time right now.',
                'threat_detected:',
            ],
            'authority' => [
                'Listen carefully: I am a police officer and this has now become an official criminal investigation.',
                'authority_impersonation:',
            ],
            'pii_iban' => [
                'For the settlement please note my account FR7630006000011234567890189 and confirm once you have processed it.',
                'pii_detected',
            ],
            'out_of_band_phone' => [
                'It is far easier if you just reach me directly on my phone at +33 6 12 34 56 78 tomorrow.',
                'out_of_band_channel:phone',
            ],
            'out_of_band_wallet' => [
                'Once the paperwork is done please send everything to 0x52908400098527886E0F7030069857D2E4169EE7 without delay.',
                'out_of_band_channel:crypto_eth',
            ],
        ];
    }

    /**
     * @dataProvider harmSampleProvider
     */
    public function testHarmFiresWithEmptyOpsecConfig(string $text, string $expectedPrefix): void
    {
        $guard = new PolicyGuard(new NullLogger(), 1, []);

        $result = $guard->validate($text, PolicyGuardConfig::default());

        self::assertFalse($result['approved']);
        self::assertHasFlagPrefix($expectedPrefix, $result['flags']);
    }

    /**
     * @dataProvider harmSampleProvider
     */
    public function testHarmStillFiresWithArbitraryAdditionalOpsecPatterns(string $text, string $expectedPrefix): void
    {
        // No additive pattern (union-only) can disable a HARM invariant.
        $guard = new PolicyGuard(new NullLogger(), 1, [
            '/\bthis-never-matches-anything\b/i',
            '/\bacme-internal\b/i',
            '', // empty is skipped, must not crash
        ]);

        $result = $guard->validate($text, PolicyGuardConfig::default());

        self::assertFalse($result['approved']);
        self::assertHasFlagPrefix($expectedPrefix, $result['flags']);
    }

    public function testAdditionalOpsecPatternIsApplied(): void
    {
        $guard = new PolicyGuard(new NullLogger(), 1, ['/\bacme-internal-tool\b/i']);

        $text = 'Thanks so much for the update, I really appreciate it. We finally rolled everything out through acme-internal-tool last night and it all went through without any trouble at all.';
        $result = $guard->validate($text, PolicyGuardConfig::default());

        self::assertFalse($result['approved']);
        self::assertHasFlagPrefix('opsec_extra:', $result['flags']);
    }

    public function testAdditionalOpsecCannotSuppressAHarmPatternInTheSameText(): void
    {
        // Text trips BOTH an operator OPSEC pattern and a HARM pattern; the HARM
        // flag must still be present — additive config never gates HARM off.
        $guard = new PolicyGuard(new NullLogger(), 1, ['/\bacme-internal-tool\b/i']);

        $text = 'We shipped it via acme-internal-tool and by the way I will kill you if you keep stalling on this.';
        $result = $guard->validate($text, PolicyGuardConfig::default());

        self::assertFalse($result['approved']);
        self::assertHasFlagPrefix('opsec_extra:', $result['flags']);
        self::assertHasFlagPrefix('threat_detected:', $result['flags']);
    }

    public function testEmptyStringOpsecEntriesAreIgnoredAndHarmUnaffected(): void
    {
        $guard = new PolicyGuard(new NullLogger(), 1, ['', '/\bnever-matches-anything\b/']);

        $text = 'I am a police officer and this is an official investigation, cooperate fully or face the consequences today.';
        $result = $guard->validate($text, PolicyGuardConfig::default());

        self::assertHasFlagPrefix('authority_impersonation:', $result['flags']);
    }
}
