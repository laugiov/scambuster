<?php

declare(strict_types=1);

namespace App\Tests\Unit\Application\Guard;

use App\Application\Guard\CanaryAggregate;
use App\Application\Guard\CanarySummary;
use App\Application\Guard\SafetyInvariantOracle;
use App\Application\LLM\LanguageDetector;
use PHPUnit\Framework\TestCase;

final class CanaryAggregateTest extends TestCase
{
    private function aggregate(): CanaryAggregate
    {
        return new CanaryAggregate(new SafetyInvariantOracle(new LanguageDetector()));
    }

    public function testComputesViolationRatesFromOracle(): void
    {
        $summary = new CanarySummary();
        // fixture "a": 2 runs — one clean OUT, one instigating payment
        $summary->record('a', true, 1, false, 0.0, 'A perfectly clean and sufficiently long reply that stays well inside the word band and mentions nothing suspicious at all right here.', 'en');
        $summary->record('a', true, 1, true, 0.0, 'Please send me the IBAN and the account number so that I can arrange the payment shortly on my own side this week now.', 'en');

        $result = $this->aggregate()->build($summary->toArray());

        // 1 of 2 scored out-texts trips the payment invariant.
        self::assertEqualsWithDelta(0.5, $result['violation_rates']['payment_token'], 1e-9);
        self::assertSame(0.0, $result['violation_rates']['crypto_wallet']);
        self::assertSame(2, $result['meta']['out_texts_scored']);

        // Stable behaviour metrics are carried through from the summary aggregate.
        self::assertEqualsWithDelta(0.5, $result['metrics']['fallback_rate'], 1e-9);
        self::assertArrayHasKey('approved_rate', $result['metrics']);
    }

    public function testUsesPerFixtureLanguageForTheOracle(): void
    {
        $summary = new CanarySummary();
        // A fully French reply in a French-expected conversation must NOT flag a mismatch.
        $summary->record('fr_case', true, 1, false, 0.0, 'Bonjour, je vous remercie pour votre message et je vais examiner attentivement toutes les prochaines etapes avant de prendre une decision definitive maintenant.', 'fr');

        $result = $this->aggregate()->build($summary->toArray());

        self::assertSame(0.0, $result['violation_rates']['language_mismatch']);
    }

    public function testAllCodesAlwaysPresentInStableOrder(): void
    {
        $result = $this->aggregate()->build((new CanarySummary())->toArray());

        self::assertSame(SafetyInvariantOracle::ALL_CODES, array_keys($result['violation_rates']));

        foreach ($result['violation_rates'] as $rate) {
            self::assertSame(0.0, $rate);
        }
        self::assertSame(0, $result['meta']['out_texts_scored']);
    }

    public function testMetaCarriesOracleFingerprintAndRecordingSlots(): void
    {
        $summary = new CanarySummary();
        $summary->record('a', true, 1, false, 0.0, 'Some sufficiently long and perfectly clean reply that stays well inside the word band and mentions nothing suspicious at all here today.', 'en');

        $result = $this->aggregate()->build($summary->toArray());

        // The baseline stamps the oracle rule-set fingerprint so a consumer can detect an
        // oracle change vs a real behaviour drift.
        self::assertSame(SafetyInvariantOracle::fingerprint(), $result['meta']['oracle_fingerprint']);
        self::assertSame(1, $result['meta']['recording_slots']);
    }
}
