<?php

declare(strict_types=1);

namespace App\Application\Standards;

use App\Domain\Communication\Service\TtpStixIdGenerator;
use App\Domain\Communication\Ttp;
use App\Domain\Communication\TtpTaxonomySeed;

/**
 * Generates the machine-readable taxonomy artifact from the canonical seed
 * (Spec 003).
 *
 * A reference framework ships a file, not a database. Every consumer this project
 * has or wants — a tool author, a reviewer, the MISP taxonomies repository, a
 * standards body — needs to read the taxonomy without an account on this platform.
 * This class produces that file.
 *
 * Determinism is a hard requirement, not a nicety (FR-004). The output carries no
 * timestamp, no generation host, no ordering that depends on anything but the seed
 * order. Two runs on the same seed are byte-identical, which is what lets CI diff a
 * regenerated artifact against the committed one and fail on drift. It is also what
 * makes the artifact citable: a reviewer can regenerate it and get the same bytes.
 */
final class TaxonomyArtifactGenerator
{
    /**
     * The kill chain name every exported attack-pattern carries. Renaming it is a
     * breaking change for consumers and is gated on the container decision
     * (Spec 003 FR-008).
     */
    public const KILL_CHAIN_NAME = 'scambuster-scam-phases';

    /**
     * The six phases, in kill-chain order. Order is semantic here — it is the
     * sequence a scam conversation moves through — so it is stated explicitly
     * rather than derived from whatever order the entries happen to sit in.
     *
     * @var list<string>
     */
    public const PHASES = [
        'hook',
        'trust-building',
        'payment-request',
        'escalation',
        'channel-switch',
        'exit',
    ];

    private TtpStixIdGenerator $idGenerator;

    public function __construct()
    {
        $this->idGenerator = new TtpStixIdGenerator();
    }

    /**
     * @return array<string, mixed>
     */
    public function generate(): array
    {
        $entries = [];

        foreach (TtpTaxonomySeed::ENTRIES as $seed) {
            $entries[] = [
                'code' => $seed['code'],
                'label' => $seed['label'],
                'definition' => $seed['definition'],
                'phase' => $seed['phase'],
                'examples' => $seed['examples'],
                'stimulus_affinity' => $seed['stimulus_affinity'],
                'external_refs' => $seed['external_refs'],
                // Every seeded entry is active. Deprecated entries keep their row
                // with active=false rather than disappearing (Constitution VI), so
                // the flag is part of the artifact from v1 even though nothing has
                // been deprecated yet.
                'active' => true,
                'stix_id' => $this->idGenerator->attackPatternId($seed['code']),
            ];
        }

        return [
            'taxonomy_version' => Ttp::TAXONOMY_VERSION,
            'generated_from' => TtpTaxonomySeed::class . '::ENTRIES',
            'kill_chain_name' => self::KILL_CHAIN_NAME,
            'phases' => self::PHASES,
            'entry_count' => \count($entries),
            'entries' => $entries,
        ];
    }

    /**
     * The artifact as the exact bytes that belong on disk.
     *
     * Pretty-printed because humans read this file in pull requests and in the
     * repository browser; unescaped slashes and unicode because escaping them makes
     * definitions unreadable for no gain. The trailing newline keeps the file
     * POSIX-clean and keeps diffs from showing a "\ No newline at end of file".
     */
    public function generateJson(): string
    {
        return json_encode(
            $this->generate(),
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
        ) . "\n";
    }

    /**
     * Canonical file name for a given taxonomy version. One file per version: a new
     * major does not overwrite the file consumers already pinned.
     */
    public static function fileName(string $taxonomyVersion = Ttp::TAXONOMY_VERSION): string
    {
        return sprintf('taxonomy-v%s.json', $taxonomyVersion);
    }
}
