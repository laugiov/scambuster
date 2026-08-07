<?php

declare(strict_types=1);

namespace App\Tests\Unit\Application\LLM;

use App\Application\LLM\PolicyGuard;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Unit tests for PolicyGuard hard rules validation
 */
class PolicyGuardTest extends TestCase
{
    private PolicyGuard $guard;

    protected function setUp(): void
    {
        $this->guard = new PolicyGuard(
            logger: new NullLogger(),
            maxLinks: 1
        );
    }

    /**
     * @test
     */
    public function it_approves_valid_text(): void
    {
        $validText = str_repeat('Bonjour monsieur, je suis intéressé par votre offre. ', 10);

        $result = $this->guard->validate($validText);

        $this->assertTrue($result['approved']);
        $this->assertEmpty($result['flags']);
    }

    /**
     * @test
     */
    public function it_accepts_short_text_with_bot_accusation_context(): void
    {
        // 28 words — comfortably above the bot-accusation floor (12)
        $shortText = 'Wait what? I am not a robot! I am a real person and I find it quite offensive that you would say something like that. Why do you think that exactly?';

        // Short text is accepted when context allows it (bot accusation = min 20 words)
        $config = \App\Application\LLM\PolicyGuardConfig::fromContext(['is_bot_accusation' => true]);
        $result = $this->guard->validate($shortText, $config);

        $this->assertTrue($result['approved']);
        $this->assertEmpty($result['flags']);
    }

    /**
     * @test
     */
    public function it_rejects_short_text_in_normal_context(): void
    {
        $shortText = 'Bonjour, merci pour ton message !';

        // 5 words — still below the normal floor (20).
        $result = $this->guard->validate($shortText);

        $this->assertFalse($result['approved']);
        $this->assertNotEmpty($result['flags']);
        $this->assertStringContainsString('too_short', $result['flags'][0]);
    }

    /**
     * @test
     */
    public function it_accepts_a_moderately_short_reply_in_normal_context(): void
    {
        // 25 words — under the previous 35-word floor, so it would have been
        // rejected and regenerated before; the lower floor now lets it ship,
        // widening the length distribution instead of clustering at ~50.
        $reply = 'Thanks, that makes sense. Could you send the account details so I can '
            .'set up the payment on my side and get this moving today?';

        $result = $this->guard->validate($reply);

        $this->assertTrue($result['approved']);
        $this->assertEmpty($result['flags']);
    }

    /**
     * @test
     */
    public function it_rejects_text_too_long(): void
    {
        $longText = str_repeat('Bonjour monsieur, je suis très intéressé par votre proposition. ', 50);

        $result = $this->guard->validate($longText);

        $this->assertFalse($result['approved']);
        $this->assertCount(1, $result['flags']);
        $this->assertStringContainsString('too_long', $result['flags'][0]);
    }

    /**
     * @test
     */
    public function it_accepts_text_with_one_link(): void
    {
        // Build text with exactly 1 link and valid word count
        $textParts = [];
        $textParts[] = 'Voici le lien: https://example.com pour plus d\'informations.';
        $textParts[] = str_repeat('Je suis très intéressé par cette offre. ', 8);
        $text = implode(' ', $textParts);

        $result = $this->guard->validate($text);

        $this->assertTrue($result['approved'], 'Text with 1 link should be approved');
        $this->assertEmpty($result['flags']);
    }

    /**
     * @test
     */
    public function it_rejects_text_with_excessive_links(): void
    {
        // Build text with 2 links (avoid "test" keyword)
        $textParts = [];
        $textParts[] = 'Visitez https://example.com et https://demo.org pour en savoir plus.';
        $textParts[] = str_repeat('Cette proposition est très intéressante. ', 8);
        $text = implode(' ', $textParts);

        $result = $this->guard->validate($text);

        $this->assertFalse($result['approved']);
        $this->assertCount(1, $result['flags']);
        $this->assertStringContainsString('excessive_links', $result['flags'][0]);
    }

