<?php

declare(strict_types=1);

namespace App\Tests\Unit\Application\Communication;

use App\Application\LLM\ContextualEnrichmentResult;
use App\DataFixtures\Communication\TtpFixtures;
use App\Domain\Communication\Service\TtpStixIdGenerator;
use App\Domain\Communication\TtpTaxonomySeed;
use PHPUnit\Framework\TestCase;

/**
 * Enforces consistency across the TTP taxonomy registries.
 *
 * The migration seeds are the production source of truth (reference rows
 * reach prod via migrations, not fixtures) and TtpFixtures mirrors them for
 * test databases. These tests lock the two constants against each other and
 * validate every row against the closed vocabularies (phases, stimulus
 * codes, external reference shape), so a drift in either place fails fast.
 */
class TtpTaxonomyConsistencyTest extends TestCase
{
    private const EXPECTED_COUNT = 27;

    /** @var list<string> */
    private const ALLOWED_PHASES = [
        'hook',
        'trust-building',
        'payment-request',
        'escalation',
        'channel-switch',
        'exit',
    ];

    /** @var list<array{code: string, label: string, definition: string, phase: string, examples: list<string>, stimulus_affinity: list<string>, external_refs: list<array{source_name: string, external_id: string}>}> */
    private array $migrationSeeds;

    protected function setUp(): void
    {
        // The DoctrineMigrations namespace is not composer-autoloaded, so the
        // migration class is loaded from its file before reflecting on it.
        if (!class_exists(\DoctrineMigrations\Version2026073000000000::class, false)) {
            require_once \dirname(__DIR__, 4) . '/migrations/Version2026073000000000.php';
        }

        $reflection = new \ReflectionClass(\DoctrineMigrations\Version2026073000000000::class);
        /** @var list<array{code: string, label: string, definition: string, phase: string, examples: list<string>, stimulus_affinity: list<string>, external_refs: list<array{source_name: string, external_id: string}>}> $seeds */
        $seeds = $reflection->getConstant('SEEDS');
        $this->migrationSeeds = $seeds;
    }

    public function testMigrationSeedsExactCount(): void
    {
        $this->assertCount(
            self::EXPECTED_COUNT,
            $this->migrationSeeds,
            'Migration SEEDS must contain exactly 27 TTP entries'
        );
    }

    public function testFixtureSeedsExactCount(): void
    {
        $this->assertCount(
            self::EXPECTED_COUNT,
            TtpFixtures::SEEDS,
            'TtpFixtures::SEEDS must contain exactly 27 TTP entries'
        );
    }

    public function testCodesAreUniqueAndWellFormed(): void
    {
        $codes = array_column($this->migrationSeeds, 'code');

        $this->assertSame(
            count($codes),
            count(array_unique($codes)),
            'TTP codes must not contain duplicates'
        );

        foreach ($codes as $code) {
            $this->assertMatchesRegularExpression(
                '/^SB-T\d{3}$/',
                $code,
                "TTP code '{$code}' must match the SB-Txxx format"
            );
        }
    }

    public function testLabelsAreUnique(): void
    {
        $labels = array_column($this->migrationSeeds, 'label');

        $this->assertSame(
            count($labels),
            count(array_unique($labels)),
            'TTP labels must not contain duplicates'
        );
    }

    public function testPhasesAreWithinTheKillChainVocabulary(): void
    {
        foreach ($this->migrationSeeds as $seed) {
            $this->assertContains(
                $seed['phase'],
                self::ALLOWED_PHASES,
                "TTP '{$seed['code']}' uses unknown phase '{$seed['phase']}'"
            );
        }
    }

    public function testStimulusAffinityValuesAreValidStimulusCodes(): void
    {
        foreach ($this->migrationSeeds as $seed) {
            $this->assertNotEmpty(
                $seed['stimulus_affinity'],
                "TTP '{$seed['code']}' must declare at least one stimulus affinity"
            );

            $unknown = array_diff($seed['stimulus_affinity'], ContextualEnrichmentResult::VALID_STIMULUS_TYPES);
            $this->assertEmpty(
                $unknown,
                "TTP '{$seed['code']}' carries unknown stimulus codes: " . implode(', ', $unknown)
            );
        }
    }

    public function testExternalRefsAreWellFormedMitreReferences(): void
    {
        foreach ($this->migrationSeeds as $seed) {
            foreach ($seed['external_refs'] as $ref) {
                $this->assertArrayHasKey('source_name', $ref, "external_refs entry of '{$seed['code']}' missing 'source_name'");
                $this->assertArrayHasKey('external_id', $ref, "external_refs entry of '{$seed['code']}' missing 'external_id'");
                $this->assertSame(
                    'mitre-attack',
                    $ref['source_name'],
                    "external_refs of '{$seed['code']}' must only carry mitre-attack references"
                );
                $this->assertNotSame(
                    '',
                    $ref['external_id'],
                    "external_refs entry of '{$seed['code']}' must carry a non-empty external_id"
                );
            }
        }
    }

    public function testEveryEntryResolvesToAUniqueStixAttackPatternId(): void
    {
        $generator = new TtpStixIdGenerator();
        $ids = [];

        foreach ($this->migrationSeeds as $seed) {
            $id = $generator->attackPatternId($seed['code']);

            $this->assertMatchesRegularExpression(
                '/^attack-pattern--[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/',
                $id,
                "TTP '{$seed['code']}' must resolve to a STIX attack-pattern id"
            );
            $ids[] = $id;
        }

        $this->assertSame(
            count($ids),
            count(array_unique($ids)),
            'Each TTP must map to a distinct STIX attack-pattern id'
        );
    }

    public function testFixtureSeedsMatchMigrationSeedsExactly(): void
    {
        $this->assertSame(
            $this->migrationSeeds,
            TtpFixtures::SEEDS,
            'TtpFixtures::SEEDS must be byte-identical to the migration SEEDS so the two can never drift'
        );
    }

    public function testCanonicalSeedMatchesMigrationSeedsExactly(): void
    {
        $this->assertSame(
            $this->migrationSeeds,
            TtpTaxonomySeed::ENTRIES,
            'TtpTaxonomySeed::ENTRIES is what the application generates artifacts from; it must be'
            . ' byte-identical to the migration SEEDS, which is what production actually holds'
        );
    }

    public function testCanonicalSeedCodesHelperMatchesTheEntries(): void
    {
        $this->assertSame(
            array_column(TtpTaxonomySeed::ENTRIES, 'code'),
            TtpTaxonomySeed::codes(),
            'TtpTaxonomySeed::codes() must return the entry codes in canonical order'
        );
    }
}
