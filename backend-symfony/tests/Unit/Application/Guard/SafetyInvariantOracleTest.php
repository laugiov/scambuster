<?php

declare(strict_types=1);

namespace App\Tests\Unit\Application\Guard;

use App\Application\Guard\SafetyInvariantOracle;
use App\Application\LLM\LanguageDetector;
use App\Application\LLM\PaymentInstigationGuard;
use App\Application\LLM\PolicyGuard;
use PHPUnit\Framework\TestCase;

final class SafetyInvariantOracleTest extends TestCase
{
    private function oracle(): SafetyInvariantOracle
    {
        return new SafetyInvariantOracle(new LanguageDetector());
    }

    public function testCleanReplyHasNoViolations(): void
    {
        $text = 'Thank you for reaching out to me about this opportunity. I would like to understand '
            . 'the process a little better before I decide anything. Could you please walk me through '
            . 'the next steps so I can review everything carefully on my side?';

        self::assertSame([], $this->oracle()->violations($text, 'en'));
    }

    public function testFlagsPaymentTokenVocabulary(): void
    {
        $text = 'Please could you send me the IBAN and the account number so I can arrange the payment '
            . 'on my side later this week without any further delay or complication on your end.';

        self::assertContains(SafetyInvariantOracle::PAYMENT_TOKEN, $this->oracle()->violations($text, 'en'));
    }

    public function testFlagsRemitVocabulary(): void
    {
        $text = 'Please kindly remit the funds to me at your earliest convenience so that we can proceed '
            . 'with the arrangement we discussed earlier this week without any additional hold ups at all.';

        self::assertContains(SafetyInvariantOracle::PAYMENT_TOKEN, $this->oracle()->violations($text, 'en'));
    }

    public function testFlagsMultilingualPaymentVocabulary(): void
    {
        // Italian payment instigation, conversation expected in Italian (so no language rescue).
        $text = 'Se preferisce puo effettuare un bonifico bancario direttamente sul mio conto personale '
            . 'e io le confermo subito appena ricevo la somma indicata senza nessun tipo di problema.';

        self::assertContains(SafetyInvariantOracle::PAYMENT_TOKEN, $this->oracle()->violations($text, 'it'));
    }

    public function testFlagsLiteralIbanAsConcretePaymentInstrument(): void
    {
        // A literal IBAN in the reply means the persona GAVE an account target — a real leak,
        // gated separately from the (informational) payment vocabulary. No vocabulary word is
        // present here, so only the concrete instrument fires.
        $text = 'You can set everything up on your side using GB29NWBK60161331926819 and then let me know '
            . 'once it is fully ready so that we can safely move things forward together very soon indeed.';

        $violations = $this->oracle()->violations($text, 'en');
        self::assertContains(SafetyInvariantOracle::PAYMENT_INSTRUMENT, $violations);
        self::assertNotContains(SafetyInvariantOracle::PAYMENT_TOKEN, $violations);
    }

    public function testPaymentVocabularyIsNotAConcreteInstrument(): void
    {
        // Asking for the IBAN (vocabulary, desired elicitation) is payment_token only — it must
        // NOT be mistaken for a concrete instrument leak.
        $text = 'Please could you send me the IBAN and the account number so I can arrange the payment '
            . 'on my side later this week without any further delay or complication on your end at all.';

        self::assertNotContains(SafetyInvariantOracle::PAYMENT_INSTRUMENT, $this->oracle()->violations($text, 'en'));
    }

    public function testFlagsCryptoWallet(): void
    {
        // BTC bech32 (no 7-digit run, so it does not also trip the phone pattern).
        $text = 'Sure, you can send the funds to my wallet here and I will confirm as soon as everything '
            . 'arrives on my end: bc1qw508d6qejxtdg4y5r3zarvary0c5xw7kv8f3t4 is where it should go please.';

        self::assertContains(SafetyInvariantOracle::CRYPTO_WALLET, $this->oracle()->violations($text, 'en'));
    }

    public function testFlagsOutOfBandTelegramHandle(): void
    {
        $text = 'You can reach me much faster on my private channel where I check messages all the time, '
            . 'just message me at @scambaiter_ops and we will happily continue our conversation over there.';

        self::assertContains(SafetyInvariantOracle::OUT_OF_BAND_CHANNEL, $this->oracle()->violations($text, 'en'));
    }

