<?php

declare(strict_types=1);

namespace App\Tests\Unit\Application\Stix;

use App\Application\Stix\IocInteroperableFieldsBuilder;
use PHPUnit\Framework\TestCase;

final class IocInteroperableFieldsBuilderTest extends TestCase
{
    /**
     * @return array<string, mixed>
     */
    private static function context(): array
    {
        return [
            'scam_type_code' => 'INVOICE_FRAUD',
            'scam_type_attck' => 'T1656',
            'scam_type_misp' => 'rsit:fraud="fraud"',
            'persona_code' => 'tech_intermediate',
            'persona_label' => 'Marketing manager, tech-comfortable',
            'extraction_method' => 'llm',
            'revelation_turn' => 1,
            'total_turns' => 4,
            'semantic_role' => 'CONTACT_CHANNEL',
            'stimulus_type' => 'PASSIVE',
            'urgency_score' => 0.8,
            'context_excerpt' => 'Fake payment update notice with new IBAN',
        ];
    }

    public function testDescriptionTellsHowTheIocWasElicited(): void
    {
        $description = IocInteroperableFieldsBuilder::description(self::context());

        self::assertNotNull($description);
        self::assertStringContainsString('turn 1 of 4', $description);
        self::assertStringContainsString('an INVOICE_FRAUD engagement', $description);
        self::assertStringContainsString('PASSIVE stimulus', $description);
        self::assertStringContainsString('CONTACT_CHANNEL', $description);
        self::assertStringContainsString('Fake payment update notice with new IBAN', $description);
    }

    public function testArticleAgreesWithTheScamTypeInitial(): void
    {
        $consonant = self::context();
        $consonant['scam_type_code'] = 'ROMANCE';

        self::assertStringContainsString('a ROMANCE engagement', (string) IocInteroperableFieldsBuilder::description($consonant));
        self::assertStringContainsString('an INVOICE_FRAUD engagement', (string) IocInteroperableFieldsBuilder::description(self::context()));
    }

    /**
     * ~37% of stored contexts have revelation_turn > total_turns. Publishing
     * "turn 5 of 4" reads as a bug to the analyst consuming the feed, so the
     * inconsistent pair must degrade to the turn alone.
     */
    public function testInconsistentTurnCountsDropTheTotal(): void
    {
        $context = self::context();
        $context['revelation_turn'] = 5;
        $context['total_turns'] = 4;

        $description = (string) IocInteroperableFieldsBuilder::description($context);

        self::assertStringNotContainsString('of 4', $description);
        self::assertStringContainsString('at turn 5 of an INVOICE_FRAUD engagement', $description);
    }

    public function testExcerptIsTerminatedSoTheNextSentenceDoesNotRunOn(): void
    {
        $context = self::context();
        $context['context_excerpt'] = 'No trailing punctuation here';

        self::assertStringContainsString(
            'Context: No trailing punctuation here. Extraction method: llm.',
            (string) IocInteroperableFieldsBuilder::description($context)
        );
    }

    public function testEmptyContextYieldsNoDescription(): void
    {
        self::assertNull(IocInteroperableFieldsBuilder::description([]));
        self::assertNull(IocInteroperableFieldsBuilder::description(['scam_type_code' => '   ']));
    }

    public function testLabelsExposePivotsAndKeepTheHistoricalOnesFirst(): void
    {
        $labels = IocInteroperableFieldsBuilder::labels(self::context(), 'confirmed');

        self::assertSame('malicious-activity', $labels[0]);
        self::assertSame('scambuster', $labels[1]);
        self::assertContains('scam-type:invoice_fraud', $labels);
        self::assertContains('ioc-role:contact_channel', $labels);
        self::assertContains('stimulus:passive', $labels);
        self::assertContains('persona:tech_intermediate', $labels);
        self::assertContains('analyst:confirmed', $labels);
    }

    public function testLabelsWithoutContextStayMinimalAndUnique(): void
    {
        $labels = IocInteroperableFieldsBuilder::labels(null, null);

        self::assertSame(['malicious-activity', 'scambuster'], $labels);
        self::assertSame(array_unique($labels), $labels);
    }

