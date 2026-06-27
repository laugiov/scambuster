<?php

declare(strict_types=1);

namespace App\Tests\Unit\Application\LLM;

use App\Application\Communication\PersonaManager;
use App\Application\LLM\ContextAnalyzer;
use App\Application\LLM\PromptBuilder;
use App\Application\LLM\ReciprocityManager;
use App\Application\LLM\VariationProvider;
use App\Domain\Communication\Persona;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Mutation-killing tests for PromptBuilder.
 *
 * Targets:
 * - System prompt contains BasePromptRules
 * - User prompt has SITUATION section
 * - User prompt has RECENT MESSAGES section
 * - Missing IOCs listed in prompt
 * - Language override text present
 * - Word count limits in prompt
 * - OBJECTIVE section present
 * - VARIETY section present
 * - Locale neutralization for non-French
 * - Validator prompts structure
 */
final class PromptBuilderMutationTest extends TestCase
{
    private PromptBuilder $builder;
    private PersonaManager&MockObject $personaManager;

    protected function setUp(): void
    {
        $contextAnalyzer = new ContextAnalyzer();
        $variationProvider = new VariationProvider();
        $reciprocityManager = new ReciprocityManager();

        $this->personaManager = $this->createMock(PersonaManager::class);

        $persona = new Persona(
            'generic_user',
            'Generic User',
            'Neutral',
            'You are a friendly person who is curious about everything.',
        );
        $this->personaManager->method('findByCode')->willReturn($persona);

        $this->builder = new PromptBuilder(
            $contextAnalyzer,
            $variationProvider,
            $reciprocityManager,
            $this->personaManager,
            new NullLogger(),
        );
    }

    private function baseContext(array $overrides = []): array
    {
        return array_merge([
            'conv_id' => 'test-conv',
            'scam_type' => ['code' => 'PHISHING', 'label_fr' => 'Phishing'],
            'last_messages' => [
                ['direction' => 'in', 'body_text' => 'Hello target', 'headers' => ['from' => 'scammer@evil.com'], 'ts_msg' => '2026-01-01T10:00:00+00:00'],
            ],
            'detected_language' => 'en',
            'extracted_iocs' => [],
            'sender_history_summary' => null,
        ], $overrides);
    }

    // === System prompt ===

    public function test_system_prompt_contains_base_rules_no_knowledge_of_honeypots(): void
    {
        $prompts = $this->builder->buildGeneratorPrompts($this->baseContext(), 'generic_user');
        $this->assertStringContainsString('no knowledge of honeypots', $prompts['system']);
    }

    public function test_system_prompt_contains_base_rules_greeting(): void
    {
        $prompts = $this->builder->buildGeneratorPrompts($this->baseContext(), 'generic_user');
        $this->assertStringContainsString('starts emails with a greeting', $prompts['system']);
    }

    public function test_system_prompt_contains_persona_text(): void
    {
        $prompts = $this->builder->buildGeneratorPrompts($this->baseContext(), 'generic_user');
        $this->assertStringContainsString('friendly person', $prompts['system']);
    }

    public function test_system_prompt_contains_language_rule(): void
    {
        $prompts = $this->builder->buildGeneratorPrompts($this->baseContext(), 'generic_user');
        $this->assertStringContainsString('writes entirely in en', $prompts['system']);
    }

    // === SITUATION section ===

    public function test_user_prompt_has_situation_section(): void
    {
        $prompts = $this->builder->buildGeneratorPrompts($this->baseContext(), 'generic_user');
        $this->assertStringContainsString('## SITUATION', $prompts['user']);
    }

    public function test_situation_contains_threat_type(): void
    {
        $prompts = $this->builder->buildGeneratorPrompts($this->baseContext(), 'generic_user');
        $this->assertStringContainsString('threat_type: Phishing', $prompts['user']);
    }

