<?php

declare(strict_types=1);

namespace App\Tests\Unit\Application\Communication;

use App\Application\Communication\ConversationLifecycleConfig;
use PHPUnit\Framework\TestCase;

class ConversationLifecycleConfigTest extends TestCase
{
    public function testLongDurationScams(): void
    {
        // Romance: 14 days timeout, high engagement
        $this->assertSame(336, ConversationLifecycleConfig::getTimeoutHours('ROMANCE'));
        $this->assertSame(336, ConversationLifecycleConfig::getTimeoutHours('ROMANCE_SCAM'));
        $this->assertSame(50, ConversationLifecycleConfig::getMaxTurns('ROMANCE'));
        $this->assertSame(60, ConversationLifecycleConfig::getMaxDurationDays('ROMANCE'));

        // Investment: 7 days timeout, high engagement
        $this->assertSame(168, ConversationLifecycleConfig::getTimeoutHours('INVESTMENT'));
        $this->assertSame(40, ConversationLifecycleConfig::getMaxTurns('INVESTMENT'));
        $this->assertSame(45, ConversationLifecycleConfig::getMaxDurationDays('INVESTMENT'));

        // Advance fee 419: 7 days timeout
        $this->assertSame(168, ConversationLifecycleConfig::getTimeoutHours('ADVANCE_FEE_419'));
        $this->assertSame(40, ConversationLifecycleConfig::getMaxTurns('ADVANCE_FEE_419'));
        $this->assertSame(30, ConversationLifecycleConfig::getMaxDurationDays('ADVANCE_FEE_419'));
    }

    public function testMediumDurationScams(): void
    {
        $this->assertSame(72, ConversationLifecycleConfig::getTimeoutHours('INVOICE_FRAUD'));
        $this->assertSame(30, ConversationLifecycleConfig::getMaxTurns('INVOICE_FRAUD'));
        $this->assertSame(21, ConversationLifecycleConfig::getMaxDurationDays('INVOICE_FRAUD'));

        $this->assertSame(120, ConversationLifecycleConfig::getTimeoutHours('CEO_FRAUD'));
        $this->assertSame(25, ConversationLifecycleConfig::getMaxTurns('CEO_FRAUD'));
        $this->assertSame(14, ConversationLifecycleConfig::getMaxDurationDays('CEO_FRAUD'));
    }

    public function testShortDurationScams(): void
    {
        // All phishing variants: 48h
        $this->assertSame(48, ConversationLifecycleConfig::getTimeoutHours('PHISHING'));
        $this->assertSame(48, ConversationLifecycleConfig::getTimeoutHours('PHISH_CREDENTIALS'));
        $this->assertSame(48, ConversationLifecycleConfig::getTimeoutHours('PHISH_MALWARE'));
        $this->assertSame(15, ConversationLifecycleConfig::getMaxTurns('PHISH_MALWARE'));
        $this->assertSame(7, ConversationLifecycleConfig::getMaxDurationDays('PHISH_MALWARE'));

        // Tech support: shortest at 24h
        $this->assertSame(24, ConversationLifecycleConfig::getTimeoutHours('TECH_SUPPORT'));
        $this->assertSame(20, ConversationLifecycleConfig::getMaxTurns('TECH_SUPPORT'));
        $this->assertSame(5, ConversationLifecycleConfig::getMaxDurationDays('TECH_SUPPORT'));
    }

    public function testCasualScams(): void
    {
        foreach (['LOTTERY', 'JOB_OFFER', 'CHARITY'] as $type) {
            $this->assertSame(72, ConversationLifecycleConfig::getTimeoutHours($type), "$type timeout");
            $this->assertSame(25, ConversationLifecycleConfig::getMaxTurns($type), "$type max_turns");
            $this->assertSame(14, ConversationLifecycleConfig::getMaxDurationDays($type), "$type max_duration");
        }
    }

