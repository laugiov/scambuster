<?php

declare(strict_types=1);

namespace App\Tests\Unit\Application\LLM\Prompt;

use App\Application\LLM\Prompt\BasePromptRules;
use PHPUnit\Framework\TestCase;

class BasePromptRulesTest extends TestCase
{
    public function testRulesContainSecurityConstraint(): void
    {
        $rules = BasePromptRules::getRules('en');

        $this->assertStringContainsString('honeypot', $rules);
        $this->assertStringContainsString('no knowledge', $rules);
    }

    public function testRulesContainFormatConstraint(): void
    {
        $rules = BasePromptRules::getRules('en');

        $this->assertStringContainsString('greeting', $rules);
        $this->assertStringContainsString('subject line', $rules);
    }

    public function testRulesContainLanguagePlaceholder(): void
    {
        $rules = BasePromptRules::getRules('fr');

        $this->assertStringContainsString('fr', $rules);
        $this->assertStringContainsString('Every single word', $rules);
    }

    public function testRulesAreUnder250Words(): void
    {
        // Cap history:
        //   - Spec 095/112 raised cap to 170 to fit no-out-of-band-channel rule.
        //   - Spec 117 (careful-buyer pushback) + headroom for spec 122
        //     (anti-repetition rule) → cap set at 250.
        // Keep the cap close to actual size so future drift is caught early.
        $rules = BasePromptRules::getRules('en');
        $wordCount = str_word_count($rules);

        $this->assertLessThan(250, $wordCount, "BasePromptRules should be under 250 words, got {$wordCount}");
    }

    public function testRulesUsePositiveDescriptions(): void
    {
        $rules = BasePromptRules::getRules('en');

        // No negative instructions
        $this->assertStringNotContainsString('NEVER', $rules);
        $this->assertStringNotContainsString('DO NOT', $rules);
        $this->assertStringNotContainsString('FORBIDDEN', $rules);
        $this->assertStringNotContainsString('INTERDIT', $rules);
        $this->assertStringNotContainsString('NE JAMAIS', $rules);
    }

    public function testRulesWithDifferentLanguages(): void
    {
        $rulesEn = BasePromptRules::getRules('en');
        $rulesFr = BasePromptRules::getRules('fr');

        $this->assertStringContainsString('en', $rulesEn);
        $this->assertStringContainsString('fr', $rulesFr);
    }

    /**
     * Spec 095 Fix #5 — BasePromptRules now includes a behavioral rule telling
     * the persona how to react to payment cues (descriptive, not prescriptive).
     * The rule is intentionally short to fit within the 120-word budget
     * (verified by testRulesAreUnder120Words).
     *
     * See: specs/095-pipeline-audit/fix-05-06-coherent-ioc-directive/spec.md
     */
    public function testRulesIncludePaymentCueRule_Fix05(): void
    {
        $rules = BasePromptRules::getRules('en');

        // The behavioral rule must reference both branches: payment-mentioned
        // and payment-not-mentioned. Wording is intentionally conversational.
        $this->assertStringContainsString('payment', $rules, 'Rule must reference payment cue');
        $this->assertStringContainsString('how to send', $rules, 'Rule must mention how to send (the IOC-pull behavior)');
        $this->assertStringContainsString('offer', $rules, 'Rule must reference the fallback: ask about the offer when payment not mentioned');
    }

    /**
     * Spec 112 — BasePromptRules must instruct the persona never to share an
     * out-of-band contact channel (phone, messaging handle, crypto wallet,
     * IBAN, postal address), even a fictional one. The prompt-side rule
     * pairs with PolicyGuard's server-side regex block so the model does
     * not even attempt a leak (saves validator retries).
     *
     * See: specs/112-no-out-of-band-channel/spec.md
     */
    public function testRulesBanOutOfBandChannels_Spec112(): void
    {
        $rules = BasePromptRules::getRules('en');

        $this->assertStringContainsString('phone', $rules, 'Rule must call out phone numbers');
        $this->assertStringContainsString('WhatsApp', $rules, 'Rule must call out WhatsApp');
        $this->assertStringContainsString('crypto wallet', $rules, 'Rule must call out crypto wallets');
        $this->assertStringContainsString('IBAN', $rules, 'Rule must call out IBAN');
        $this->assertStringContainsString('fictional', $rules, 'Rule must explicitly ban fictional channels too');
    }

    /**
     * Spec 117 — BasePromptRules must instruct the persona to behave like a
     * careful buyer when the attacker pushes for upfront payment before any
     * contract or scope of work: ask for SoW / invoice / company verification
     * first, defer rather than refuse, stay calm if the attacker escalates
     * or pivots to out-of-band channels.
     *
     * The discriminator between a legitimate vendor and a scammer is the
     * reaction to this paperwork ask: a real vendor calmly produces it;
     * a scammer escalates pressure, pivots channels, or offers a personal
     * account. The persona's restraint becomes the test.
     *
     * See: specs/117-legitimate-buyer-pushback/spec.md
     */
    public function testRulesIncludeBuyerPushbackRule_Spec117(): void
    {
        $rules = BasePromptRules::getRules('en');

        // The rule must reference each anchor concept so the LLM understands
        // the full discriminator pattern: paperwork ask + polite firmness +
        // defer-not-refuse + stay-calm-on-escalation.
        $this->assertStringContainsString('Statement of Work', $rules, 'Rule must explicitly call out SoW as a paperwork ask');
        $this->assertStringContainsString('company registration', $rules, 'Rule must include company verification ask');
        $this->assertStringContainsString('defer', $rules, 'Rule must instruct to defer rather than refuse');
        $this->assertStringContainsString('upfront payment', $rules, 'Rule must scope to upfront-payment pressure');
        $this->assertStringContainsString('personal-looking bank account', $rules, 'Rule must call out the personal-account escalation pattern');
    }
}