    public function testExternalReferencesResolveAttckAndMisp(): void
    {
        $refs = IocInteroperableFieldsBuilder::externalReferences(self::context());

        self::assertSame('mitre-attack', $refs[0]['source_name']);
        self::assertSame('T1656', $refs[0]['external_id']);
        self::assertSame('https://attack.mitre.org/techniques/T1656/', $refs[0]['url']);
        self::assertSame('misp-taxonomy', $refs[1]['source_name']);
    }

    public function testSubTechniqueUrlUsesTheSlashForm(): void
    {
        $context = self::context();
        $context['scam_type_attck'] = 'T1566.003';

        $refs = IocInteroperableFieldsBuilder::externalReferences($context);

        self::assertSame('https://attack.mitre.org/techniques/T1566/003/', $refs[0]['url']);
    }

    /**
     * A malformed technique id must not produce a link to a page that cannot
     * exist — a dead external reference is worse than none.
     */
    // ── D3: control-character stripping in exported free text ───────────────

    /**
     * The context excerpt is attacker-influenced (an LLM narrative of the
     * scammer message) and lands in the STIX `description` a consumer renders or
     * logs. Non-whitespace C0 control characters and DEL must be removed so they
     * cannot inject terminal/log control sequences (ESC, BEL, NUL) or corrupt
     * rendering. These bytes never occur inside a valid UTF-8 sequence, so
     * stripping them at byte level is multilingual-safe.
     */
    public function testDescriptionStripsControlCharactersFromExcerpt(): void
    {
        $context = self::context();
        $context['context_excerpt'] = "Pay to \x00IBAN\x1b[31m DE\x07 now\x7f end";

        $description = (string) IocInteroperableFieldsBuilder::description($context);

        foreach (["\x00", "\x1b", "\x07", "\x7f"] as $ctrl) {
            self::assertStringNotContainsString($ctrl, $description, sprintf('0x%02X must be stripped', \ord($ctrl)));
        }
        // The visible text survives, only the control bytes are gone.
        self::assertStringContainsString('Pay to IBAN', $description);
        self::assertStringContainsString('DE now end', $description);
    }

    public function testDescriptionPreservesLegitimateMultilingualText(): void
    {
        $samples = [
            'accented Latin' => 'Paiement frauduleux à réaliser immédiatement',
            'cyrillic' => 'Срочный платёж на счёт',
            'arabic' => 'تحويل عاجل إلى الحساب',
            'cjk' => '请立即汇款到账户',
            'emoji' => 'Send money now 💸🏦',
        ];

        foreach ($samples as $label => $text) {
            $context = self::context();
            $context['context_excerpt'] = $text;
            $description = (string) IocInteroperableFieldsBuilder::description($context);
            self::assertStringContainsString($text, $description, $label . ' must be preserved verbatim');
        }
    }

    public function testControlCharsDoNotProduceEmptyOrBrokenContextSentence(): void
    {
        $context = self::context();
        $context['context_excerpt'] = "\x00\x01\x02clean\x03";

        $description = (string) IocInteroperableFieldsBuilder::description($context);

        self::assertStringContainsString('Context: clean', $description);
    }

    /**
     * Defense at the sink (stringOrNull), not only the excerpt path: a control
     * character in any other free-text description field must also be stripped.
     */
    public function testControlCharsStrippedFromNonExcerptFields(): void
    {
        $context = self::context();
        $context['persona_label'] = "Retired\x1b nurse\x07";
        $context['semantic_role'] = "CONTACT\x00_CHANNEL";

        $description = (string) IocInteroperableFieldsBuilder::description($context);

        foreach (["\x1b", "\x07", "\x00"] as $ctrl) {
            self::assertStringNotContainsString($ctrl, $description);
        }
        self::assertStringContainsString('Retired nurse', $description);
        self::assertStringContainsString('CONTACT_CHANNEL', $description);
    }

    public function testMalformedAttckIdIsDropped(): void
    {
        $context = self::context();
        $context['scam_type_attck'] = 'not-a-technique';
        $context['scam_type_misp'] = null;

        self::assertSame([], IocInteroperableFieldsBuilder::externalReferences($context));
    }
}
