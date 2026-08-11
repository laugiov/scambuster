<?php

declare(strict_types=1);

namespace App\Tests\Unit\Application\Standards;

use App\Application\Standards\MispMachineTagGenerator;
use App\Domain\Communication\Ttp;
use App\Domain\Communication\TtpTaxonomySeed;
use PHPUnit\Framework\TestCase;

/**
 * Guards the generated MISP taxonomy file (Spec 006 FR-004).
 *
 * A registered MISP taxonomy is published to every instance that syncs the
 * taxonomies repository, and it is hard to retract. So the two things that matter
 * are that the file says exactly what the taxonomy says — never a hand-written
 * paraphrase that drifts — and that the tag strings it registers are the same ones
 * the platform already emits. A mismatch there would register meanings for tags
 * nobody sends, while the tags actually in the wild stay unresolvable.
 */
final class MispMachineTagGeneratorTest extends TestCase
{
    private const MACHINETAG_PATH = __DIR__ . '/../../../../config/standards/machinetag.json';

    private MispMachineTagGenerator $generator;

    protected function setUp(): void
    {
        $this->generator = new MispMachineTagGenerator();
    }

    public function testGenerationIsDeterministic(): void
    {
        $this->assertSame(
            $this->generator->generateJson(),
            (new MispMachineTagGenerator())->generateJson()
        );
    }

    public function testCarriesEveryTaxonomyEntryInCanonicalOrder(): void
    {
        $document = $this->generator->generate();

        /** @var list<array<string, mixed>> $entries */
        $entries = $document['values'][0]['entry'];

        $this->assertSame(TtpTaxonomySeed::codes(), array_column($entries, 'value'));
    }

    public function testValuesAndDescriptionsMatchTheTaxonomyVerbatim(): void
    {
        $document = $this->generator->generate();

        /** @var list<array<string, mixed>> $entries */
        $entries = $document['values'][0]['entry'];

        foreach (TtpTaxonomySeed::ENTRIES as $index => $seed) {
            $this->assertSame($seed['code'], $entries[$index]['value']);
            $this->assertSame(
                $seed['label'],
                $entries[$index]['expanded'],
                'a paraphrased label would drift from the taxonomy the moment either changes'
            );
            $this->assertSame($seed['definition'], $entries[$index]['description']);
        }
    }

    /**
     * The registered tag has to be the tag the platform sends. TtpMispTagProvider
     * emits `scambuster:ttp="SB-Txxx"`; the namespace and predicate here are the
     * two halves of that string.
     */
    public function testNamespaceAndPredicateMatchTheTagsThePlatformEmits(): void
    {
        $document = $this->generator->generate();

        $this->assertSame('scambuster', $document['namespace']);
        $this->assertSame('ttp', $document['predicates'][0]['value']);
        $this->assertSame('ttp', $document['values'][0]['predicate']);

        $sampleTag = sprintf(
            '%s:%s="%s"',
            $document['namespace'],
            $document['values'][0]['predicate'],
            TtpTaxonomySeed::codes()[0],
        );

        $this->assertSame('scambuster:ttp="SB-T001"', $sampleTag);
    }

    /**
     * MISP taxonomy versions are integers, so the semver taxonomy version is
     * folded into one that still moves monotonically with it.
     */
    public function testVersionTracksTheTaxonomyVersion(): void
    {
        $document = $this->generator->generate();

        [$major, $minor] = array_pad(explode('.', Ttp::TAXONOMY_VERSION), 2, '0');

        $this->assertSame((int) $major * 10 + (int) $minor, $document['version']);
        $this->assertIsInt($document['version'], 'MISP taxonomy versions are integers, not semver strings');
    }

    public function testTheCommittedFileMatchesAFreshGeneration(): void
    {
        $committed = file_get_contents(self::MACHINETAG_PATH);

        $this->assertIsString($committed, 'the generated machinetag.json must be committed');
        $this->assertSame(
            $this->generator->generateJson(),
            $committed,
            'machinetag.json is stale. It is generated, never hand-edited (Spec 006 FR-004).'
        );
    }

    /**
     * Constitution III has no exception, and a MISP taxonomy is about as public as
     * a file gets. Definitions describe behaviour in the project's own words; if a
     * verbatim quote ever reached the taxonomy it would reach this file too.
     */
    public function testCarriesNoContentBeyondTheTaxonomyText(): void
    {
        $document = $this->generator->generate();

        /** @var list<array<string, mixed>> $entries */
        $entries = $document['values'][0]['entry'];

        foreach ($entries as $entry) {
            $this->assertSame(
                ['value', 'expanded', 'description'],
                array_keys($entry),
                'the file must carry only the code, label and definition — no examples, no evidence'
            );
        }
    }
}
