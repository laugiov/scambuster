<?php

declare(strict_types=1);

namespace App\Tests\Unit\Application\Standards;

use App\Application\Standards\F3MappingLoader;
use App\Application\Standards\F3MappingRenderer;
use App\Domain\Communication\TtpTaxonomySeed;
use PHPUnit\Framework\TestCase;

/**
 * Guards the F3 mapping (Spec 002).
 *
 * Two things are being protected. First, that the mapping file itself stays complete
 * and internally consistent — every taxonomy code covered, every decision citing what
 * it must cite. Second, and more important, that the mapping file and the taxonomy
 * data agree: a `mitre-f3` reference may only exist in `external_refs` when a
 * confirmed mapping row backs it, and a confirmed row must be reflected in the data.
 *
 * That second pair is what keeps Constitution II enforceable by CI rather than by
 * memory: no external-framework claim reaches a STIX consumer without a recorded,
 * dated, per-entry check behind it.
 */
final class F3MappingTest extends TestCase
{
    private const MAPPING_PATH = __DIR__ . '/../../../../config/standards/f3-mapping.json';
    private const DOCUMENT_PATH = __DIR__ . '/../../../../../docs/standards/f3-mapping.md';

    private F3MappingLoader $loader;

    protected function setUp(): void
    {
        $this->loader = new F3MappingLoader(self::MAPPING_PATH);
    }

    public function testMappingFileIsInternallyConsistent(): void
    {
        $problems = $this->loader->validate(TtpTaxonomySeed::codes());

        $this->assertSame(
            [],
            $problems,
            "The F3 mapping file has problems:\n - " . implode("\n - ", $problems)
        );
    }

    public function testEveryTaxonomyCodeHasExactlyOneMappingEntry(): void
    {
        $mapping = $this->loader->load();

        /** @var list<array<string, mixed>> $entries */
        $entries = $mapping['entries'];
        $codes = array_map(
            static fn (array $entry): string => \is_string($entry['code'] ?? null) ? $entry['code'] : '',
            $entries
        );

        $this->assertSame(
            TtpTaxonomySeed::codes(),
            $codes,
            'The mapping must cover all 27 taxonomy codes, in canonical order'
        );
    }

    public function testEveryRelationIsFromTheClosedVocabulary(): void
    {
        $mapping = $this->loader->load();

        /** @var list<array<string, mixed>> $entries */
        $entries = $mapping['entries'];

        foreach ($entries as $entry) {
            $this->assertContains(
                $entry['relation'] ?? null,
                F3MappingLoader::RELATIONS,
                sprintf('%s uses a relation outside the closed vocabulary', (string) ($entry['code'] ?? '?'))
            );
        }
    }

    /**
     * The load-bearing check. A `mitre-f3` reference in the taxonomy data is a public
     * claim: it ships in every STIX export. It may only exist where the mapping
     * document confirms it.
     */
    public function testTheTaxonomyDataAndTheConfirmedMappingsAgreeExactly(): void
    {
        $confirmed = $this->loader->confirmedReferences();

        foreach ($confirmed as $code => $ids) {
            sort($ids);
            $confirmed[$code] = $ids;
        }

        ksort($confirmed);

        $inData = $this->f3ReferencesInTaxonomyData();

        // Compared as whole sets rather than looped, so this test asserts even when
        // both sides are empty — which is the state while the mapping is pending,
        // and exactly the state a silent regression would hide in.
        $this->assertSame(
            $confirmed,
            $inData,
            'Every mitre-f3 reference in the taxonomy must be backed by a confirmed mapping decision,'
            . ' and every confirmed decision must have reached external_refs. A reference with no'
            . ' decision behind it is an unverified external claim shipping in every STIX export'
            . ' (Constitution II); a decision that never reached the data is a mapping the exports'
            . ' silently do not carry.'
        );
    }

    /**
     * @return array<string, list<string>>
     */
    private function f3ReferencesInTaxonomyData(): array
    {
        /** @var array<string, list<string>> $byCode */
        $byCode = [];

        foreach (TtpTaxonomySeed::ENTRIES as $entry) {
            foreach ($entry['external_refs'] as $ref) {
                if ($ref['source_name'] === F3MappingLoader::SOURCE_NAME) {
                    $byCode[$entry['code']][] = $ref['external_id'];
                }
            }
        }

        foreach ($byCode as $code => $ids) {
            sort($ids);
            $byCode[$code] = $ids;
        }

        ksort($byCode);

        return $byCode;
    }