    public function test_situation_contains_language(): void
    {
        $prompts = $this->builder->buildGeneratorPrompts($this->baseContext(), 'generic_user');
        $this->assertStringContainsString('language: en', $prompts['user']);
    }

    public function test_situation_contains_exchange_count(): void
    {
        $prompts = $this->builder->buildGeneratorPrompts($this->baseContext(), 'generic_user');
        $this->assertStringContainsString('exchange_count:', $prompts['user']);
    }

    public function test_situation_contains_stage(): void
    {
        $prompts = $this->builder->buildGeneratorPrompts($this->baseContext(), 'generic_user');
        $this->assertStringContainsString('stage:', $prompts['user']);
    }

    // === RECENT MESSAGES section ===

    public function test_user_prompt_has_recent_messages_section(): void
    {
        $prompts = $this->builder->buildGeneratorPrompts($this->baseContext(), 'generic_user');
        $this->assertStringContainsString('## RECENT MESSAGES', $prompts['user']);
    }

    public function test_recent_messages_contains_attacker_label(): void
    {
        $prompts = $this->builder->buildGeneratorPrompts($this->baseContext(), 'generic_user');
        $this->assertStringContainsString('Attacker', $prompts['user']);
    }

    public function test_recent_messages_contains_message_body(): void
    {
        $prompts = $this->builder->buildGeneratorPrompts($this->baseContext(), 'generic_user');
        $this->assertStringContainsString('Hello target', $prompts['user']);
    }

    public function test_recent_messages_contains_from_header(): void
    {
        $prompts = $this->builder->buildGeneratorPrompts($this->baseContext(), 'generic_user');
        $this->assertStringContainsString('scammer@evil.com', $prompts['user']);
    }

    public function test_empty_messages_shows_first_exchange(): void
    {
        $context = $this->baseContext(['last_messages' => []]);
        $prompts = $this->builder->buildGeneratorPrompts($context, 'generic_user');
        $this->assertStringContainsString('No prior messages', $prompts['user']);
    }

    // === OBJECTIVE section ===

    public function test_user_prompt_has_objective_section(): void
    {
        $prompts = $this->builder->buildGeneratorPrompts($this->baseContext(), 'generic_user');
        $this->assertStringContainsString('## OBJECTIVE', $prompts['user']);
    }

    public function test_objective_contains_target_length(): void
    {
        $prompts = $this->builder->buildGeneratorPrompts($this->baseContext(), 'generic_user');
        $this->assertStringContainsString('Target length:', $prompts['user']);
    }

    public function test_objective_word_count_defaults_50_150(): void
    {
        $prompts = $this->builder->buildGeneratorPrompts($this->baseContext(), 'generic_user');
        $this->assertStringContainsString('50-150 words', $prompts['user']);
    }

    public function test_objective_custom_word_limits(): void
    {
        $context = $this->baseContext(['policy_min_words' => 30, 'policy_max_words' => 100]);
        $prompts = $this->builder->buildGeneratorPrompts($context, 'generic_user');
        $this->assertStringContainsString('30-100 words', $prompts['user']);
    }

    // === Language override ===

    public function test_language_override_mentions_english(): void
    {
        $prompts = $this->builder->buildGeneratorPrompts($this->baseContext(), 'generic_user');
        $this->assertStringContainsString('LANGUAGE OVERRIDE', $prompts['user']);
        $this->assertStringContainsString('English', $prompts['user']);
    }

    public function test_language_override_mentions_french_for_fr(): void
    {
        $context = $this->baseContext(['detected_language' => 'fr']);
        $prompts = $this->builder->buildGeneratorPrompts($context, 'generic_user');
        $this->assertStringContainsString('French', $prompts['user']);
    }

    public function test_language_override_mentions_spanish_for_es(): void
    {
        $context = $this->baseContext(['detected_language' => 'es']);
        $prompts = $this->builder->buildGeneratorPrompts($context, 'generic_user');
        $this->assertStringContainsString('Spanish', $prompts['user']);
    }

