<?php

declare(strict_types=1);

namespace App\Tests\Unit\Application\Standards;

use App\Application\Standards\TaxonomyArtifactGenerator;
use App\Domain\Communication\Service\TtpStixIdGenerator;
use App\Domain\Communication\Ttp;
use App\Domain\Communication\TtpTaxonomySeed;
use PHPUnit\Framework\TestCase;

/**
 * Guards the generated taxonomy artifact (Spec 003).
 *
 * The artifact is what a third party reads instead of this database. Three
 * properties make that work, and each is pinned here:
 *
 * - it is byte-stable, so a reviewer regenerating it gets the same file and CI can
 *   diff the committed one against a fresh run;
 * - it matches the canonical seed exactly, so the file and the database never tell
 *   a consumer two different things;
 * - its STIX ids match what the exporter actually emits, so an id read from the file
 *   resolves against a bundle.
 */
final class TaxonomyArtifactGeneratorTest extends TestCase
{
    private const ARTIFACT_PATH = __DIR__ . '/../../../../config/standards/taxonomy-v1.0.json';

    private TaxonomyArtifactGenerator $generator;

    protected function setUp(): void
    {
        $this->generator = new TaxonomyArtifactGenerator();
    }

    public function testGenerationIsByteIdenticalAcrossRuns(): void
    {
        $this->assertSame(
            $this->generator->generateJson(),
            (new TaxonomyArtifactGenerator())->generateJson(),
            'Generation must be deterministic: no timestamps, no host, no unstable ordering (FR-004)'
        );
    }

    public function testArtifactCoversEveryTaxonomyEntryInSeedOrder(): void
    {
        $artifact = $this->generator->generate();

        /** @var list<array<string, mixed>> $entries */
        $entries = $artifact['entries'];

        $this->assertSame(TtpTaxonomySeed::codes(), array_column($entries, 'code'));
        $this->assertSame(\count($entries), $artifact['entry_count']);
    }

    public function testEveryEntryCarriesTheFullRequiredFieldSet(): void
    {
        $artifact = $this->generator->generate();

        /** @var list<array<string, mixed>> $entries */
        $entries = $artifact['entries'];

        foreach ($entries as $entry) {
            $this->assertSame(
                ['code', 'label', 'definition', 'phase', 'examples', 'stimulus_affinity', 'external_refs', 'active', 'stix_id'],
                array_keys($entry),
                sprintf('%s must carry exactly the documented field set (FR-002)', (string) $entry['code'])
            );
        }
    }

    public function testEntryContentMatchesTheCanonicalSeedVerbatim(): void
    {
        $artifact = $this->generator->generate();

        /** @var list<array<string, mixed>> $entries */
        $entries = $artifact['entries'];

        foreach (TtpTaxonomySeed::ENTRIES as $index => $seed) {
            $entry = $entries[$index];

            $this->assertSame($seed['label'], $entry['label']);
            $this->assertSame($seed['definition'], $entry['definition']);
            $this->assertSame($seed['phase'], $entry['phase']);
            $this->assertSame($seed['examples'], $entry['examples']);
            $this->assertSame($seed['stimulus_affinity'], $entry['stimulus_affinity']);
            $this->assertSame($seed['external_refs'], $entry['external_refs']);
        }
    }

    /**
     * The artifact and the STIX export have to agree on ids, or an id a consumer
     * reads from the file resolves against nothing in a bundle.
     */
    public function testStixIdsMatchTheExporterIdGenerator(): void
    {
        $idGenerator = new TtpStixIdGenerator();
        $artifact = $this->generator->generate();

        /** @var list<array<string, mixed>> $entries */
        $entries = $artifact['entries'];

        foreach ($entries as $entry) {
            $code = (string) $entry['code'];
            $this->assertSame($idGenerator->attackPatternId($code), $entry['stix_id']);
        }
    }

    public function testEveryEntryPhaseIsInTheDeclaredKillChain(): void
    {
        $artifact = $this->generator->generate();

        /** @var list<array<string, mixed>> $entries */
        $entries = $artifact['entries'];

        foreach ($entries as $entry) {
            $this->assertContains($entry['phase'], TaxonomyArtifactGenerator::PHASES);
        }
    }

    public function testArtifactCarriesTheCurrentTaxonomyVersion(): void
    {
        $artifact = $this->generator->generate();

        $this->assertSame(Ttp::TAXONOMY_VERSION, $artifact['taxonomy_version']);
        $this->assertSame(TaxonomyArtifactGenerator::fileName(), 'taxonomy-v' . Ttp::TAXONOMY_VERSION . '.json');
    }

    public function testGeneratedJsonEndsWithASingleNewline(): void
    {
        $json = $this->generator->generateJson();

        $this->assertStringEndsWith("}\n", $json);
        $this->assertStringEndsNotWith("\n\n", $json);
    }

    /**
     * The one that catches the real mistake: someone edits the taxonomy and forgets
     * to regenerate. CI runs the same check through
     * `scambuster:ttp:taxonomy-export --check`.
     */
    public function testTheCommittedArtifactMatchesAFreshGeneration(): void
    {
        $committed = file_get_contents(self::ARTIFACT_PATH);

        $this->assertIsString($committed, 'the taxonomy artifact must be committed');
        $this->assertSame(
            $this->generator->generateJson(),
            $committed,
            'The committed artifact is stale. Run: php bin/console scambuster:ttp:taxonomy-export'
        );
    }

    public function testCommittedArtifactIsValidUtf8Json(): void
    {
        $committed = file_get_contents(self::ARTIFACT_PATH);

        $this->assertIsString($committed);
        $decoded = json_decode($committed, true);

        $this->assertIsArray($decoded, 'the committed artifact must parse as JSON');
        $this->assertArrayHasKey('entries', $decoded);
    }
}