    /**
     * A French reply of exactly 36 whitespace tokens —
     * including digit tokens, an uppercase accented initial and an
     * elision — must pass the default 35-word floor. Production shipped
     * a fallback because str_word_count dropped the digit tokens and
     * counted this shape as 33 words.
     *
     * @test
     */
    public function it_counts_french_replies_the_way_the_generator_targets(): void
    {
        $text = 'Bonjour, votre message concernant le colis 4512 du 12 juillet 2026 me surprend '
            . 'car je ne me souviens pas de cette commande. Pouvez-vous me confirmer '
            . 'le numéro de suivi exact et la date de livraison prévue?';

        $result = $this->guard->validate($text);

        $this->assertTrue($result['approved'], 'A 36-token French reply must pass the 35-word floor');
        $this->assertEmpty($result['flags']);
    }

    /**
     * @test
     * @dataProvider forbiddenPatternsProvider
     */
    public function it_rejects_forbidden_patterns(string $forbiddenWord): void
    {
        $text = str_repeat("Je comprends votre demande concernant {$forbiddenWord} et je vais vous répondre. ", 10);

        $result = $this->guard->validate($text);

        $this->assertFalse($result['approved']);
        $this->assertNotEmpty($result['flags']);
        $this->assertStringContainsString('forbidden_pattern', $result['flags'][0]);
    }

    public static function forbiddenPatternsProvider(): array
    {
        return [
            'honeypot' => ['honeypot'],
            'scambuster' => ['scambuster'],
            'I am a bot' => ['I am a bot'],
            'automated system' => ['automated system'],
            'artificial intelligence' => ['artificial intelligence'],
            'leurre' => ['leurre'],
        ];
    }

    /**
     * @test
     */
    public function it_detects_iban_pii(): void
    {
        $text = str_repeat('Mon IBAN est FR7612345678901234567890123 pour le virement. ', 10);

        $result = $this->guard->validate($text);

        $this->assertFalse($result['approved']);
        $this->assertContains('pii_detected', $result['flags']);
    }

    /**
     * Phone numbers are now REJECTED (previous policy
     * allowed them, the audit on conv ab075b53-... showed that
     * permitting "fake" numbers leaks tell-tales of automation and
     * pulls the bait out of the email thread).
     *
     * @test
     */
    public function it_rejects_phone_numbers_as_out_of_band_channel(): void
    {
        $text = str_repeat('Appelez-moi au 0612345678 pour discuter rapidement de votre situation. ', 10);

        $result = $this->guard->validate($text);

        $this->assertFalse($result['approved']);
        $this->assertContains('out_of_band_channel:phone', $result['flags']);
    }

