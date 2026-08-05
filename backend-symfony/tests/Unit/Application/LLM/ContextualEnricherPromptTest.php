<?php

declare(strict_types=1);

namespace App\Tests\Unit\Application\LLM;

use App\Application\Communication\MessageAnonymizer;
use App\Application\LLM\ContextualEnricher;
use App\Application\LLM\Prompt\PromptProvider;
use App\Application\LLM\Port\LLMClientInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

/**
 * Tests for the contextual enrichment fallback prompt.
 *
 * Original coverage (F3 urgency calibration, F5 SHA256 role guidance)
 * is preserved via semantic assertions. Later
 * additions assert the new anti-bias guardrails for stimulus_type,
 * hesitation_detected, and language_switch_detected.
 */
final class ContextualEnricherPromptTest extends TestCase
{
    private string $promptText;

    protected function setUp(): void
    {
        $llmClient = $this->createMock(LLMClientInterface::class);
        $anonymizer = new MessageAnonymizer();
        $dispatcher = $this->createMock(EventDispatcherInterface::class);

        $enricher = new ContextualEnricher($llmClient, $anonymizer, $dispatcher, new NullLogger(), new PromptProvider('/nonexistent-prompt-dir', new NullLogger()));

        $ref = new \ReflectionMethod($enricher, 'fallbackPromptTemplate');
        $this->promptText = $ref->invoke($enricher);
    }

    // --- F3: Urgency prompt — anti-default + calibrated anchors ---

    public function test_prompt_contains_anti_default_instruction(): void
    {
        $this->assertStringContainsString(
            'Do NOT default to 0.5 or 0.75',
            $this->promptText,
            'Prompt must instruct LLM not to anchor on common default values',
        );
    }

    public function test_prompt_contains_calibration_anchors(): void
    {
        // v2 uses 6 calibrated anchors (0.05 / 0.20 / 0.40 / 0.60 / 0.80 / 0.95)
        // rather than the 10-bucket scale from F3 (which clustered around 0.5).
        foreach (['0.05', '0.20', '0.40', '0.60', '0.80', '0.95'] as $anchor) {
            $this->assertStringContainsString(
                $anchor,
                $this->promptText,
                "Prompt must contain urgency anchor {$anchor}",
            );
        }
    }

    // --- F5: SHA256 role guidance preserved ---

    public function test_prompt_contains_hash_signature_guidance(): void
    {
        // v2 uses "signature blocks, audit fingerprints, footers" instead
        // of the original F5 "footer" phrasing — assert any of the three.
        $found = str_contains($this->promptText, 'signature')
            || str_contains($this->promptText, 'footer')
            || str_contains($this->promptText, 'fingerprint');
        $this->assertTrue($found, 'Prompt must guide hash role for non-malware contexts');
    }

    public function test_prompt_contains_hash_identity_document_default(): void
    {
        $this->assertStringContainsString(
            'IDENTITY_DOCUMENT',
            $this->promptText,
            'Prompt must mention IDENTITY_DOCUMENT as default hash role',
        );
    }

    public function test_prompt_contains_hash_malware_inline_guidance(): void
    {
        $this->assertStringContainsString(
            'MALWARE_DOWNLOAD_URL',
            $this->promptText,
            'Prompt must mention MALWARE_DOWNLOAD_URL for inline hashes',
        );
    }

    // --- additions ---

    public function test_prompt_contains_anti_passive_bias_rule(): void
    {
        $this->assertStringContainsString(
            'ANTI-BIAS RULE',
            $this->promptText,
            'Prompt must contain explicit anti-PASSIVE-bias rule',
        );
        $this->assertStringContainsString(
            'PASSIVE is the LAST resort',
            $this->promptText,
            'Prompt must declare PASSIVE as last-resort, not default',
        );
    }

    public function test_prompt_contains_hesitation_strict_definition(): void
    {
        // v2 narrows hesitation_detected by explicitly excluding politeness
        // and delay apologies — those were the major FP source per Phase D.
        $this->assertStringContainsString(
            'politeness',
            $this->promptText,
            'Prompt must explicitly exclude politeness from hesitation detection',
        );
        $this->assertStringContainsString(
            'delay apology',
            $this->promptText,
            'Prompt must explicitly exclude delay apologies from hesitation detection',
        );
    }

    public function test_prompt_contains_language_switch_strict_definition(): void
    {
        // v2 narrows language_switch_detected to intra-message switches only,
        // excluding entire non-English emails (the major FP source per Phase D).
        $this->assertStringContainsString(
            'WITHIN this message',
            $this->promptText,
            'Prompt must require intra-message switch for language_switch=true',
        );
    }

    public function test_prompt_contains_phishing_url_tightening(): void
    {
        // v2 tightens PHISHING_CREDENTIAL_URL to credential-soliciting paths
        // only, sending marketing/notification URLs to INFRASTRUCTURE_DOMAIN.
        $this->assertStringContainsString(
            'INFRASTRUCTURE_DOMAIN, not PHISHING_CREDENTIAL_URL',
            $this->promptText,
            'Prompt must tighten URL classification rule',
        );
    }

    public function test_prompt_contains_excerpt_specificity_requirement(): void
    {
        $this->assertStringContainsString(
            'CONCRETE detail',
            $this->promptText,
            'Prompt must require concrete detail in context_excerpt',
        );
    }
}
