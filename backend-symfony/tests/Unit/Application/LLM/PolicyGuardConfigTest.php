<?php

declare(strict_types=1);

namespace App\Tests\Unit\Application\LLM;

use App\Application\LLM\PolicyGuardConfig;
use PHPUnit\Framework\TestCase;

class PolicyGuardConfigTest extends TestCase
{
    public function testDefaultRange(): void
    {
        $config = PolicyGuardConfig::default();

        $this->assertSame(35, $config->minWords);
        $this->assertSame(150, $config->maxWords);
    }

    public function testFromContextDefault(): void
    {
        $config = PolicyGuardConfig::fromContext([]);

        $this->assertSame(35, $config->minWords);
        $this->assertSame(150, $config->maxWords);
    }

    public function testBotAccusationReducedRange(): void
    {
        $config = PolicyGuardConfig::fromContext(['is_bot_accusation' => true]);

        $this->assertSame(20, $config->minWords);
        $this->assertSame(70, $config->maxWords);
    }

    public function testBotAccusationViaToneRecommendation(): void
    {
        $config = PolicyGuardConfig::fromContext(['tone_recommendation' => 'déstabilisé']);

        $this->assertSame(20, $config->minWords);
        $this->assertSame(70, $config->maxWords);
    }

    public function testAggressionReducedRange(): void
    {
        $config = PolicyGuardConfig::fromContext(['is_aggression' => true]);

        $this->assertSame(25, $config->minWords);
        $this->assertSame(90, $config->maxWords);
    }

    public function testAggressionViaToneRecommendation(): void
    {
        $config = PolicyGuardConfig::fromContext(['tone_recommendation' => 'offensé']);

        $this->assertSame(25, $config->minWords);
        $this->assertSame(90, $config->maxWords);
    }

    public function testPostIbanRange(): void
    {
        $config = PolicyGuardConfig::fromContext(['is_post_iban' => true]);

        $this->assertSame(30, $config->minWords);
        $this->assertSame(100, $config->maxWords);
    }

    public function testEvasiveScammerRange(): void
    {
        $config = PolicyGuardConfig::fromContext(['is_evasive_scammer' => true]);

        $this->assertSame(30, $config->minWords);
        $this->assertSame(120, $config->maxWords);
    }

    public function testBotAccusationTakesPriorityOverAggression(): void
    {
        $config = PolicyGuardConfig::fromContext([
            'is_bot_accusation' => true,
            'is_aggression' => true,
        ]);

        // Bot accusation has highest priority in match
        $this->assertSame(20, $config->minWords);
        $this->assertSame(70, $config->maxWords);
    }

    public function testNormalConversationIgnoresFalseFlags(): void
    {
        $config = PolicyGuardConfig::fromContext([
            'is_bot_accusation' => false,
            'is_aggression' => false,
            'is_post_iban' => false,
            'is_evasive_scammer' => false,
        ]);

        $this->assertSame(35, $config->minWords);
        $this->assertSame(150, $config->maxWords);
    }
}
