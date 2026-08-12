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

    /**
     * @dataProvider encodableVersions
     */
    public function testEncodesVersionsWhoseMinorFitsOneDigit(string $taxonomyVersion, int $expected): void
    {
        $this->assertSame($expected, MispMachineTagGenerator::encodeVersion($taxonomyVersion));
    }

    /**
     * @return array<string, array{0: string, 1: int}>
     */
    public static function encodableVersions(): array
    {
        return [
            'current taxonomy version' => ['1.0', 10],
            'first minor' => ['1.1', 11],
            'last encodable minor' => ['1.9', 19],
            'next major' => ['2.0', 20],
            'no minor part reads as zero' => ['3', 30],
        ];
    }

    /**
     * The encoding gives the minor part one decimal digit. Taxonomy 1.10 would
     * encode to 20, the same integer as 2.0: a consumer syncing the MISP taxonomies
     * repository would see the version go backwards from 1.9, and two different
     * taxonomies would claim to be the same release.
     *
     * Failing at generation is recoverable. Publishing a colliding version to every
     * MISP instance that syncs the repository is not, which is why this throws
     * rather than wrapping.
     *
     * @dataProvider unencodableVersions
     */
    public function testRefusesToEncodeAMinorVersionBeyondOneDigit(string $taxonomyVersion): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessageMatches('/cannot be encoded as a MISP taxonomy version/');

        MispMachineTagGenerator::encodeVersion($taxonomyVersion);
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function unencodableVersions(): array
    {
        return [
            'first minor that overflows' => ['1.10'],
            'well past the boundary' => ['2.11'],
            'far future' => ['1.42'],
        ];
    }

    /**
     * The boundary is stated once and read from there, so a future widening of the
     * encoding cannot leave the guard checking a stale number.
     */
    public function testTheEncodableBoundaryIsTheOneTheGuardEnforces(): void
    {
        $boundary = MispMachineTagGenerator::MAX_ENCODABLE_MINOR;

        $this->assertSame(
            10 + $boundary,
            MispMachineTagGenerator::encodeVersion('1.' . $boundary),
            'the highest declared minor must still encode'
        );

        $this->expectException(\LogicException::class);
        MispMachineTagGenerator::encodeVersion('1.' . ($boundary + 1));
    }

    /**
     * The guard must not have moved what the current taxonomy publishes. Anything
     * else would change the committed machinetag.json.
     */
    public function testTheCurrentTaxonomyStillPublishesTheSameVersion(): void
    {
        $this->assertSame(10, MispMachineTagGenerator::encodeVersion('1.0'));
        $this->assertSame(10, $this->generator->version());
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
