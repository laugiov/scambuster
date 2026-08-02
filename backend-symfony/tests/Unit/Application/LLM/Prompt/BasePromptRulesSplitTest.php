<?php

declare(strict_types=1);

namespace App\Tests\Unit\Application\LLM\Prompt;

use App\Application\LLM\Prompt\BasePromptRules;
use PHPUnit\Framework\TestCase;

/**
 * Locks the core/editable split of BasePromptRules.
 *
 * The overriding constraint is ZERO REGRESSION: getRules() must be byte-identical to
 * the pre-split implementation (golden sha256). The split adds getCoreRules() /
 * getEditableRules() as derived views over the same single source of truth.
 */
final class BasePromptRulesSplitTest extends TestCase
{
    // Golden hashes of the full getRules() block — a byte-identity lock so no rule text drifts
    // silently. Update deliberately (with review) whenever a rule is intentionally reworded.
    private const GOLDEN_EN = '77a562ec999910a5e223dc7301d4f57a3b39f94b6d099dbc3b150df661f9b505';
    private const GOLDEN_FR = 'd5dfe7470928f6359cc326d9d2c6f0f20a1a73f76950e0b36a32660cf27dd02d';

    // ─── zero-regression: byte-identical getRules ──────────────────────

    public function testGetRulesEnIsByteIdenticalToPreSplitGolden(): void
    {
        self::assertSame(self::GOLDEN_EN, hash('sha256', BasePromptRules::getRules('en')));
    }

    public function testGetRulesFrIsByteIdenticalToPreSplitGolden(): void
    {
        self::assertSame(self::GOLDEN_FR, hash('sha256', BasePromptRules::getRules('fr')));
    }

    // ─── partition: complete + disjoint ────────────────────────────────

    public function testEveryRuleIsInExactlyOneSubsetAndTogetherTheyCoverAll(): void
    {
        $all = $this->ruleLines(BasePromptRules::getRules('en'));
        $core = $this->ruleLines(BasePromptRules::getCoreRules('en'));
        $editable = $this->ruleLines(BasePromptRules::getEditableRules('en'));

        // Disjoint.
        self::assertSame([], array_intersect($core, $editable), 'core and editable must not share a rule');

        // Complete: core ∪ editable == all rules.
        $union = array_merge($core, $editable);
        sort($union);
        $sortedAll = $all;
        sort($sortedAll);
        self::assertSame($sortedAll, $union, 'core ∪ editable must equal the full rule set');

        // Every rule belongs to exactly one subset.
        foreach ($all as $rule) {
            $inCore = in_array($rule, $core, true);
            $inEditable = in_array($rule, $editable, true);
            self::assertTrue($inCore xor $inEditable, "rule must be in exactly one subset: {$rule}");
        }
    }

    public function testKnownCounts(): void
    {
        self::assertCount(11, $this->ruleLines(BasePromptRules::getRules('en')));
        self::assertCount(6, $this->ruleLines(BasePromptRules::getCoreRules('en')));
        self::assertCount(5, $this->ruleLines(BasePromptRules::getEditableRules('en')));
    }

    // ─── the CORE subset holds the safety-adjacent invariants ──────────

    /**
     * @dataProvider coreMarkerProvider
     */
    public function testCoreRulesContainSafetyAdjacentMarkers(string $marker): void
    {
        self::assertStringContainsString($marker, BasePromptRules::getCoreRules('en'));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function coreMarkerProvider(): array
    {
        return [
            'no-honeypot-knowledge' => ['no knowledge of honeypots'],
            'out-of-band phone' => ['Never give a phone'],
            'out-of-band IBAN' => ['IBAN'],
            // Prompt-side counterpart of PolicyGuard's redirect-email channel block: the persona
            // must be told never to hand out an alternate email, or the guard rejects a reply the
            // prompt never warned against (a wasted retry).
            'out-of-band redirect-email' => ['a different email address'],
            'careful-buyer SoW' => ['Statement of Work'],
            'payment-cue' => ['how to send it'],
            'mailbox-coherence' => ['reading mail received at your own mailbox'],
            'language-invariant' => ['Every single word'],
        ];
    }

    public function testCoreRulesDoNotContainEditableMarkers(): void
    {
        $core = BasePromptRules::getCoreRules('en');

        self::assertStringNotContainsString('starts emails with a greeting', $core);
        self::assertStringNotContainsString('Accept whatever name', $core);
        self::assertStringNotContainsString('re-ask a question', $core);
        self::assertStringNotContainsString('systematically sign', $core);
    }

    // ─── the EDITABLE subset holds the voice/style/quality rules ───────

    /**
     * @dataProvider editableMarkerProvider
     */
    public function testEditableRulesContainStyleMarkers(string $marker): void
    {
        self::assertStringContainsString($marker, BasePromptRules::getEditableRules('en'));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function editableMarkerProvider(): array
    {
        return [
            'greeting' => ['starts emails with a greeting'],
            'name-acceptance' => ['Accept whatever name'],
            'scenario' => ['Adapt to the scenario'],
            'no-signing' => ['systematically sign'],
            'anti-repetition' => ['re-ask a question'],
        ];
    }

    public function testEditableRulesDoNotContainCoreMarkers(): void
    {
        $editable = BasePromptRules::getEditableRules('en');

        self::assertStringNotContainsString('Never give a phone', $editable);
        self::assertStringNotContainsString('Statement of Work', $editable);
        self::assertStringNotContainsString('reading mail received at your own mailbox', $editable);
        self::assertStringNotContainsString('no knowledge of honeypots', $editable);
    }

    // ─── language substitution flows through the subsets ───────────────

    public function testLanguageInvariantIsCoreAndSubstitutedPerLanguage(): void
    {
        self::assertStringContainsString('writes entirely in fr', BasePromptRules::getCoreRules('fr'));
        self::assertStringContainsString('writes entirely in de', BasePromptRules::getCoreRules('de'));
        self::assertStringNotContainsString('writes entirely in', BasePromptRules::getEditableRules('fr'));
    }

    /**
     * Split rule lines back out (getRules imploded with "\n").
     *
     * @return list<string>
     */
    private function ruleLines(string $block): array
    {
        return $block === '' ? [] : explode("\n", $block);
    }
}
