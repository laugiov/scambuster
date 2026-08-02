<?php

declare(strict_types=1);

namespace App\Tests\Unit\Application\LLM\Prompt;

use App\Application\LLM\Prompt\BasePromptRules;
use App\Application\LLM\Prompt\PromptCatalog;
use PHPUnit\Framework\TestCase;

final class PromptCatalogTest extends TestCase
{
    public function testKnownKeys(): void
    {
        self::assertSame(['contextual_enrichment', 'persona_style_rules', 'conversation_director_strategy', 'conversation_director_tone', 'reward_judge', 'ttp_extraction'], PromptCatalog::keys());
        self::assertTrue(PromptCatalog::isKnown('reward_judge'));
        self::assertFalse(PromptCatalog::isKnown('does_not_exist'));
    }

    public function testDescriptions(): void
    {
        self::assertNotSame('', PromptCatalog::description('contextual_enrichment'));
        self::assertSame('', PromptCatalog::description('unknown'));
    }

    public function testContextualEnrichmentRequiresItsEightTokens(): void
    {
        $required = PromptCatalog::requiredPlaceholders('contextual_enrichment');

        self::assertCount(8, $required);

        foreach (['{{SCAM_TYPE}}', '{{PERSONA_CODE}}', '{{IOC_TYPES}}', '{{REVELATION_MESSAGE}}'] as $token) {
            self::assertContains($token, $required);
        }
    }

    public function testRewardJudgeHasNoRequiredPlaceholders(): void
    {
        self::assertSame([], PromptCatalog::requiredPlaceholders('reward_judge'));
    }

    public function testUnknownKeyHasNoRequiredPlaceholders(): void
    {
        self::assertSame([], PromptCatalog::requiredPlaceholders('unknown'));
    }

    public function testAllReturnsFullMetadata(): void
    {
        $all = PromptCatalog::all();

        self::assertArrayHasKey('contextual_enrichment', $all);
        self::assertArrayHasKey('description', $all['contextual_enrichment']);
        self::assertArrayHasKey('required', $all['contextual_enrichment']);
        self::assertArrayHasKey('default', $all['contextual_enrichment']);
        self::assertArrayHasKey('canary_validatable', $all['contextual_enrichment']);
    }

    public function testCanaryValidatableReflectsReplySmokeCoverage(): void
    {
        // reward_judge (conversation-close), contextual_enrichment and ttp_extraction (ingest)
        // run OUTSIDE reply generation → the reply canary can't exercise them → no "Validate" action.
        self::assertFalse(PromptCatalog::canaryValidatable('contextual_enrichment'));
        self::assertFalse(PromptCatalog::canaryValidatable('reward_judge'));
        self::assertFalse(PromptCatalog::canaryValidatable('ttp_extraction'));
        // persona_style_rules is part of the generator SYSTEM prompt on every reply → covered.
        self::assertTrue(PromptCatalog::canaryValidatable('persona_style_rules'));
        // The director strategy/tone blocks are rendered by the analysis prompt the reply
        // generation runs on every turn (analyzer fires at message #2+) → covered.
        self::assertTrue(PromptCatalog::canaryValidatable('conversation_director_strategy'));
        self::assertTrue(PromptCatalog::canaryValidatable('conversation_director_tone'));
        self::assertFalse(PromptCatalog::canaryValidatable('unknown'));
    }

    public function testCanaryValidatableSetIsLockstepped(): void
    {
        // Lockstep drift-guard: the EXACT set of canary_validatable keys. Adding/flipping a key
        // here MUST be a conscious change made together with verifying the reply-objective smoke
        // actually resolves that key (else "Validate" returns a hollow verdict). If this fails,
        // confirm smoke coverage for the changed key and update this list.
        $validatable = array_values(array_filter(
            PromptCatalog::keys(),
            static fn (string $k): bool => PromptCatalog::canaryValidatable($k),
        ));

        self::assertSame(['persona_style_rules', 'conversation_director_strategy', 'conversation_director_tone'], $validatable);
    }

    public function testPersonaStyleRulesDefaultIsLockstepWithEditableRules(): void
    {
        // The catalog default the UI shows MUST equal the editable rules the runtime injects, so a
        // future edit to BasePromptRules can never silently diverge from the advertised default.
        self::assertSame(
            BasePromptRules::getEditableRules('en'),
            PromptCatalog::defaultBody('persona_style_rules'),
        );
    }

    public function testDefaultBodyReturnsTheShippedPrompt(): void
    {
        $enrichment = PromptCatalog::defaultBody('contextual_enrichment');
        self::assertNotSame('', $enrichment);
        self::assertStringContainsString('{{SCAM_TYPE}}', $enrichment);
        self::assertStringContainsString('{{REVELATION_MESSAGE}}', $enrichment);

        $reward = PromptCatalog::defaultBody('reward_judge');
        self::assertNotSame('', $reward);
        self::assertStringContainsString('outcome_score', $reward);
    }

    public function testDefaultBodyThrowsOnUnknownKey(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        PromptCatalog::defaultBody('does_not_exist');
    }

    /**
     * ZERO REGRESSION: the catalog defaults are byte-identical to the prompts the runtime
     * shipped before they were centralized here. Golden sha256 captured pre-move (the
     * enricher default is otherwise only self-referentially checked, so this is its real
     * byte-identity lock; the reward hash mirrors RewardJudgeRubricTest::GOLDEN_RUBRIC).
     */
    public function testDefaultsAreByteIdenticalToTheShippedPrompts(): void
    {
        self::assertSame(
            '45bdb5e09207d6af45dd205e0fb27863f0806db9a2b8b510a978f199552bf058',
            hash('sha256', PromptCatalog::defaultBody('contextual_enrichment')),
            'contextual_enrichment default drifted from the shipped prompt',
        );
        self::assertSame(
            '8bc2dc333285ef20cdf24152cbea44ca5a28dfc4966b192755576640b2501e2a',
            hash('sha256', PromptCatalog::defaultBody('reward_judge')),
            'reward_judge default drifted from the shipped rubric',
        );
        // The director blocks are extracted byte-for-byte from the analysis prompt; the assembled
        // prompt is also locked in ConversationAnalyzerDirectorPromptTest, but freeze each block
        // here too so an accidental catalog edit is caught at the source.
        self::assertSame(
            '73e3d5baff1d88699291cc40be1f2c030b16d0891fba045a81f2cf93a47de113',
            hash('sha256', PromptCatalog::defaultBody('conversation_director_strategy')),
            'conversation_director_strategy default drifted from the shipped director text',
        );
        self::assertSame(
            '950cac02a8fb40b7b0c998a939005444a57847ea25abbdadb50d634964e5b7bc',
            hash('sha256', PromptCatalog::defaultBody('conversation_director_tone')),
            'conversation_director_tone default drifted from the shipped director text',
        );
    }
}