    public function testBroaderAndRelatedDecisionsAreNeverPublishedAsReferences(): void
    {
        $this->assertNotContains(F3MappingLoader::RELATION_BROADER, F3MappingLoader::CONFIRMED_RELATIONS);
        $this->assertNotContains(F3MappingLoader::RELATION_RELATED, F3MappingLoader::CONFIRMED_RELATIONS);
        $this->assertNotContains(F3MappingLoader::RELATION_NONE, F3MappingLoader::CONFIRMED_RELATIONS);
        $this->assertNotContains(F3MappingLoader::RELATION_PENDING, F3MappingLoader::CONFIRMED_RELATIONS);
    }

    public function testValidatorRejectsADecisionThatCitesNoTechnique(): void
    {
        $problems = $this->validateFixture([
            ['code' => 'SB-T001', 'relation' => 'equivalent', 'f3_ids' => [], 'rationale' => 'x'],
        ], ['SB-T001']);

        $this->assertStringContainsString('cites no F3 id', implode("\n", $problems));
    }

    public function testValidatorRejectsANoneDecisionThatCitesATechnique(): void
    {
        $problems = $this->validateFixture([
            ['code' => 'SB-T001', 'relation' => 'none', 'f3_ids' => ['F1020'], 'rationale' => 'x'],
        ], ['SB-T001']);

        $this->assertStringContainsString('is "none" but cites F3 id', implode("\n", $problems));
    }

    public function testValidatorRejectsAMissingRationale(): void
    {
        $problems = $this->validateFixture([
            ['code' => 'SB-T001', 'relation' => 'pending', 'f3_ids' => [], 'rationale' => '   '],
        ], ['SB-T001']);

        $this->assertStringContainsString('has no rationale', implode("\n", $problems));
    }

    public function testValidatorRejectsAnUncoveredTaxonomyCode(): void
    {
        $problems = $this->validateFixture([
            ['code' => 'SB-T001', 'relation' => 'pending', 'f3_ids' => [], 'rationale' => 'x'],
        ], ['SB-T001', 'SB-T002']);

        $this->assertStringContainsString('SB-T002 has no mapping entry', implode("\n", $problems));
    }

    /**
     * FR-006: a recorded decision without its F3 version cannot be invalidated by a
     * future F3 release, because nobody can tell which release it was made against.
     */
    public function testValidatorRejectsRecordedDecisionsWithNoFrameworkVersion(): void
    {
        $problems = $this->validateFixture(
            [['code' => 'SB-T001', 'relation' => 'none', 'f3_ids' => [], 'rationale' => 'x']],
            ['SB-T001'],
            frameworkVersion: null,
            checkedOn: null,
        );

        $this->assertStringContainsString('framework_version is not set', implode("\n", $problems));
        $this->assertStringContainsString('checked_on is not set', implode("\n", $problems));
    }

    public function testAnAllPendingMappingNeedsNoFrameworkVersionYet(): void
    {
        $problems = $this->validateFixture(
            [['code' => 'SB-T001', 'relation' => 'pending', 'f3_ids' => [], 'rationale' => 'x']],
            ['SB-T001'],
            frameworkVersion: null,
            checkedOn: null,
        );

        $this->assertSame([], $problems);
    }

    public function testTheGeneratedDocumentBlockIsUpToDate(): void
    {
        $document = file_get_contents(self::DOCUMENT_PATH);

        $this->assertIsString($document, 'the mapping document must exist');

        $renderer = new F3MappingRenderer($this->loader);

        $this->assertSame(
            $document,
            $renderer->replaceBlock($document),
            'docs/standards/f3-mapping.md is stale. Run: php bin/console scambuster:ttp:f3-mapping'
        );
    }

    public function testTheRenderedTableCoversEveryTaxonomyCode(): void
    {
        $rendered = (new F3MappingRenderer($this->loader))->render();

        foreach (TtpTaxonomySeed::codes() as $code) {
            $this->assertStringContainsString($code, $rendered);
        }
    }

    /**
     * @param list<array<string, mixed>> $entries
     * @param list<string>               $taxonomyCodes
     *
     * @return list<string>
     */
    private function validateFixture(
        array $entries,
        array $taxonomyCodes,
        ?string $frameworkVersion = null,
        ?string $checkedOn = null,
    ): array {
        $path = tempnam(sys_get_temp_dir(), 'f3-mapping-') . '.json';
        file_put_contents($path, json_encode([
            'framework' => 'mitre-f3',
            'framework_version' => $frameworkVersion,
            'checked_on' => $checkedOn,
            'entries' => $entries,
        ], JSON_THROW_ON_ERROR));

        try {
            return (new F3MappingLoader($path))->validate($taxonomyCodes);
        } finally {
            unlink($path);
        }
    }
}
