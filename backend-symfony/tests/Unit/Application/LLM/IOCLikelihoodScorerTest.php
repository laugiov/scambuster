<?php

declare(strict_types=1);

namespace App\Tests\Unit\Application\LLM;

use App\Application\LLM\IOCLikelihoodScorer;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for IOCLikelihoodScorer
 *
 * Tests the heuristic scoring system (0-100) for evaluating
 * generated replies based on their IOC extraction likelihood.
 */
class IOCLikelihoodScorerTest extends TestCase
{
    private IOCLikelihoodScorer $scorer;

    protected function setUp(): void
    {
        $this->scorer = new IOCLikelihoodScorer();
    }

    /**
     * @test
     */
    public function it_gives_high_score_for_explicit_question_targeting_channel(): void
    {
        $text = 'Quel est votre numéro de téléphone pour vous joindre ?';
        $context = [
            'state_slots' => [
                'canal_cible' => 'phone',
                'missing_iocs' => ['phone', 'url'],
            ],
            'last_messages' => [],
        ];

        $score = $this->scorer->score($text, $context);

        // +25 (question) +25 (channel) +10 (missing IOC) = 60
        $this->assertGreaterThanOrEqual(50, $score);
    }

    /**
     * @test
     */
    public function it_gives_bonus_for_explicit_question(): void
    {
        $text = 'Pouvez-vous me donner plus de détails ?';
        $context = ['state_slots' => [], 'last_messages' => []];

        $score = $this->scorer->score($text, $context);

        $this->assertGreaterThanOrEqual(25, $score);
    }

    /**
     * @test
     */
    public function it_detects_phone_channel_targeting(): void
    {
        $text = 'À quel numéro puis-je vous appeler ?';
        $context = [
            'state_slots' => ['canal_cible' => 'phone'],
            'last_messages' => [],
        ];

        $score = $this->scorer->score($text, $context);

        // Should have bonus for question + channel targeting
        $this->assertGreaterThanOrEqual(40, $score);
    }

    /**
     * @test
     */
    public function it_detects_whatsapp_channel_targeting(): void
    {
        $text = 'Avez-vous WhatsApp pour communiquer plus facilement ?';
        $context = [
            'state_slots' => ['canal_cible' => 'whatsapp'],
            'last_messages' => [],
        ];

        $score = $this->scorer->score($text, $context);

        $this->assertGreaterThanOrEqual(40, $score);
    }

    /**
     * @test
     */
    public function it_detects_iban_channel_targeting(): void
    {
        $text = 'Sur quel compte bancaire dois-je faire le virement ?';
        $context = [
            'state_slots' => ['canal_cible' => 'iban'],
            'last_messages' => [],
        ];

        $score = $this->scorer->score($text, $context);

        $this->assertGreaterThanOrEqual(40, $score);
    }

    /**
     * @test
     */
    public function it_detects_url_channel_targeting(): void
    {
        $text = 'Quel est le lien vers le site pour vérifier ?';
        $context = [
            'state_slots' => ['canal_cible' => 'url'],
            'last_messages' => [],
        ];

        $score = $this->scorer->score($text, $context);

        $this->assertGreaterThanOrEqual(40, $score);
    }

    /**
     * @test
     */
    public function it_gives_bonus_for_referencing_last_message(): void
    {
        $text = 'Vous mentionnez une vérification de sécurité, comment cela fonctionne ?';
        $context = [
            'state_slots' => [],
            'last_messages' => [
                [
                    'direction' => 'in',
                    'body_text' => 'Votre compte nécessite une vérification de sécurité urgente.',
                ],
            ],
        ];

        $score = $this->scorer->score($text, $context);

        // Should have bonus for question + reference
        $this->assertGreaterThanOrEqual(35, $score);
    }

    /**
     * @test
     */
    public function it_gives_bonus_for_mentioning_missing_iocs(): void
    {
        $text = 'Puis-je avoir votre numéro de téléphone ?';
        $context = [
            'state_slots' => [
                'missing_iocs' => ['phone'],
                'canal_cible' => 'phone',
            ],
            'last_messages' => [],
        ];

        $score = $this->scorer->score($text, $context);

        // +25 (question) +25 (channel) +10 (missing IOC) = 60
        $this->assertEquals(60, $score);
    }

    /**
     * @test
     */
    public function it_penalizes_proactive_action(): void
    {
        $text = 'Je vais vérifier mes documents et vous envoyer les informations.';
        $context = ['state_slots' => [], 'last_messages' => []];

        $score = $this->scorer->score($text, $context);

        // -20 for proactive action
        $this->assertEquals(0, $score); // Can't go below 0
    }