    public function test_language_override_mentions_german_for_de(): void
    {
        $context = $this->baseContext(['detected_language' => 'de']);
        $prompts = $this->builder->buildGeneratorPrompts($context, 'generic_user');
        $this->assertStringContainsString('German', $prompts['user']);
    }

    // === VARIETY section ===

    public function test_user_prompt_has_variety_section(): void
    {
        $prompts = $this->builder->buildGeneratorPrompts($this->baseContext(), 'generic_user');
        $this->assertStringContainsString('## VARIETY', $prompts['user']);
    }

    // === Missing IOCs ===

    public function test_missing_iocs_listed_when_present(): void
    {
        // With enough messages, context analyzer detects missing IOCs
        $context = $this->baseContext([
            'last_messages' => [
                ['direction' => 'in', 'body_text' => 'Hello from scammer', 'headers' => ['from' => 'bad@evil.com'], 'ts_msg' => '2026-01-01T10:00:00+00:00'],
                ['direction' => 'out', 'body_text' => 'I got your message', 'headers' => ['from' => 'me@good.com'], 'ts_msg' => '2026-01-01T11:00:00+00:00'],
            ],
        ]);
        $prompts = $this->builder->buildGeneratorPrompts($context, 'generic_user');
        // Spec 095 Fix #6 replaced the weak "If natural" directive with a
        // stage-aware match expression; we now assert the OBJECTIVE section
        // is still present (test name kept for historical traceability).
        $this->assertStringContainsString('## OBJECTIVE', $prompts['user']);
    }

    // ─── Spec 095 Fix #6 — stage-aware OBJECTIVE directive ──────────────

    /**
     * Spec 095 Fix #6 — first_contact stage (1-2 messages, no payment kw).
     * The OBJECTIVE must instruct the LLM to ask about the offer, NOT
     * about payment specifics. Asking for IBAN on turn 1 is a bot tell.
     *
     * See: specs/095-pipeline-audit/fix-05-06-coherent-ioc-directive/spec.md
     */
    public function test_objective_first_contact_directive_Fix06(): void
    {
        $context = $this->baseContext([
            'last_messages' => [
                ['direction' => 'in', 'body_text' => 'Hi, I have an opportunity for you', 'headers' => ['from' => 'a@b.com'], 'ts_msg' => '2026-01-01T10:00:00+00:00'],
            ],
        ]);
        $prompts = $this->builder->buildGeneratorPrompts($context, 'generic_user');
        $this->assertStringContainsString('first contact', $prompts['user'], 'OBJECTIVE must mention stage');
        $this->assertStringContainsString('Hold off on payment', $prompts['user'], 'OBJECTIVE must defer payment-specifics on first_contact');
    }

    /**
     * Spec 095 Fix #6 — follow_up stage (3-5 messages, no payment keyword).
     * The OBJECTIVE must ask a practical question, not yet a specific IOC.
     */
    public function test_objective_follow_up_directive_Fix06(): void
    {
        // 3-5 messages WITHOUT payment keyword → ContextAnalyzer returns follow_up
        $context = $this->baseContext([
            'last_messages' => [
                ['direction' => 'in', 'body_text' => 'Hi', 'headers' => ['from' => 'a@b.com'], 'ts_msg' => '2026-01-01T10:00:00+00:00'],
                ['direction' => 'out', 'body_text' => 'Hello', 'headers' => ['from' => 'me@g.com'], 'ts_msg' => '2026-01-01T11:00:00+00:00'],
                ['direction' => 'in', 'body_text' => 'Tell me more about you', 'headers' => ['from' => 'a@b.com'], 'ts_msg' => '2026-01-01T12:00:00+00:00'],
                ['direction' => 'out', 'body_text' => 'Sure I am happy to share', 'headers' => ['from' => 'me@g.com'], 'ts_msg' => '2026-01-01T13:00:00+00:00'],
            ],
        ]);
        $prompts = $this->builder->buildGeneratorPrompts($context, 'generic_user');
        $this->assertStringContainsString('follow-up', $prompts['user'], 'OBJECTIVE must mention follow-up stage');
        $this->assertStringContainsString('practical question', $prompts['user'], 'OBJECTIVE must request a practical question');
    }

