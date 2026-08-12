<?php

declare(strict_types=1);

namespace App\Application\Standards;

use App\Domain\Communication\Ttp;
use App\Domain\Communication\TtpTaxonomySeed;

/**
 * Generates the MISP taxonomy file (`machinetag.json`) for the `scambuster`
 * namespace, from the canonical taxonomy seed.
 *
 * The platform already emits `scambuster:ttp="SB-Txxx"` tags. They are well-formed
 * machine tags, but no MISP instance can resolve them to a meaning, because the
 * namespace is not registered in the MISP taxonomies repository. Registering it is
 * what turns a tag a consumer sees as free text into one their instance
 * understands.
 *
 * GATED. Filing this file anywhere public is blocked until the project records how
 * it publishes its taxonomy: a registered public taxonomy is a normative artifact,
 * and a merged MISP taxonomy PR is hard to retract. Generating and testing it is not
 * blocked, and that is all this class does — it produces bytes on disk, and a human
 * decides whether they ever leave the repository.
 *
 * Generated, never hand-written: a hand-maintained copy of 27 definitions
 * would drift from the taxonomy within one release, and the drift would be
 * invisible until a consumer noticed their tag meant something else.
 */
final class MispMachineTagGenerator
{
    public const NAMESPACE_NAME = 'scambuster';
    public const PREDICATE = 'ttp';

    /** Highest minor version the integer encoding can carry. See {@see encodeVersion}. */
    public const MAX_ENCODABLE_MINOR = 9;

    /**
     * The taxonomy values this namespace registers, one per code.
     *
     * @return list<array{value: string, expanded: string, description: string}>
     */
    public function entries(): array
    {
        $entries = [];

        foreach (TtpTaxonomySeed::entries() as $entry) {
            $entries[] = [
                'value' => $entry['code'],
                'expanded' => $entry['label'],
                'description' => $entry['definition'],
            ];
        }

        return $entries;
    }

    public function version(): int
    {
        return self::encodeVersion(Ttp::TAXONOMY_VERSION);
    }

    /**
     * @return array<string, mixed>
     */
    public function generate(): array
    {
        return [
            'namespace' => self::NAMESPACE_NAME,
            'description' => 'Scammer-side tactics, techniques and procedures observable in the messages of a'
                . ' fraud conversation, across a six-phase scam kill chain. Generated from the ScamBuster TTP'
                . ' taxonomy; see https://github.com/laugiov/scambuster.',
            'version' => $this->version(),
            'expanded' => 'ScamBuster Scam TTP',
            'predicates' => [[
                'value' => self::PREDICATE,
                'expanded' => 'Scam TTP',
                'description' => 'A behaviour from the closed ScamBuster TTP taxonomy, observed in a scammer message.',
            ]],
            'values' => [[
                'predicate' => self::PREDICATE,
                'entry' => $this->entries(),
            ]],
        ];
    }

    public function generateJson(): string
    {
        return json_encode(
            $this->generate(),
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
        ) . "\n";
    }

    /**
     * MISP taxonomy versions are integers, not semver strings. The taxonomy's own
     * "1.0" becomes 10 and "1.1" becomes 11, so the MISP-side version still moves
     * monotonically with the taxonomy and a consumer can tell two releases apart.
     *
     * The encoding gives the minor part exactly one decimal digit, and stops rather
     * than wrapping when that runs out. Taxonomy 1.10 would otherwise encode to 20,
     * which is the same integer as 2.0: a consumer would see the version go
     * backwards from 1.9, and two different taxonomies would claim to be the same
     * release. Failing here is recoverable; publishing a colliding version to every
     * MISP instance that syncs the taxonomies repository is not.
     *
     * Public and static because it is a pure function of the version string, and
     * the failure it guards against is worth testing directly rather than only
     * through whatever version the taxonomy happens to carry today.
     *
     * @throws \LogicException when the minor version no longer fits the encoding
     */
    public static function encodeVersion(string $taxonomyVersion): int
    {
        // explode always yields at least one element, so index 0 needs no fallback;
        // a version string with no minor part reads as minor 0.
        $parts = explode('.', $taxonomyVersion);
        $major = (int) $parts[0];
        $minor = (int) ($parts[1] ?? 0);

        if ($minor > self::MAX_ENCODABLE_MINOR) {
            throw new \LogicException(sprintf(
                'Taxonomy version "%s" cannot be encoded as a MISP taxonomy version:'
                . ' the minor part must be %d or less, because major * 10 + minor gives'
                . ' the minor exactly one digit. Minor %d would encode to %d, colliding'
                . ' with taxonomy %d.%d and making the published version go backwards.'
                . ' Widen the encoding in %s before releasing this taxonomy version.',
                $taxonomyVersion,
                self::MAX_ENCODABLE_MINOR,
                $minor,
                $major * 10 + $minor,
                $major + intdiv($minor, 10),
                $minor % 10,
                self::class,
            ));
        }

        return $major * 10 + $minor;
    }
}