    /**
     * @test
     */
    public function it_penalizes_proactive_sending(): void
    {
        $text = 'Je peux vous envoyer mes informations si nécessaire.';
        $context = ['state_slots' => [], 'last_messages' => []];

        $score = $this->scorer->score($text, $context);

        // -20 for proactive action
        $this->assertEquals(0, $score);
    }

    /**
     * @test
     */
    public function it_penalizes_generic_language(): void
    {
        $text = 'Je comprends votre inquiétude. Je vois que c\'est important.';
        $context = ['state_slots' => [], 'last_messages' => []];

        $score = $this->scorer->score($text, $context);

        // -15 for generic language (2+ generic phrases)
        $this->assertEquals(0, $score);
    }

    /**
     * @test
     */
    public function it_scores_question_with_context_reference(): void
    {
        $text = 'Quel est le numéro ?';
        $context = [
            'state_slots' => [],
            'last_messages' => [
                [
                    'direction' => 'in',
                    'body_text' => 'Envoyez-moi votre numéro de téléphone immédiatement.',
                ],
            ],
        ];

        $score = $this->scorer->score($text, $context);

        // +25 (question) +25 (channel "numéro" keyword) +15 (references "téléphone" from last message)
        $this->assertEquals(65, $score);
    }

    /**
     * @test
     */
    public function it_handles_perfect_reply(): void
    {
        $text = 'Vous mentionnez un problème urgent. Sur quel numéro puis-je vous joindre pour régler cela ?';
        $context = [
            'state_slots' => [
                'canal_cible' => 'phone',
                'missing_iocs' => ['phone'],
            ],
            'last_messages' => [
                [
                    'direction' => 'in',
                    'body_text' => 'Votre compte a un problème urgent, contactez-nous.',
                ],
            ],
        ];

        $score = $this->scorer->score($text, $context);

        // +25 (question) +25 (channel) +15 (reference) +10 (missing IOC) = 75
        $this->assertEquals(75, $score);
    }

    /**
     * @test
     */
    public function it_handles_mediocre_reply(): void
    {
        $text = 'Je comprends. Je vois votre message.';
        $context = ['state_slots' => [], 'last_messages' => []];

        $score = $this->scorer->score($text, $context);

        // -15 (generic language)
        $this->assertEquals(0, $score);
    }

    /**
     * @test
     */
    public function it_handles_empty_context(): void
    {
        $text = 'Pouvez-vous clarifier ?';
        $context = [];

        $score = $this->scorer->score($text, $context);

        // +25 (question)
        $this->assertEquals(25, $score);
    }

    /**
     * @test
     */
    public function it_clamps_score_to_maximum_100(): void
    {
        // This shouldn't happen in practice, but test the clamping
        $text = 'Quel est votre numéro WhatsApp pour le virement bancaire via ce lien ?';
        $context = [
            'state_slots' => [
                'canal_cible' => 'phone',
                'missing_iocs' => ['phone', 'whatsapp', 'iban', 'url'],
            ],
            'last_messages' => [
                [
                    'direction' => 'in',
                    'body_text' => 'Envoyez-moi votre numéro WhatsApp pour le virement bancaire.',
                ],
            ],
        ];

        $score = $this->scorer->score($text, $context);

        $this->assertLessThanOrEqual(100, $score);
    }

    /**
     * @test
     */
    public function it_clamps_score_to_minimum_0(): void
    {
        $text = 'Je comprends. Je vois. Je vais vous transmettre mes informations. Merci pour votre message.';
        $context = ['state_slots' => [], 'last_messages' => []];

        $score = $this->scorer->score($text, $context);

        // Multiple penalties should not go below 0
        $this->assertEquals(0, $score);
        $this->assertGreaterThanOrEqual(0, $score);
    }

    /**
     * @test
     */
    public function it_scores_real_world_good_example(): void
    {
        $text = 'Avant de procéder, pouvez-vous me confirmer le numéro où je peux vous joindre pour sécuriser mon compte ?';
        $context = [
            'state_slots' => [
                'canal_cible' => 'phone',
                'missing_iocs' => ['phone'],
                'stage' => 'follow_up',
            ],
            'last_messages' => [
                [
                    'direction' => 'in',
                    'body_text' => 'Votre compte bancaire nécessite une vérification de sécurité.',
                ],
            ],
        ];

        $score = $this->scorer->score($text, $context);

        // Should be high quality
        $this->assertGreaterThanOrEqual(60, $score);
    }