    /**
     * Spec 095 Fix #6 — payment_push stage (any message with payment keyword OR 6+ messages).
     * The OBJECTIVE must explicitly request a concrete IOC question.
     *
     * Spec 118 update: the concrete-IOC anchors are now scam-type-aware.
     * `baseContext` uses scam_type=PHISHING, which routes to the phishing_pull
     * bucket — the bucket asks about "exact site", "credential", "attachment",
     * "card details" instead of IBAN/wallet. We assert the phishing_pull-
     * specific anchors here. The Fix06 invariant (no weak directive +
     * concrete IOC at payment_push) is preserved through different wording.
     * The banking bucket continues to list IBAN/SWIFT/wallet — covered by
     * `PromptBuilderTest::test_spec118_uses_banking_template_for_*`.
     */
    public function test_objective_payment_push_directive_Fix06(): void
    {
        // Payment keyword in any of the last 3 messages → ContextAnalyzer returns payment_push
        $context = $this->baseContext([
            'last_messages' => [
                ['direction' => 'in', 'body_text' => 'Please send me 1000 euros via virement to my IBAN', 'headers' => ['from' => 'a@b.com'], 'ts_msg' => '2026-01-01T10:00:00+00:00'],
            ],
        ]);
        $prompts = $this->builder->buildGeneratorPrompts($context, 'generic_user');
        $this->assertStringContainsString('payment push', $prompts['user'], 'OBJECTIVE must mention payment push stage');
        $this->assertStringContainsString('phishing-style request', $prompts['user'], 'OBJECTIVE must announce the phishing_pull bucket for PHISHING scam_type (spec 118)');
        $this->assertStringContainsString('exact site', $prompts['user'], 'OBJECTIVE must list exact-site IOC anchor for phishing_pull (spec 118)');
        $this->assertStringContainsString('credential', $prompts['user'], 'OBJECTIVE must list credential IOC anchor for phishing_pull (spec 118)');
    }

    /**
     * Spec 095 Fix #6 — the old weak "If natural, try to obtain" directive
     * MUST be gone. Its replacement is the stage-aware match expression
     * tested above.
     */
    public function test_objective_no_weak_if_natural_directive_Fix06(): void
    {
        $prompts = $this->builder->buildGeneratorPrompts($this->baseContext(), 'generic_user');
        $this->assertStringNotContainsString('If natural, try to obtain', $prompts['user'], 'Weak directive must be removed');
    }

    // === Vary opening for multi-message threads ===

    public function test_multi_message_thread_mentions_vary_opening(): void
    {
        $context = $this->baseContext([
            'last_messages' => [
                ['direction' => 'in', 'body_text' => 'Hello', 'headers' => ['from' => 'a@b.com'], 'ts_msg' => '2026-01-01T10:00:00+00:00'],
                ['direction' => 'out', 'body_text' => 'Reply', 'headers' => ['from' => 'me@g.com'], 'ts_msg' => '2026-01-01T11:00:00+00:00'],
            ],
        ]);
        $prompts = $this->builder->buildGeneratorPrompts($context, 'generic_user');
        $this->assertStringContainsString('Vary your opening', $prompts['user']);
    }

    // === No placeholders ===

    public function test_no_placeholders_instruction_present(): void
    {
        $prompts = $this->builder->buildGeneratorPrompts($this->baseContext(), 'generic_user');
        $this->assertStringContainsString('Never use placeholders', $prompts['user']);
    }

    // === Write your reply now ===

    public function test_prompt_ends_with_write_reply_now(): void
    {
        $prompts = $this->builder->buildGeneratorPrompts($this->baseContext(), 'generic_user');
        $this->assertStringEndsWith('Write your reply now.', $prompts['user']);
    }

