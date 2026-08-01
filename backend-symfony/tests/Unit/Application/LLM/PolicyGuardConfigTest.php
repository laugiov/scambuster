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

        $this->assertSame(20, $config->minWords);
        $this->assertSame(150, $config->maxWords);
    }

    public function testFromContextDefault(): void
    {
        $config = PolicyGuardConfig::fromContext([]);

        $this->assertSame(20, $config->minWords);
        $this->assertSame(150, $config->maxWords);
    }

    public function testBotAccusationReducedRange(): void
    {
        $config = PolicyGuardConfig::fromContext(['is_bot_accusation' => true]);

        $this->assertSame(12, $config->minWords);
        $this->assertSame(70, $config->maxWords);
    }

    public function testBotAccusationViaToneRecommendation(): void
    {
        $config = PolicyGuardConfig::fromContext(['tone_recommendation' => 'unsettled']);

        $this->assertSame(12, $config->minWords);
        $this->assertSame(70, $config->maxWords);
    }

    public function testAggressionReducedRange(): void
    {
        $config = PolicyGuardConfig::fromContext(['is_aggression' => true]);

        $this->assertSame(15, $config->minWords);
        $this->assertSame(90, $config->maxWords);
    }

    public function testAggressionViaToneRecommendation(): void
    {
        $config = PolicyGuardConfig::fromContext(['tone_recommendation' => 'offended']);

        $this->assertSame(15, $config->minWords);
        $this->assertSame(90, $config->maxWords);
    }

    public function testPostIbanRange(): void
    {
        $config = PolicyGuardConfig::fromContext(['is_post_iban' => true]);

        $this->assertSame(18, $config->minWords);
        $this->assertSame(100, $config->maxWords);
    }

    public function testEvasiveScammerRange(): void
    {
        $config = PolicyGuardConfig::fromContext(['is_evasive_scammer' => true]);

        $this->assertSame(18, $config->minWords);
        $this->assertSame(120, $config->maxWords);
    }

    public function testBotAccusationTakesPriorityOverAggression(): void
    {
        $config = PolicyGuardConfig::fromContext([
            'is_bot_accusation' => true,
            'is_aggression' => true,
        ]);

        // Bot accusation has highest priority in match
        $this->assertSame(12, $config->minWords);
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

        $this->assertSame(20, $config->minWords);
        $this->assertSame(150, $config->maxWords);
    }

    /**
     * Terse persona archetypes (identified by keyword on the persona's
     * free-text tone descriptor) get a floor their mandated style can
     * actually meet. The style validator demands telegraphic/impulsive
     * brevity; a 35-word floor made those two gates unsatisfiable
     * together and shipped fallbacks at attempt 3 (32-33 words).
     *
     * @dataProvider tersePersonaToneProvider
     */
    public function testTersePersonaToneReducesFloor(string $personaTone): void
    {
        $config = PolicyGuardConfig::fromContext(['persona_tone' => $personaTone]);

        $this->assertSame(12, $config->minWords);
        $this->assertSame(120, $config->maxWords);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function tersePersonaToneProvider(): array
    {
        return [
            'telegraphic entrepreneur' => ['Telegraphic, typo-prone, business jargon'],
            'concise business owner' => ['Direct, time-conscious, concise'],
            'abbreviated student' => ['Abbreviated, impatient, attention mistakes'],
            'short elderly person' => ['Short, affectionate, family-focused'],
            'uppercase keyword' => ['TERSE and pragmatic'],
        ];
    }

    public function testVerbosePersonaToneKeepsDefaultFloor(): void
    {
        $config = PolicyGuardConfig::fromContext(['persona_tone' => 'Warm, rambling, off-topic']);

        $this->assertSame(20, $config->minWords);
        $this->assertSame(150, $config->maxWords);
    }

    public function testSituationalOverridesKeepPrecedenceOverTerseTone(): void
    {
        // A terse persona in a bot-accusation moment gets the
        // bot-accusation band, not the terse band.
        $config = PolicyGuardConfig::fromContext([
            'is_bot_accusation' => true,
            'persona_tone' => 'Telegraphic, typo-prone, business jargon',
        ]);

        $this->assertSame(12, $config->minWords);
        $this->assertSame(70, $config->maxWords);

        $aggression = PolicyGuardConfig::fromContext([
            'is_aggression' => true,
            'persona_tone' => 'Direct, time-conscious, concise',
        ]);

        $this->assertSame(15, $aggression->minWords);
        $this->assertSame(90, $aggression->maxWords);
    }

    public function testMissingOrNonStringPersonaToneKeepsDefault(): void
    {
        $this->assertSame(20, PolicyGuardConfig::fromContext(['persona_tone' => null])->minWords);
        $this->assertSame(20, PolicyGuardConfig::fromContext(['persona_tone' => 42])->minWords);
    }
}
