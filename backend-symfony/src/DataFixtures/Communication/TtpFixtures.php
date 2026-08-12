<?php

declare(strict_types=1);

namespace App\DataFixtures\Communication;

use App\Domain\Communication\Ttp;
use App\Domain\Communication\TtpTaxonomySeed;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Bundle\FixturesBundle\FixtureGroupInterface;
use Doctrine\Persistence\ObjectManager;

/**
 * TtpFixtures - Create the TTP taxonomy rows for tests
 *
 * Loads the canonical taxonomy rows into a test database. The rows themselves
 * live in {@see TtpTaxonomySeed}, the production-side source of truth, and are
 * aliased here rather than copied — one fewer place to drift. The lkp_ttp
 * migration keeps its own self-contained copy (a migration cannot depend on
 * application code), and a consistency test locks the two against each other.
 */
class TtpFixtures extends Fixture implements FixtureGroupInterface
{
    /** Reference/lookup data — loadable on its own for the lightweight demo seed. */
    public static function getGroups(): array
    {
        return ['reference'];
    }

    /**
     * The canonical taxonomy rows, aliased from the production-side constant.
     *
     * @var list<array{code: string, label: string, definition: string, phase: string, examples: list<string>, stimulus_affinity: list<string>, external_refs: list<array{source_name: string, external_id: string}>}>
     */
    public const SEEDS = TtpTaxonomySeed::ENTRIES;

    public function load(ObjectManager $manager): void
    {
        foreach (self::SEEDS as $data) {
            $manager->persist(new Ttp(
                $data['code'],
                $data['label'],
                $data['definition'],
                $data['phase'],
                $data['examples'],
                $data['stimulus_affinity'],
                $data['external_refs']
            ));
        }

        $manager->flush();
    }
}
