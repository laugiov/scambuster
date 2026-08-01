<?php

declare(strict_types=1);

namespace App\Tests\Unit\Application\LLM;

use App\Application\Communication\PersonaManager;
use App\Application\LLM\ContextAnalyzer;
use App\Application\LLM\ConversationAnalyzer;
use App\Application\LLM\Port\LLMClientInterface;
use App\Application\LLM\PromptBuilder;
use App\Application\LLM\ReciprocityManager;
use App\Application\LLM\VariationProvider;
use App\Domain\Communication\Persona;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * The conversation director (LLM-reasoned) drives the VARIETY and OBJECTIVE
 * sections: it lists intel already obtained (so the persona never re-asks) and
 * supplies the per-turn objective, replacing the old regex extraction and the
 * static scam-type OBJECTIVE table.
 */
final class PromptBuilderDirectorTest extends TestCase
{
    private PersonaManager $personaManager;

    protected function setUp(): void
    {
        $persona = new Persona(
            'small_business_owner',
            'Small business owner',
            'Professional, time-pressed',
            'You are a small business owner managing invoices and vendors.',
        );
        $this->personaManager = $this->createMock(PersonaManager::class);
        $this->personaManager->method('findByCode')->willReturn($persona);
    }

    /**
     * @param array<string, mixed> $director
     */
    private function builderWithDirector(array $director): PromptBuilder
    {
        $analyzerResponse = json_encode([
            'strategic_analysis' => 'mocked',
            'repetitions_detected' => [],
            'tone_recommendation' => 'confident',
            'strategic_suggestions' => [],
            'instructions' => ['interdictions' => [], 'obligations' => [], 'objectif_strategique' => 'x', 'style_ton' => 'x'],
            'director' => $director,
        ], JSON_THROW_ON_ERROR);

        $llmClient = $this->createMock(LLMClientInterface::class);
        $llmClient->method('chat')->willReturn($analyzerResponse);

        return new PromptBuilder(
            new ContextAnalyzer(new NullLogger()),
            new VariationProvider(),
            new ReciprocityManager(new NullLogger()),
            $this->personaManager,
            new NullLogger(),
            new ConversationAnalyzer($llmClient, new NullLogger()),
        );
    }

    public function testPriorityOverrideCarriesNoLeakedConversationIds(): void
    {
        // Analyzer emits forbidden IOCs → PromptBuilder renders the PRIORITY
        // OVERRIDE block. That block must never leak internal conversation IDs
        // or loss dates into a prompt (they used to be hardcoded there).
        $analyzerResponse = json_encode([
            'strategic_analysis' => 'mocked',
            'repetitions_detected' => [],
            'tone_recommendation' => 'confident',
            'strategic_suggestions' => [],
            'instructions' => [
                'interdictions' => [],
                'obligations' => [],
                'forbidden_iocs' => ['BIC', 'SWIFT'],
                'pivot_to_iocs' => ['phone', 'postal address'],
            ],
        ], JSON_THROW_ON_ERROR);

        $llmClient = $this->createMock(LLMClientInterface::class);
        $llmClient->method('chat')->willReturn($analyzerResponse);

        $builder = new PromptBuilder(
            new ContextAnalyzer(new NullLogger()),
            new VariationProvider(),
            new ReciprocityManager(new NullLogger()),
            $this->personaManager,
            new NullLogger(),
            new ConversationAnalyzer($llmClient, new NullLogger()),
        );

        $user = (string) $builder->buildGeneratorPrompts($this->context(), 'small_business_owner')['user'];

        self::assertStringContainsString('## PRIORITY OVERRIDE', $user);
        self::assertStringContainsString('trigger bot detection', $user);
        self::assertStringNotContainsString('lost on', $user);
        self::assertDoesNotMatchRegularExpression('/\bconv [0-9a-f]{6,}/', $user);
    }

