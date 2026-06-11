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
 * Spec 095 Fix #18 — PromptBuilder must inject a "## PRIORITY OVERRIDE"
 * section AFTER the OBJECTIVE when `forbidden_iocs` is non-empty in the
 * analyzer's output, and produce a bit-identical prompt when empty.
 *
 * These tests cover regression vectors V3 (PromptBuilder structure) and V4
 * (baseline non-regression).
 *
 * See: specs/095-pipeline-audit/fix-18-priority-override-via-forbidden-iocs/spec.md
 */
final class PromptBuilderForbiddenIocsTest extends TestCase
{
    private PersonaManager $personaManager;
    private Persona $persona;

    protected function setUp(): void
    {
        $this->persona = new Persona(
            'small_business_owner',
            'Small business owner',
            'Professional, time-pressed',
            'You are a small business owner managing invoices and vendors.',
        );

        $this->personaManager = $this->createMock(PersonaManager::class);
        $this->personaManager->method('findByCode')->willReturn($this->persona);
    }

    /**
     * Build a PromptBuilder with a real ConversationAnalyzer backed by a
     * mocked LLMClient that returns the given $instructions payload in
     * the JSON response. We can't `createMock(ConversationAnalyzer::class)`
     * because the analyzer is declared `final`; mocking the LLMClient
     * port underneath is the cleanest workaround.
     */
    private function buildBuilderWithAnalysisInstructions(array $instructions): PromptBuilder
    {
        // Compose a valid analyzer JSON response embedding the $instructions
        $analyzerResponse = json_encode([
            'strategic_analysis' => 'mocked analysis',
            'repetitions_detected' => [],
            'tone_recommendation' => 'confident',
            'strategic_suggestions' => [],
            'instructions' => array_merge([
                'interdictions' => [],
                'obligations' => [],
                'objectif_strategique' => 'mocked',
                'style_ton' => 'mocked',
            ], $instructions),
        ], JSON_THROW_ON_ERROR);

        $llmClient = $this->createMock(LLMClientInterface::class);
        $llmClient->method('chat')->willReturn($analyzerResponse);

        $analyzer = new ConversationAnalyzer($llmClient, new NullLogger());

        return new PromptBuilder(
            new ContextAnalyzer(new NullLogger()),
            new VariationProvider(),
            new ReciprocityManager(new NullLogger()),
            $this->personaManager,
            new NullLogger(),
            $analyzer,
        );
    }

    /**
     * Build a context that triggers the payment_push stage (this is where the
     * bug was observed — OBJECTIVE hardcoded the IOC list, no pivot logic).
     */
    private function buildPaymentPushContext(): array
    {
        return [
            'conv_id' => 'test-conv-fix18',
            'scam_type' => ['code' => 'ADVANCE_FEE_419', 'label' => 'Advance Fee Fraud'],
            'last_messages' => [
                ['direction' => 'in', 'body_text' => 'Hi, please send the IBAN for the virement once we finalize the project.', 'ts_msg' => '2026-01-01T00:00:00+00:00'],
                ['direction' => 'out', 'body_text' => 'Could you share the BIC and SWIFT code for the virement?', 'ts_msg' => '2026-01-01T01:00:00+00:00'],
                ['direction' => 'in', 'body_text' => 'I will provide our IBAN and bank account details once we finalize the project.', 'ts_msg' => '2026-01-01T02:00:00+00:00'],
            ],
            'detected_language' => 'en',
            'persona' => 'small_business_owner',
            'policy_min_words' => 50,
            'policy_max_words' => 150,
        ];
    }

    /**
     * Spec 095 Fix #18 / V3 regression — when `forbidden_iocs` is empty
     * (the dominant case, ~99.75 % of replies), the prompt must NOT contain
     * the "## PRIORITY OVERRIDE" section.
     */
    public function testPriorityOverrideAbsentWhenForbiddenIocsEmpty_Fix18(): void
    {
        $builder = $this->buildBuilderWithAnalysisInstructions([
            'interdictions' => [],
            'obligations' => ['vary your phrasing'],
            'objectif_strategique' => 'Obtain a payment IOC',
            'style_ton' => 'Natural, 80-100 words',
            'forbidden_iocs' => [],     // <-- empty
            'pivot_to_iocs' => [],
        ]);

        $result = $builder->buildGeneratorPrompts($this->buildPaymentPushContext(), 'small_business_owner');

        $this->assertIsArray($result);
        $userPrompt = (string) $result['user'];

        $this->assertStringNotContainsString('PRIORITY OVERRIDE', $userPrompt, 'No override block when forbidden_iocs is empty');
        $this->assertStringNotContainsString('DO NOT ask for these again', $userPrompt);
        // OBJECTIVE section must still be the LAST instructional block
        $this->assertStringContainsString('## OBJECTIVE', $userPrompt);
    }