    public function testUnknownUsesDefaultPolicy(): void
    {
        $policy = ConversationLifecycleConfig::getPolicy('UNKNOWN');
        $this->assertSame(72, $policy['timeout_hours']);
        $this->assertSame(25, $policy['max_turns']);
        $this->assertSame(14, $policy['max_duration_days']);
        // Spec 095 Fix #15 — UNKNOWN bucket now allows reopen to recover
        // late follow-ups on unclassifiable inbound.
        $this->assertTrue($policy['allow_reopen']);
        $this->assertSame(72, $policy['reopen_window_hours']);
    }

    public function testNonExistentScamTypeUsesDefault(): void
    {
        $policy = ConversationLifecycleConfig::getPolicy('DOES_NOT_EXIST');
        $this->assertSame(72, $policy['timeout_hours']);
    }

    public function testCaseInsensitive(): void
    {
        $this->assertSame(336, ConversationLifecycleConfig::getTimeoutHours('romance'));
        $this->assertSame(48, ConversationLifecycleConfig::getTimeoutHours('phishing'));
        $this->assertSame(120, ConversationLifecycleConfig::getTimeoutHours('ceo_fraud'));
        $this->assertSame(168, ConversationLifecycleConfig::getTimeoutHours('investment'));
    }

    public function testReopenAllowedForLongEngagementScams(): void
    {
        // Romance, Investment, Advance Fee allow reopen
        $this->assertTrue(ConversationLifecycleConfig::allowsReopen('ROMANCE'));
        $this->assertSame(72, ConversationLifecycleConfig::getReopenWindowHours('ROMANCE'));

        $this->assertTrue(ConversationLifecycleConfig::allowsReopen('INVESTMENT'));
        $this->assertSame(48, ConversationLifecycleConfig::getReopenWindowHours('INVESTMENT'));

        $this->assertTrue(ConversationLifecycleConfig::allowsReopen('ADVANCE_FEE_419'));
        $this->assertSame(48, ConversationLifecycleConfig::getReopenWindowHours('ADVANCE_FEE_419'));
    }

    public function testReopenDeniedOnlyForLowValueScams_Fix15(): void
    {
        // Spec 095 Fix #15 — only LOTTERY + CHARITY still deny reopen
        // (low volume, low signal). All other short-window types now allow
        // reopen with a 72h window so late scammer follow-ups don't get lost.
        foreach (['LOTTERY', 'CHARITY'] as $type) {
            $this->assertFalse(ConversationLifecycleConfig::allowsReopen($type), "$type should not allow reopen");
            $this->assertSame(0, ConversationLifecycleConfig::getReopenWindowHours($type), "$type reopen window");
        }
    }

    public function testReopenAllowedForShortScams_Fix15(): void
    {
        // Spec 095 Fix #15 — short-window scam types that previously denied
        // reopen now allow it within a 72h window. Measured loss before fix:
        // PHISHING 17%, INVOICE_FRAUD 21%, TECH_SUPPORT 33%, JOB_OFFER 33%.
        foreach (['PHISHING', 'PHISH_CREDENTIALS', 'PHISH_MALWARE', 'TECH_SUPPORT', 'CEO_FRAUD', 'JOB_OFFER', 'INVOICE_FRAUD', 'UNKNOWN'] as $type) {
            $this->assertTrue(ConversationLifecycleConfig::allowsReopen($type), "$type should allow reopen post-Fix #15");
            $this->assertSame(72, ConversationLifecycleConfig::getReopenWindowHours($type), "$type reopen window should be 72h");
        }
    }

    public function testGetAllPoliciesReturnsAll14Types(): void
    {
        $all = ConversationLifecycleConfig::getAllPolicies();
        $expected = [
            'ROMANCE', 'ROMANCE_SCAM', 'INVESTMENT', 'ADVANCE_FEE_419',
            'INVOICE_FRAUD', 'CEO_FRAUD',
            'PHISHING', 'PHISH_CREDENTIALS', 'PHISH_MALWARE', 'TECH_SUPPORT',
            'LOTTERY', 'JOB_OFFER', 'CHARITY',
            'UNKNOWN',
        ];
        foreach ($expected as $type) {
            $this->assertArrayHasKey($type, $all, "Missing policy for $type");
        }
        $this->assertCount(14, $all);
    }
}
