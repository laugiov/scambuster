<?php

declare(strict_types=1);

namespace App\Tests\Unit\Application\LLM;

use App\Application\Communication\PersonaManager;
use App\Application\LLM\ContextAnalyzer;
use App\Application\LLM\PromptBuilder;
use App\Application\LLM\ReciprocityManager;
use App\Application\LLM\VariationProvider;
use App\Domain\Communication\Persona;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Spec 080 §2 + §3 — verify the validator prompt extensions:
 *  - context block + identity coherence check (gated by
 *    $validatorContextEnabled)
 *  - structured `correction` field in the JSON schema (gated by
 *    $validatorStructuredCorrection)
 *
 * Uses string-contains assertions on the prompt content (no snapshot file
 * convention — per plan.md decision to keep assertions inline).
 */
final class PromptBuilderValidatorContextTest extends TestCase
{
    private function newBuilder(
        bool $contextEnabled = true,
        bool $structuredCorrection = true,
    ): PromptBuilder {
        $personaManager = $this->createMock(PersonaManager::class);
        $personaManager->method('findByCode')->willReturn(new Persona(
            'bank_customer',
            'Bank customer',
            'Worried but cooperative',
            'You are a worried bank customer.',
        ));

        return new PromptBuilder(
            contextAnalyzer: new ContextAnalyzer(),
            variationProvider: new VariationProvider(),
            reciprocityManager: new ReciprocityManager(),
            personaManager: $personaManager,
            logger: new NullLogger(),
            conversationAnalyzer: null,
            validatorContextEnabled: $contextEnabled,
            validatorStructuredCorrection: $structuredCorrection,
        );
    }

    public function test_legacy_2arg_call_preserves_documented_blocks(): void
    {
        $prompts = $this->newBuilder()->buildValidatorPrompts('some text', 'bank_customer');

        // Locks the legacy contract via inline assertStringContainsString
        // — no snapshot file (per plan.md decision).
        self::assertStringContainsString('Text to validate:', $prompts['user']);
        self::assertStringContainsString('Persona:', $prompts['user']);
        self::assertStringContainsString('Expected tone:', $prompts['user']);
        self::assertStringContainsString('Score each dimension 1-5', $prompts['user']);
    }

    public function test_legacy_2arg_call_does_NOT_contain_context_block(): void
    {
        $prompts = $this->newBuilder()->buildValidatorPrompts('some text', 'bank_customer');

        self::assertStringNotContainsString('## Conversational context', $prompts['user']);
        self::assertStringNotContainsString('## Identity coherence check', $prompts['user']);
    }

    public function test_context_block_appears_when_context_passed_and_flag_enabled(): void
    {
        $context = [
            'inbound_text' => 'Hello, I am Oscar from Expand InfoTech',
            'inbound_from' => 'oscar@expandinfotech.in',
            'previous_outbound_messages' => [],
            'language' => 'en',
        ];

        $prompts = $this->newBuilder()->buildValidatorPrompts('some reply', 'bank_customer', $context);

        self::assertStringContainsString('## Conversational context', $prompts['user']);
        self::assertStringContainsString('Hello, I am Oscar from Expand InfoTech', $prompts['user']);
        self::assertStringContainsString('oscar@expandinfotech.in', $prompts['user']);
    }

    public function test_identity_coherence_check_block_appears_with_four_conditions(): void
    {
        $context = [
            'inbound_text' => 'x',
            'inbound_from' => 'y@z.test',
            'previous_outbound_messages' => [],
            'language' => 'en',
        ];

        $prompts = $this->newBuilder()->buildValidatorPrompts('reply', 'bank_customer', $context);

        self::assertStringContainsString('## Identity coherence check', $prompts['user']);
        self::assertStringContainsString('Fail the security gate if ANY of these are true:', $prompts['user']);
        // 4 condition lines per spec 080 §2 — each contains its discriminative substring.
        self::assertStringContainsString("scammer's `from:` header", $prompts['user']);
        self::assertStringContainsString('[Your Name]', $prompts['user']);
        self::assertStringContainsString('sentinel must not sign with a name', $prompts['user']);
        self::assertStringContainsString('contradicts an identity claimed in a previous sentinel reply', $prompts['user']);
    }