    /**
     * Spec 095 Fix #18 / V3 — when `forbidden_iocs` is populated, the
     * prompt MUST contain a "## PRIORITY OVERRIDE" section that names the
     * forbidden IOCs explicitly. This is THE fix for conv 204fab36.
     */
    public function testPriorityOverridePresentWhenForbiddenIocsPopulated_Fix18(): void
    {
        $builder = $this->buildBuilderWithAnalysisInstructions([
            'interdictions' => ['FORBIDDEN to ask BIC/SWIFT (deferred)'],
            'obligations' => ['Pivot to phone or address'],
            'objectif_strategique' => 'Obtain phone number',
            'style_ton' => 'Cooperative, 80-100 words',
            'forbidden_iocs' => ['BIC', 'SWIFT'],   // <-- populated
            'pivot_to_iocs' => ['phone', 'postal address', 'beneficiary name'],
        ]);

        $result = $builder->buildGeneratorPrompts($this->buildPaymentPushContext(), 'small_business_owner');

        $userPrompt = (string) $result['user'];

        $this->assertStringContainsString('## PRIORITY OVERRIDE', $userPrompt, 'Override block must be present');
        // Lists the forbidden IOCs verbatim
        $this->assertStringContainsString('BIC', $userPrompt);
        $this->assertStringContainsString('SWIFT', $userPrompt);
        // Explicit forbid + pivot directive
        $this->assertStringContainsString('DO NOT ask for these again', $userPrompt);
        $this->assertStringContainsString('OVERRIDES the OBJECTIVE', $userPrompt);
    }

    /**
     * Spec 095 Fix #18 / V3 — when the analyzer provides a `pivot_to_iocs`
     * list, the override block must use it as the suggested alternatives.
     * When absent, the default alternative list is used.
     */
    public function testPriorityOverridePivotListIncludesAnalyzerSuggestions_Fix18(): void
    {
        $builder = $this->buildBuilderWithAnalysisInstructions([
            'interdictions' => [],
            'obligations' => [],
            'objectif_strategique' => 'Test',
            'style_ton' => 'Test',
            'forbidden_iocs' => ['IBAN'],
            'pivot_to_iocs' => ['wallet address', 'cryptocurrency address'],
        ]);

        $result = $builder->buildGeneratorPrompts($this->buildPaymentPushContext(), 'small_business_owner');
        $userPrompt = (string) $result['user'];

        // The analyzer-supplied pivot list must appear verbatim
        $this->assertStringContainsString('wallet address', $userPrompt);
        $this->assertStringContainsString('cryptocurrency address', $userPrompt);
    }

    /**
     * Spec 095 Fix #18 / V3 — the OBJECTIVE section must be followed by
     * PRIORITY OVERRIDE (PRIORITY OVERRIDE is the LAST instructional block,
     * benefiting from recency bias).
     */
    public function testPriorityOverrideAppearsAfterObjective_Fix18(): void
    {
        $builder = $this->buildBuilderWithAnalysisInstructions([
            'interdictions' => [],
            'obligations' => [],
            'objectif_strategique' => 'Test',
            'style_ton' => 'Test',
            'forbidden_iocs' => ['BIC'],
            'pivot_to_iocs' => [],
        ]);

        $result = $builder->buildGeneratorPrompts($this->buildPaymentPushContext(), 'small_business_owner');
        $userPrompt = (string) $result['user'];

        $posObjective = strpos($userPrompt, '## OBJECTIVE');
        $posOverride = strpos($userPrompt, '## PRIORITY OVERRIDE');

        $this->assertNotFalse($posObjective);
        $this->assertNotFalse($posOverride);
        $this->assertGreaterThan($posObjective, $posOverride, 'PRIORITY OVERRIDE must appear AFTER OBJECTIVE in the prompt (recency bias)');
    }

    /**
     * Spec 095 Fix #18 / V4 regression — when `forbidden_iocs` is empty,
     * the OBJECTIVE for payment_push must still mention IOC names
     * (BIC/SWIFT/IBAN/etc.) so the baseline pull-for-payment behavior is
     * preserved. This guards against accidentally weakening the dominant
     * happy path.
     */
    public function testBaselineObjectiveStillPullsIocsWhenForbiddenIocsEmpty_Fix18(): void
    {
        $builder = $this->buildBuilderWithAnalysisInstructions([
            'interdictions' => [],
            'obligations' => [],
            'objectif_strategique' => 'Test',
            'style_ton' => 'Test',
            'forbidden_iocs' => [],
            'pivot_to_iocs' => [],
        ]);

        $result = $builder->buildGeneratorPrompts($this->buildPaymentPushContext(), 'small_business_owner');
        $userPrompt = (string) $result['user'];

        // Existing payment_push OBJECTIVE (Fix #6) wording must remain intact
        $this->assertStringContainsString('Stage: payment push', $userPrompt);
        $this->assertStringContainsString('IBAN', $userPrompt, 'Baseline OBJECTIVE must still mention IBAN (Fix #6 behavior preserved)');
        $this->assertStringContainsString('wallet', $userPrompt);
    }
}