    /**
     * Parametrised across the out-of-band channel categories. Each sample is a
     * realistic-shaped leak; each MUST be rejected with the matching flag suffix.
     *
     * @dataProvider provideOutOfBandSamples
     * @test
     */
    public function it_rejects_each_out_of_band_channel_kind(string $sample, string $expectedFlag): void
    {
        // Pad to clear the min-words gate so we isolate the channel detection.
        $text = $sample . ' ' . str_repeat('Merci pour votre patience, je reviens vers vous dès que possible avec les détails du dossier. ', 5);

        $result = $this->guard->validate($text);

        $this->assertFalse($result['approved'], sprintf('Expected rejection for: "%s"', $sample));
        $this->assertContains($expectedFlag, $result['flags'], sprintf('Expected flag "%s" for: "%s"', $expectedFlag, $sample));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function provideOutOfBandSamples(): iterable
    {
        yield 'french mobile sequential' => ['Tu peux me joindre au 0612345678', 'out_of_band_channel:phone'];
        yield 'e164 international'        => ['Call me at +33 6 12 34 56 78 to discuss', 'out_of_band_channel:phone'];
        yield 'us format with parens'     => ['WhatsApp me at (555) 123-4567', 'out_of_band_channel:phone'];
        yield 'telegram handle'           => ['Reach me on Telegram: @scambaiter_xx', 'out_of_band_channel:telegram_handle'];
        yield 'skype live uri'            => ['Add me on Skype live:my.handle', 'out_of_band_channel:skype_uri'];
        yield 'discord invite'            => ['Join the chat at discord.gg/abc123', 'out_of_band_channel:signal_discord'];
        yield 'btc wallet'                => ['Send to my wallet bc1qar0srrr7xfkvy5l643lydnw9re59gtzzwf5mdq', 'out_of_band_channel:crypto_btc'];
        yield 'eth wallet'                => ['Or my ETH 0xabcdef0123456789abcdef0123456789abcdef01', 'out_of_band_channel:crypto_eth'];
        yield 't.me link'                 => ['Let us keep chatting on t.me/scam_ops_desk from now', 'out_of_band_channel:messenger_link'];
        yield 'wa.me link'                => ['Or reach me on wa.me/joinmychat any time', 'out_of_band_channel:messenger_link'];
        yield 'redirect email'            => ['Write to me directly at scamops.desk@protonmail.com please', 'out_of_band_channel:redirect_email'];
    }

    /**
     * Clean reply with no contact channel passes.
     *
     * @test
     */
    public function it_approves_reply_without_any_out_of_band_channel(): void
    {
        $text = str_repeat('Bonjour Anshu, merci pour les informations. Pouvez-vous me confirmer la référence de dossier avant que je valide le transfert ? ', 4);

        $result = $this->guard->validate($text);

        $this->assertTrue($result['approved'], sprintf('Expected approval; flags: %s', implode(', ', $result['flags'])));
        $this->assertEmpty($result['flags']);
    }

    /**
     * @test
     */
    public function it_detects_address_pii(): void
    {
        $text = str_repeat('J\'habite au 123 rue de la Paix à Paris. ', 10);

        $result = $this->guard->validate($text);

        $this->assertFalse($result['approved']);
        $this->assertContains('pii_detected', $result['flags']);
    }

    /**
     * @test
     */
    public function it_can_detect_multiple_flags(): void
    {
        $text = 'Ceci est un test honeypot.';

        $result = $this->guard->validate($text);

        $this->assertFalse($result['approved']);
        $this->assertGreaterThanOrEqual(2, count($result['flags']));
    }

    /**
     * @test
     */
    public function it_handles_french_accented_characters_in_word_count(): void
    {
        $text = str_repeat('Bonjour, je suis très intéressé par votre offre étonnante à découvrir. ', 10);

        $result = $this->guard->validate($text);

        $this->assertTrue($result['approved'], 'Should count French accented characters correctly');
    }

    /**
     * @test
     * @dataProvider threatPatternsProvider
     */
    public function it_rejects_threat_patterns(string $threatPhrase, string $expectedFlag): void
    {
        $text = str_repeat("Je comprends votre situation. ", 5)
            . $threatPhrase
            . str_repeat(" Merci de votre compréhension. ", 5);

        $result = $this->guard->validate($text);

        $this->assertFalse($result['approved']);
        $hasThreatFlag = false;
        foreach ($result['flags'] as $flag) {
            if (str_starts_with($flag, 'threat_detected:')) {
                $hasThreatFlag = true;
            }
        }
        $this->assertTrue($hasThreatFlag, 'Expected threat_detected flag for: ' . $threatPhrase);
    }

    public static function threatPatternsProvider(): array
    {
        return [
            'kill threat FR' => ['Je vais vous tuer si vous continuez.', 'threat_detected'],
            'harm threat FR' => ['Je vais vous blesser très fort.', 'threat_detected'],
            'destroy threat FR' => ['Je vais vous détruire complètement.', 'threat_detected'],
            'kill threat EN' => ['I will kill you for this.', 'threat_detected'],
            'hurt threat EN' => ['I will hurt your family.', 'threat_detected'],
            'pay threat FR' => ['Vous allez le payer très cher.', 'threat_detected'],
            'regret threat FR' => ['Vous allez regretter cela bientôt.', 'threat_detected'],
            'dead threat FR' => ['Vous êtes mort si vous faites cela.', 'threat_detected'],
            'know address FR' => ['Je sais où vous habitez monsieur.', 'threat_detected'],
            'watch out FR' => ['Gare à vous car je vais revenir.', 'threat_detected'],
        ];
    }

    /**
     * @test
     * @dataProvider authorityPatternsProvider
     */
    public function it_rejects_authority_impersonation(string $authorityPhrase): void
    {
        $text = str_repeat("Je comprends votre situation. ", 5)
            . $authorityPhrase
            . str_repeat(" Merci de votre compréhension. ", 5);

        $result = $this->guard->validate($text);

        $this->assertFalse($result['approved']);
        $hasAuthorityFlag = false;
        foreach ($result['flags'] as $flag) {
            if (str_starts_with($flag, 'authority_impersonation:')) {
                $hasAuthorityFlag = true;
            }
        }
        $this->assertTrue($hasAuthorityFlag, 'Expected authority_impersonation flag for: ' . $authorityPhrase);
    }

    public static function authorityPatternsProvider(): array
    {
        return [
            'police FR' => ['Je suis policier et je mène une enquête.'],
            'gendarme FR' => ['Je suis gendarme dans cette affaire.'],
            'commissaire FR' => ['Je suis commissaire de police nationale.'],
            'agent FR' => ['Je suis agent de police judiciaire.'],
            'procureur FR' => ['Je suis procureur de la République.'],
            'juge FR' => ['Je suis juge au tribunal de grande instance.'],
            'works for police FR' => ['Je travaille pour la police nationale.'],
            'works for interpol FR' => ['Je travaille pour interpol depuis longtemps.'],
            'police officer EN' => ['I am a police officer investigating this case.'],
            'fbi agent EN' => ['I am an fbi agent and you are under investigation.'],
            'works for police EN' => ['I work for the police department here.'],
            'arrest warrant FR' => ["J'ai un mandat d'arrêt contre vous."],
            // Commercial bank + tax-authority impersonation, and more languages.
            'bank advisor FR' => ['Je suis votre conseiller bancaire, service sécurité.'],
            'bank FR' => ['Je suis de votre banque et je dois vérifier votre compte.'],
            'tax FR' => ['Je suis un agent des impôts.'],
            'fisc FR' => ['Je suis du fisc pour un recouvrement.'],
            'bank EN' => ['I am calling from your bank about suspicious activity.'],
            'bank rep EN' => ['I am your bank representative and need your details.'],
            'irs EN' => ['I am from the IRS regarding unpaid taxes.'],
            'hmrc EN' => ['I am with HMRC about your tax return.'],
            'police ES' => ['Soy de la policía nacional.'],
            'bank ES' => ['Soy del banco, departamento de seguridad.'],
            'tax ES' => ['Soy de Hacienda, la agencia tributaria.'],
            'police DE' => ['Ich bin von der Polizei.'],
            'bank DE' => ['Ich bin von Ihrer Bank.'],
            'tax DE' => ['Ich bin vom Finanzamt.'],
            'police IT' => ['Sono della polizia di stato.'],
            'bank IT' => ['Sono della banca, ufficio sicurezza.'],
        ];
    }

    /**
     * @test
     */
    public function it_allows_mentioning_police_in_third_person(): void
    {
        // Talking ABOUT police (not claiming to BE police) should be allowed
        $text = str_repeat('La police ne peut rien faire dans cette situation malheureusement. ', 10);

        $result = $this->guard->validate($text);

        // Should not trigger authority impersonation (no "je suis" prefix)
        $hasAuthorityFlag = false;
        foreach ($result['flags'] as $flag) {
            if (str_starts_with($flag, 'authority_impersonation:')) {
                $hasAuthorityFlag = true;
            }
        }
        $this->assertFalse($hasAuthorityFlag, 'Mentioning police in third person should not trigger impersonation');
    }
}