    /** @return array<string, mixed> */
    private function context(): array
    {
        return [
            'conv_id' => 'test-conv-director',
            'scam_type' => ['code' => 'COLD_SERVICE_SPAM', 'label' => 'Cold service spam'],
            'last_messages' => [
                ['direction' => 'in', 'body_text' => 'We can rebuild your website. Here is our address and CIN.', 'ts_msg' => '2026-01-01T00:00:00+00:00'],
                ['direction' => 'out', 'body_text' => 'Could you share your postal address?', 'ts_msg' => '2026-01-01T01:00:00+00:00'],
                ['direction' => 'in', 'body_text' => 'I already gave it. Are you a bot?', 'ts_msg' => '2026-01-01T02:00:00+00:00'],
            ],
            'detected_language' => 'en',
            'persona' => 'small_business_owner',
            'policy_min_words' => 50,
            'policy_max_words' => 150,
        ];
    }

    public function testDirectorObjectiveDrivesTheObjectiveSection(): void
    {
        $builder = $this->builderWithDirector([
            'already_obtained' => [],
            'mark_state' => 'stalling',
            'objective' => 'get them to name a price, then request an invoice and the payment method',
            'progress' => 'stalled',
            'next_move' => 'stop verifying; say you are satisfied and ask their rate',
            'should_continue' => true,
            'stop_reason' => '',
        ]);

        $user = (string) $builder->buildGeneratorPrompts($this->context(), 'small_business_owner')['user'];

        self::assertStringContainsString('Your objective for this exchange: get them to name a price', $user);
        self::assertStringContainsString('Your single next move in this message: stop verifying', $user);
        // The static scam-type fallback must NOT be used when the director spoke.
        self::assertStringNotContainsString('Stage: payment push', $user);
    }

    public function testAlreadyObtainedForbidsReAsking(): void
    {
        $builder = $this->builderWithDirector([
            'already_obtained' => ['postal address', 'registration number', 'client references'],
            'mark_state' => 'anti_bot_challenge',
            'objective' => 'acknowledge what you have and move to pricing',
            'progress' => 'stalled',
            'next_move' => 'do not re-ask; pivot',
            'should_continue' => true,
            'stop_reason' => '',
        ]);

        $user = (string) $builder->buildGeneratorPrompts($this->context(), 'small_business_owner')['user'];

        self::assertStringContainsString('You have ALREADY obtained', $user);
        self::assertStringContainsString('postal address', $user);
        self::assertStringContainsString('registration number', $user);
        self::assertStringContainsString('Do NOT ask for any of these again', $user);
    }

    public function testStyleDirectiveSurfacesInVarietySection(): void
    {
        $builder = $this->builderWithDirector([
            'already_obtained' => [],
            'mark_state' => 'stalling',
            'objective' => 'get their rate',
            'progress' => 'advancing',
            'next_move' => 'ask the rate',
            'style_directive' => 'answer only, ask nothing this turn',
            'should_continue' => true,
            'stop_reason' => '',
        ]);

        $user = (string) $builder->buildGeneratorPrompts($this->context(), 'small_business_owner')['user'];

        self::assertStringContainsString('Shape this reply as follows', $user);
        self::assertStringContainsString('answer only, ask nothing this turn', $user);
    }

    public function testNoStyleDirectiveKeepsVarietySectionQuiet(): void
    {
        // Empty style_directive → no "Shape this reply" line (safe degradation).
        $builder = $this->builderWithDirector([
            'already_obtained' => [],
            'mark_state' => 'cooperative',
            'objective' => 'get their rate',
            'progress' => 'advancing',
            'next_move' => 'ask the rate',
            'style_directive' => '',
            'should_continue' => true,
            'stop_reason' => '',
        ]);

        $user = (string) $builder->buildGeneratorPrompts($this->context(), 'small_business_owner')['user'];

        self::assertStringNotContainsString('Shape this reply as follows', $user);
    }

    public function testFallsBackToStaticObjectiveWhenDirectorHasNoObjective(): void
    {
        // Empty objective → director stays silent → the stage/scam fallback runs.
        $builder = $this->builderWithDirector([
            'already_obtained' => [],
            'mark_state' => 'cooperative',
            'objective' => '',
            'progress' => 'advancing',
            'next_move' => '',
            'should_continue' => true,
            'stop_reason' => '',
        ]);

        $user = (string) $builder->buildGeneratorPrompts($this->context(), 'small_business_owner')['user'];

        self::assertStringContainsString('## OBJECTIVE', $user);
        self::assertStringNotContainsString('Your objective for this exchange:', $user);
    }
}