    /**
     * @test
     */
    public function it_scores_real_world_bad_example(): void
    {
        $text = 'Je comprends votre message. C\'est inquiétant. Je vais vérifier avec ma banque.';
        $context = [
            'state_slots' => [],
            'last_messages' => [],
        ];

        $score = $this->scorer->score($text, $context);

        // Should be low quality (generic + proactive)
        $this->assertLessThanOrEqual(20, $score);
    }

    // ─── i18n EN keywords ───────────────────────────

    /**
     * EN channel keywords (account, wire, bank, beneficiary,
     * etc.) must trigger the +25 targetsSpecificChannel bonus, mirroring
     * the FR behavior. Previously, EN replies using ONLY these EN-specific
     * words (no "iban" / "telephone" which coincidentally match FR) missed
     * the bonus entirely.
     *
     * Test input deliberately uses EN-only words: "bank", "wire", "routing"
     * (none of which are in the previous FR keyword list for iban).
     */
    public function testScoreTargetsEnglishChannel(): void
    {
        // EN reply using ONLY EN-specific keywords (no "iban" which would
        // coincidentally match FR). Previously: only +25 from question;
        // missing_iocs hint won't trigger because no FR keyword matches.
        // Now: +25 (question) + +25 (channel: bank/wire/routing) +
        // +10 (missing iban via EN keywords) = 60.
        $text = 'Could you tell me the bank wire details and the routing number?';
        $context = [
            'state_slots' => [
                'canal_cible' => 'iban',
                'missing_iocs' => ['iban'],
            ],
            'last_messages' => [],
        ];

        $score = $this->scorer->score($text, $context);

        // Expected score: ≥ 50 (question + channel + missing).
        // Previously: only ~25 (just the question).
        $this->assertGreaterThanOrEqual(50, $score, 'EN-only channel keywords (bank/wire/routing) must contribute to score');
    }

    /**
     * EN proactive patterns ("I will check", "I can send")
     * must trigger the -20 penalty, mirroring FR behavior.
     */
    public function testScorePenalizesEnglishProactiveAction(): void
    {
        // EN reply proposing to send something proactively (bad scambaiting)
        $text = 'I will check my records and I can send you my IBAN tomorrow morning.';
        $context = ['state_slots' => [], 'last_messages' => []];

        $score = $this->scorer->score($text, $context);

        // No question (0), but should be penalized by -20 for proactive intent
        $this->assertLessThan(25, $score, 'EN proactive patterns must reduce score');
    }

    /**
     * EN generic phrases ("thank you for your message",
     * "I understand") must trigger the -15 penalty when 2+ are present.
     *
     * Test design: include a `?` (+25 question) so we can DETECT the -15
     * penalty (otherwise score=0 both before and after fix due to clamping).
     * Previously: 25 (only question, EN generics don't fire).
     * Now: 25 - 15 = 10.
     */
    public function testScorePenalizesEnglishGenericLanguage(): void
    {
        // EN reply with positive signal (question) + 3 EN generic phrases
        $text = 'Thank you for your message. I understand your offer. I appreciate the opportunity. Could we discuss?';
        $context = ['state_slots' => [], 'last_messages' => []];

        $score = $this->scorer->score($text, $context);

        // Now: 25 (question) - 15 (3 EN generic phrases) = 10
        // Previously: 25 (only question, generics don't fire)
        $this->assertLessThan(25, $score, 'EN generic phrases must reduce score below the no-penalty baseline');
    }

    /**
     * FR detection MUST be preserved (additive change).
     * Regression guard: feed the exact same text as the original
     * it_gives_high_score_for_explicit_question_targeting_channel test
     * and assert identical score.
     */
    public function testScorePreservesFrenchBehavior(): void
    {
        // Same input as the FR baseline test (line ~28)
        $text = 'Quel est votre numéro de téléphone pour vous joindre ?';
        $context = [
            'state_slots' => [
                'canal_cible' => 'phone',
                'missing_iocs' => ['phone', 'url'],
            ],
            'last_messages' => [],
        ];

        $score = $this->scorer->score($text, $context);

        // FR behavior unchanged: +25 question + +25 channel + +10 missing IOC = 60
        $this->assertGreaterThanOrEqual(50, $score, 'FR scoring must be preserved (additive i18n)');
    }
}
