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
    public function testMalformedAttckIdIsDropped(): void
    {
        $context = self::context();
        $context['scam_type_attck'] = 'not-a-technique';
        $context['scam_type_misp'] = null;

        self::assertSame([], IocInteroperableFieldsBuilder::externalReferences($context));
    }
}
