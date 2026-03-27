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
        // 28 words — above bot accusation min (20) but below normal min (50)
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

        // Short text is rejected in normal context (min 50 words)
        $result = $this->guard->validate($shortText);

        $this->assertFalse($result['approved']);
        $this->assertNotEmpty($result['flags']);
        $this->assertStringContainsString('too_short', $result['flags'][0]);
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
            'test' => ['test'],
            'analyse' => ['analyse'],
            'leurre' => ['leurre'],
            'fake' => ['fake'],
            'simulation' => ['simulation'],
            'bot' => ['bot'],
            'automatique' => ['automatique'],
            'intelligence artificielle' => ['intelligence artificielle'],
            'scambuster' => ['scambuster'],
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
     * @test
     */
    public function it_allows_fake_phone_numbers(): void
    {
        // Fake phone numbers are now ALLOWED (needed for honeypot reciprocity)
        $text = str_repeat('Appelez-moi au 0612345678 pour discuter rapidement de votre situation. ', 10);

        $result = $this->guard->validate($text);

        $this->assertTrue($result['approved']);
        $this->assertEmpty($result['flags']); // No PII flag for phone numbers
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