    // === Validator prompts ===

    public function test_validator_system_prompt_mentions_auditor(): void
    {
        $prompts = $this->builder->buildValidatorPrompts('Test text', 'generic_user');
        $this->assertStringContainsString('auditor', $prompts['system']);
    }

    public function test_validator_system_prompt_mentions_naturalness(): void
    {
        $prompts = $this->builder->buildValidatorPrompts('Test text', 'generic_user');
        $this->assertStringContainsString('naturalness', $prompts['system']);
    }

    public function test_validator_system_prompt_mentions_persona_fit(): void
    {
        $prompts = $this->builder->buildValidatorPrompts('Test text', 'generic_user');
        $this->assertStringContainsString('persona_fit', $prompts['system']);
    }

    public function test_validator_system_prompt_mentions_ti_value(): void
    {
        $prompts = $this->builder->buildValidatorPrompts('Test text', 'generic_user');
        $this->assertStringContainsString('ti_value', $prompts['system']);
    }

    public function test_validator_user_prompt_contains_generated_text(): void
    {
        $prompts = $this->builder->buildValidatorPrompts('My special test text here', 'generic_user');
        $this->assertStringContainsString('My special test text here', $prompts['user']);
    }

    public function test_validator_user_prompt_contains_persona_label(): void
    {
        $prompts = $this->builder->buildValidatorPrompts('Test text', 'generic_user');
        $this->assertStringContainsString('Generic User', $prompts['user']);
    }

    public function test_validator_user_prompt_contains_expected_tone(): void
    {
        $prompts = $this->builder->buildValidatorPrompts('Test text', 'generic_user');
        $this->assertStringContainsString('Neutral', $prompts['user']);
    }

    public function test_validator_system_mentions_security_gate(): void
    {
        $prompts = $this->builder->buildValidatorPrompts('Test text', 'generic_user');
        $this->assertStringContainsString('Security gate', $prompts['system']);
    }

    // === Locale neutralization ===

    public function test_non_french_language_adds_language_constraint_prefix(): void
    {
        $prompts = $this->builder->buildGeneratorPrompts($this->baseContext(), 'generic_user');
        $this->assertStringContainsString('WRITE EXCLUSIVELY IN English', $prompts['system']);
    }

    public function test_french_language_does_not_add_constraint_prefix(): void
    {
        $context = $this->baseContext(['detected_language' => 'fr']);
        $prompts = $this->builder->buildGeneratorPrompts($context, 'generic_user');
        $this->assertStringNotContainsString('WRITE EXCLUSIVELY IN', $prompts['system']);
    }

    // === Sender history ===

    public function test_sender_history_included_when_present(): void
    {
        $context = $this->baseContext(['sender_history_summary' => '3 prior conversations detected.']);
        $prompts = $this->builder->buildGeneratorPrompts($context, 'generic_user');
        $this->assertStringContainsString('Prior exchanges', $prompts['user']);
        $this->assertStringContainsString('3 prior conversations', $prompts['user']);
    }

    public function test_sender_history_absent_when_null(): void
    {
        $context = $this->baseContext(['sender_history_summary' => null]);
        $prompts = $this->builder->buildGeneratorPrompts($context, 'generic_user');
        $this->assertStringNotContainsString('Prior exchanges', $prompts['user']);
    }

    // === Generation dialogue (retry context) ===

    public function test_generation_dialogue_included_when_present(): void
    {
        $context = $this->baseContext([
            'generation_dialogue' => [
                ['role' => 'Generator (attempt 1)', 'content' => 'First attempt text'],
                ['role' => 'Validator (attempt 1)', 'content' => 'REJECTED'],
            ],
        ]);
        $prompts = $this->builder->buildGeneratorPrompts($context, 'generic_user');
        $this->assertStringContainsString('Previous attempts', $prompts['user']);
        $this->assertStringContainsString('Fix the issues above', $prompts['user']);
    }
}
