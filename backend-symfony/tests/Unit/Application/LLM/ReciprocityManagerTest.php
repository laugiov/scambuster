<?php

declare(strict_types=1);

namespace App\Tests\Unit\Application\LLM;

use App\Application\LLM\ReciprocityManager;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for ReciprocityManager
 *
 * Tests give/take balance analysis, emotional detection,
 * direct question detection, and fake data suggestions.
 */
class ReciprocityManagerTest extends TestCase
{
    private ReciprocityManager $manager;

    protected function setUp(): void
    {
        $this->manager = new ReciprocityManager();
    }

    // ------------------------------------------------------------------ //
    //  Empty / First message
    // ------------------------------------------------------------------ //

    public function testEmptyMessagesReturnFirstMessageReason(): void
    {
        $result = $this->manager->analyze([]);

        $this->assertFalse($result['should_give_info']);
        $this->assertSame('first_message', $result['reason']);
        $this->assertSame(0, $result['give_count']);
        $this->assertSame(0, $result['take_count']);
    }

    // ------------------------------------------------------------------ //
    //  Too many questions without giving
    // ------------------------------------------------------------------ //

    public function testTooManyQuestionsTriggersGive(): void
    {
        $messages = [
            ['direction' => 'in', 'body_text' => 'Bonjour, je vous contacte.'],
            ['direction' => 'out', 'body_text' => 'Comment est-ce possible ? Qui etes-vous ?'],
            ['direction' => 'in', 'body_text' => 'Je suis votre banquier.'],
            ['direction' => 'out', 'body_text' => 'Quel est le probleme ? Pourquoi ?'],
        ];

        $result = $this->manager->analyze($messages);

        $this->assertTrue($result['should_give_info']);
        $this->assertSame('too_many_questions', $result['reason']);
        $this->assertGreaterThanOrEqual(2, $result['take_count']);
        $this->assertSame(0, $result['give_count']);
    }

    // ------------------------------------------------------------------ //
    //  Emotional vulnerability
    // ------------------------------------------------------------------ //

    public function testEmotionalKeywordTriggersGive(): void
    {
        $messages = [
            ['direction' => 'in', 'body_text' => 'Aide-moi, je suis désespéré !'],
        ];

        $result = $this->manager->analyze($messages);

        $this->assertTrue($result['should_give_info']);
        $this->assertSame('emotional_vulnerability', $result['reason']);
    }

    public function testUrgentKeywordTriggersEmotionalVulnerability(): void
    {
        $messages = [
            ['direction' => 'in', 'body_text' => "C'est urgent, j'ai peur pour mon compte."],
        ];

        $result = $this->manager->analyze($messages);

        $this->assertTrue($result['should_give_info']);
        $this->assertSame('emotional_vulnerability', $result['reason']);
    }

    // ------------------------------------------------------------------ //
    //  Direct questions
    // ------------------------------------------------------------------ //

    public function testDirectQuestionTriggersGive(): void
    {
        $messages = [
            ['direction' => 'in', 'body_text' => 'Donne-moi ton adresse email.'],
        ];

        $result = $this->manager->analyze($messages);

        $this->assertTrue($result['should_give_info']);
        $this->assertSame('direct_question', $result['reason']);
    }

    public function testQuelEstTonTriggersDirectQuestion(): void
    {
        $messages = [
            ['direction' => 'in', 'body_text' => 'Quel est ton numero de telephone ?'],
        ];

        $result = $this->manager->analyze($messages);

        $this->assertTrue($result['should_give_info']);
        $this->assertSame('direct_question', $result['reason']);
    }

    public function testJaiBesoinTriggersDirectQuestion(): void
    {
        $messages = [
            ['direction' => 'in', 'body_text' => "J'ai besoin de votre identite."],
        ];

        $result = $this->manager->analyze($messages);

        $this->assertTrue($result['should_give_info']);
        $this->assertSame('direct_question', $result['reason']);
    }

    // ------------------------------------------------------------------ //
    //  Imbalanced ratio
    // ------------------------------------------------------------------ //