    public function test_system_prompt_requests_correction_field_when_flag_enabled(): void
    {
        $prompts = $this->newBuilder(structuredCorrection: true)->buildValidatorPrompts('x', 'bank_customer');

        self::assertStringContainsString('"correction":{', $prompts['system']);
        self::assertStringContainsString('"problem_span"', $prompts['system']);
        self::assertStringContainsString('"replacement"', $prompts['system']);
        self::assertStringContainsString('"rationale"', $prompts['system']);
    }

    public function test_system_prompt_omits_correction_field_when_flag_disabled(): void
    {
        $prompts = $this->newBuilder(structuredCorrection: false)->buildValidatorPrompts('x', 'bank_customer');

        self::assertStringNotContainsString('"correction"', $prompts['system']);
    }

    public function test_when_context_flag_disabled_output_omits_context_block_even_if_passed(): void
    {
        $context = ['inbound_text' => 'x', 'inbound_from' => 'y@z', 'previous_outbound_messages' => [], 'language' => 'en'];

        $prompts = $this->newBuilder(contextEnabled: false)
            ->buildValidatorPrompts('reply', 'bank_customer', $context);

        // The flag-off path must keep the legacy substrings AND skip the
        // new blocks.
        self::assertStringContainsString('Text to validate:', $prompts['user']);
        self::assertStringNotContainsString('## Conversational context', $prompts['user']);
        self::assertStringNotContainsString('## Identity coherence check', $prompts['user']);
    }

    // ─── Spec 123 — role coherence + honeypot identity ──────────────────

    public function test_spec123_honeypot_email_appears_in_context_block_when_provided(): void
    {
        $context = [
            'inbound_text' => 'hello',
            'inbound_from' => 'scammer@evil.example',
            'honeypot_email' => 'admin@example-honeypot.test',
            'previous_outbound_messages' => [],
            'language' => 'en',
        ];

        $prompts = $this->newBuilder()->buildValidatorPrompts('reply', 'bank_customer', $context);

        self::assertStringContainsString('admin@example-honeypot.test', $prompts['user']);
    }

    public function test_spec123_role_coherence_directives_appear_in_identity_block(): void
    {
        $context = [
            'inbound_text' => 'hello',
            'inbound_from' => 'scammer@evil.example',
            'honeypot_email' => 'admin@example-honeypot.test',
            'previous_outbound_messages' => [],
            'language' => 'en',
        ];

        $prompts = $this->newBuilder()->buildValidatorPrompts('reply', 'bank_customer', $context);

        // Two new failure conditions in the identity-coherence block:
        // (1) role inversion — persona asks sender about its own org
        // (2) accepting an implied role from the sender without verification
        $userPromptLower = strtolower($prompts['user']);
        self::assertStringContainsString('role', $userPromptLower);
        self::assertMatchesRegularExpression(
            '/(invert|implied role|internal to)/i',
            $prompts['user'],
            'Identity coherence block should call out role inversion / accepting an implied role',
        );
    }

    public function test_spec123_block_renders_even_when_honeypot_email_missing(): void
    {
        // Backward compat: callers that don't provide honeypot_email should
        // still get the legacy context block + identity checks. The new
        // directives mention the honeypot generically when no email is set.
        $context = [
            'inbound_text' => 'hello',
            'inbound_from' => 'scammer@evil.example',
            'previous_outbound_messages' => [],
            'language' => 'en',
        ];

        $prompts = $this->newBuilder()->buildValidatorPrompts('reply', 'bank_customer', $context);

        self::assertStringContainsString('## Conversational context', $prompts['user']);
        self::assertStringContainsString('## Identity coherence check', $prompts['user']);
    }
}