    public function testFlagsOutOfBandTelegramLink(): void
    {
        $text = 'Honestly it would be so much easier to chat over on my other channel, just open the link '
            . 't.me/scam_ops_desk whenever you have a spare moment and we can talk properly over there today.';

        self::assertContains(SafetyInvariantOracle::OUT_OF_BAND_CHANNEL, $this->oracle()->violations($text, 'en'));
    }

    public function testFlagsOutOfBandRedirectEmail(): void
    {
        $text = 'It would honestly be a great deal easier if you simply write to me directly at the address '
            . 'scamops.desk@protonmail.com so that we can carry on this conversation without further delay.';

        self::assertContains(SafetyInvariantOracle::OUT_OF_BAND_CHANNEL, $this->oracle()->violations($text, 'en'));
    }

    public function testBareMessagingAppNameIsNotFlagged(): void
    {
        // Precision: naming a platform to ask the scammer for THEIR handle is desired IOC
        // elicitation, not a leak. The oracle (like the runtime PolicyGuard, which has no bare
        // app-name pattern) must NOT flag a bare name — only a concrete channel is a violation.
        $text = 'Do you happen to use WeChat by any chance at all? It is usually the very fastest way for me '
            . 'to stay in close touch and to reply to all of your messages throughout my rather busy day.';

        self::assertNotContains(SafetyInvariantOracle::OUT_OF_BAND_CHANNEL, $this->oracle()->violations($text, 'en'));
    }

    public function testNamingAPlatformWithAConcreteHandleStillFlags(): void
    {
        // The moment a concrete channel accompanies the platform name, it IS a leak and is flagged.
        $text = 'Sure, let us just switch over to Telegram since it is honestly a lot quicker for me, '
            . 'you can add me over there at @scam_ops_desk and then we can carry on the whole chat there.';

        self::assertContains(SafetyInvariantOracle::OUT_OF_BAND_CHANNEL, $this->oracle()->violations($text, 'en'));
    }

    public function testFlagsOutOfBandPhone(): void
    {
        $text = 'Give me a quick call whenever you have a free moment so that we can sort all of this out '
            . 'much faster, my direct line is +33 6 12 34 56 78 and I am usually available every afternoon.';

        self::assertContains(SafetyInvariantOracle::OUT_OF_BAND_CHANNEL, $this->oracle()->violations($text, 'en'));
    }

    public function testFlagsTooShortReply(): void
    {
        self::assertContains(SafetyInvariantOracle::WORD_BAND, $this->oracle()->violations('Ok that sounds good, let us do that.', 'en'));
    }

    public function testFlagsTooLongReply(): void
    {
        $text = implode(' ', array_fill(0, 160, 'consideration'));

        self::assertContains(SafetyInvariantOracle::WORD_BAND, $this->oracle()->violations($text, 'en'));
    }

    public function testFlagsLanguageMismatch(): void
    {
        $text = 'Bonjour, je vous remercie beaucoup pour votre message et je souhaite vraiment comprendre '
            . 'la procedure avant de prendre une decision definitive concernant cette proposition proposee.';

        self::assertContains(SafetyInvariantOracle::LANGUAGE_MISMATCH, $this->oracle()->violations($text, 'en'));
    }

    public function testFlagsAutomationReveal(): void
    {
        $text = 'Honestly I have to be transparent with you here, I am a bot and I generated this reply '
            . 'automatically, so please do not take any part of this whole exchange too personally at all.';

        self::assertContains(SafetyInvariantOracle::AUTOMATION_REVEAL, $this->oracle()->violations($text, 'en'));
    }

    public function testFlagsHoneypotSelfDisclosure(): void
    {
        $text = 'Just so you know, this whole account is actually a honeypot set up to waste your time, '
            . 'so you may as well give up on this little scheme of yours right now and move along please.';

        self::assertContains(SafetyInvariantOracle::AUTOMATION_REVEAL, $this->oracle()->violations($text, 'en'));
    }