    public function testImbalancedRatioTriggersGive(): void
    {
        $messages = [
            ['direction' => 'in', 'body_text' => 'Bonjour.'],
            ['direction' => 'out', 'body_text' => 'Voici mon nom, je suis Jean. Comment ca marche ? Qui est-ce ? Que dois-je faire ?'],
            ['direction' => 'in', 'body_text' => 'Ok.'],
            ['direction' => 'out', 'body_text' => 'Pourquoi ? Comment ? Quand ?'],
        ];

        $result = $this->manager->analyze($messages);

        // give_count=1 (voici mon), take_count >= 3 questions -> ratio > 2
        $this->assertTrue($result['should_give_info']);
        $this->assertSame('imbalanced_ratio', $result['reason']);
    }

    // ------------------------------------------------------------------ //
    //  Balanced conversation
    // ------------------------------------------------------------------ //

    public function testBalancedConversationDoesNotTriggerGive(): void
    {
        $messages = [
            ['direction' => 'in', 'body_text' => 'Bonjour, comment allez-vous ?'],
            ['direction' => 'out', 'body_text' => "Je m'appelle Marie, merci."],
        ];

        $result = $this->manager->analyze($messages);

        $this->assertFalse($result['should_give_info']);
        $this->assertSame('balanced', $result['reason']);
    }

    // ------------------------------------------------------------------ //
    //  Counting
    // ------------------------------------------------------------------ //

    public function testGiveCountDetectsVoiciMon(): void
    {
        $messages = [
            ['direction' => 'out', 'body_text' => 'Voici mon numero.'],
        ];

        $result = $this->manager->analyze($messages);

        $this->assertSame(1, $result['give_count']);
    }

    public function testGiveCountDetectsJeMapelle(): void
    {
        $messages = [
            ['direction' => 'out', 'body_text' => "Je m'appelle Pierre."],
        ];

        $result = $this->manager->analyze($messages);

        $this->assertSame(1, $result['give_count']);
    }

    public function testTakeCountCountsQuestionMarks(): void
    {
        $messages = [
            ['direction' => 'out', 'body_text' => 'Qui ? Quoi ? Quand ?'],
        ];

        $result = $this->manager->analyze($messages);

        $this->assertSame(3, $result['take_count']);
    }

    public function testInboundMessagesAreIgnoredForGiveCount(): void
    {
        $messages = [
            ['direction' => 'in', 'body_text' => "Je m'appelle Jean."],
        ];

        $result = $this->manager->analyze($messages);

        $this->assertSame(0, $result['give_count']);
    }

    // ------------------------------------------------------------------ //
    //  generateFakeDataSuggestions
    // ------------------------------------------------------------------ //

    public function testGenerateFakeDataSuggestionsReturnsString(): void
    {
        $suggestions = $this->manager->generateFakeDataSuggestions([]);

        $this->assertIsString($suggestions);
        $this->assertNotEmpty($suggestions);
        $this->assertStringContainsString('SOFT DETAILS', $suggestions);
    }

    /**
     * The reciprocity hint must never encourage volunteering a contact channel.
     * It used to suggest inventing a French phone number, which fought the
     * deterministic no-out-of-band-channel guard; now it must explicitly forbid
     * channels and carry no phone-invention wording (in any language).
     */
    public function testGenerateFakeDataSuggestionsForbidsChannelsAndDropsPhoneInvitation(): void
    {
        $suggestions = strtolower($this->manager->generateFakeDataSuggestions([]));

        // Explicitly forbids sharing a channel.
        $this->assertStringContainsString('never share a phone', $suggestions);
        $this->assertStringContainsString('contact channel', $suggestions);

        // Carries no invitation to invent/share a phone number (the old defect).
        $this->assertStringNotContainsString('téléphone', $suggestions);
        $this->assertStringNotContainsString('numéro', $suggestions);
        $this->assertStringNotContainsString('share a phone number if', $suggestions);
    }

    public function testGenerateFakeDataSuggestionsIsEnglish(): void
    {
        $suggestions = $this->manager->generateFakeDataSuggestions([]);

        // No accented characters => no French leaking into the English pipeline.
        $this->assertSame(
            0,
            preg_match('/[àâäéèêëïîôùûüç]/iu', $suggestions),
            'Reciprocity hint must be English (no accented French characters)',
        );
    }
}
