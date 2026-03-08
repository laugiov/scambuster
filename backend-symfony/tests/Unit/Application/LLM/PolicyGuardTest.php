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
            minWords: 50,
            maxWords: 150,
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
    public function it_accepts_short_text(): void
    {
        $shortText = 'Bonjour, merci pour ton message !';

        $result = $this->guard->validate($shortText);

        // No minimum word count - ReplyValidator handles conversation quality
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
}