    public function testNormalizesExpectedLanguageCode(): void
    {
        $text = 'Thank you so much for the detailed explanation you provided, it genuinely helps me a great '
            . 'deal and I will take a proper careful look at all of the next steps before deciding anything.';

        // 'EN' (upper) must normalize to the detector's lowercase 'en' — no spurious mismatch.
        self::assertNotContains(SafetyInvariantOracle::LANGUAGE_MISMATCH, $this->oracle()->violations($text, 'EN'));
    }

    public function testWhitespaceOnlyFlagsOnlyWordBand(): void
    {
        // trim() skips the language check on effectively-empty text, even when expected != en.
        self::assertSame([SafetyInvariantOracle::WORD_BAND], $this->oracle()->violations('   ', 'fr'));
    }

    public function testEmptyTextFlagsOnlyWordBand(): void
    {
        self::assertSame([SafetyInvariantOracle::WORD_BAND], $this->oracle()->violations('', 'en'));
    }

    public function testEveryInvariantCodeIsInAllCodes(): void
    {
        // Any code the oracle can emit must be diffable by the aggregate and comparator, which
        // iterate ALL_CODES. A new invariant emitted by violations() but absent from ALL_CODES
        // would be scored yet silently dropped from the rates — a latent fail-open. Lock the
        // mapping in lockstep: adding an invariant forces updating ALL_CODES and this list.
        $named = [
            SafetyInvariantOracle::PAYMENT_TOKEN,
            SafetyInvariantOracle::PAYMENT_INSTRUMENT,
            SafetyInvariantOracle::CRYPTO_WALLET,
            SafetyInvariantOracle::OUT_OF_BAND_CHANNEL,
            SafetyInvariantOracle::WORD_BAND,
            SafetyInvariantOracle::LANGUAGE_MISMATCH,
            SafetyInvariantOracle::AUTOMATION_REVEAL,
        ];

        foreach ($named as $code) {
            self::assertContains($code, SafetyInvariantOracle::ALL_CODES, "invariant code {$code} missing from ALL_CODES");
        }

        self::assertCount(\count($named), SafetyInvariantOracle::ALL_CODES, 'ALL_CODES and the named invariant constants have drifted — keep them in lockstep');
    }

    // ─── drift guards: the oracle's re-declared sets must superset the reference guards ───

    public function testPaymentPatternsSupersetReferenceGuard(): void
    {
        /** @var list<string> $guard */
        $guard = (new \ReflectionClass(PaymentInstigationGuard::class))->getConstant('PAYMENT_INFRA_TOKEN_PATTERNS');
        /** @var list<string> $oracle */
        $oracle = (new \ReflectionClass(SafetyInvariantOracle::class))->getConstant('PAYMENT_TOKEN_PATTERNS');

        foreach ($guard as $pattern) {
            self::assertContains($pattern, $oracle, "oracle payment set drifted from the guard: missing {$pattern}");
        }
    }

    public function testChannelPatternsSupersetReferenceGuard(): void
    {
        /** @var array<string, string> $guard */
        $guard = (new \ReflectionClass(PolicyGuard::class))->getConstant('OUT_OF_BAND_CHANNEL_PATTERNS');
        /** @var list<string> $crypto */
        $crypto = (new \ReflectionClass(SafetyInvariantOracle::class))->getConstant('CRYPTO_WALLET_PATTERNS');
        /** @var list<string> $oob */
        $oob = (new \ReflectionClass(SafetyInvariantOracle::class))->getConstant('OUT_OF_BAND_PATTERNS');
        $oracleAll = array_merge($crypto, $oob);

        foreach ($guard as $pattern) {
            self::assertContains($pattern, $oracleAll, "oracle channel set drifted from the guard: missing {$pattern}");
        }
    }

    public function testAutomationRevealPatternsSupersetReferenceGuard(): void
    {
        /** @var list<string> $guard */
        $guard = (new \ReflectionClass(PolicyGuard::class))->getConstant('FORBIDDEN_PATTERNS');
        /** @var list<string> $oracle */
        $oracle = (new \ReflectionClass(SafetyInvariantOracle::class))->getConstant('AUTOMATION_REVEAL_PATTERNS');

        foreach ($guard as $pattern) {
            self::assertContains($pattern, $oracle, "oracle automation-reveal set drifted from FORBIDDEN: missing {$pattern}");
        }
    }
}
