<?php

declare(strict_types=1);

namespace App\DataFixtures\Scambaiting;

use App\DataFixtures\Communication\PersonaFixtures;
use App\DataFixtures\Communication\ScamTypeFixtures;
use App\Domain\Communication\Persona;
use App\Domain\Communication\ScamType;
use App\Infrastructure\Doctrine\Entity\PersonaPerformanceStatsEntity;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Common\DataFixtures\DependentFixtureInterface;
use Doctrine\Persistence\ObjectManager;

final class PersonaPerformanceStatsFixtures extends Fixture implements DependentFixtureInterface
{
    public function load(ObjectManager $manager): void
    {
        // Get personas
        $elderlyPerson = $manager->getRepository(Persona::class)->findOneBy(['personaCode' => 'elderly_person']);
        $genericUser = $manager->getRepository(Persona::class)->findOneBy(['personaCode' => 'generic_user']);
        $smallBusinessOwner = $manager->getRepository(Persona::class)->findOneBy(['personaCode' => 'small_business_owner']);
        $lonelyPerson = $manager->getRepository(Persona::class)->findOneBy(['personaCode' => 'lonely_person']);

        // Get scam types
        $phishing = $manager->getRepository(ScamType::class)->findOneBy(['code' => 'PHISHING']);
        $romance = $manager->getRepository(ScamType::class)->findOneBy(['code' => 'ROMANCE']);
        $invoiceFraud = $manager->getRepository(ScamType::class)->findOneBy(['code' => 'INVOICE_FRAUD']);
        $techSupport = $manager->getRepository(ScamType::class)->findOneBy(['code' => 'TECH_SUPPORT']);

        // Scenario 1: PHISHING - elderly_person performs best (exploitation)
        if ($elderlyPerson && $phishing) {
            $stats1 = new PersonaPerformanceStatsEntity(
                $elderlyPerson,
                $phishing,
                sessionsCount: 25,
                rewardSum: 20.5,
                rewardAvg: 0.82
            );
            $manager->persist($stats1);
        }

        if ($genericUser && $phishing) {
            $stats2 = new PersonaPerformanceStatsEntity(
                $genericUser,
                $phishing,
                sessionsCount: 18,
                rewardSum: 9.0,
                rewardAvg: 0.50
            );
            $manager->persist($stats2);
        }

        if ($smallBusinessOwner && $phishing) {
            $stats3 = new PersonaPerformanceStatsEntity(
                $smallBusinessOwner,
                $phishing,
                sessionsCount: 12,
                rewardSum: 7.2,
                rewardAvg: 0.60
            );
            $manager->persist($stats3);
        }

        // Scenario 2: ROMANCE - generic_user performs best
        if ($genericUser && $romance) {
            $stats4 = new PersonaPerformanceStatsEntity(
                $genericUser,
                $romance,
                sessionsCount: 30,
                rewardSum: 24.0,
                rewardAvg: 0.80
            );
            $manager->persist($stats4);
        }

        if ($lonelyPerson && $romance) {
            $stats5 = new PersonaPerformanceStatsEntity(
                $lonelyPerson,
                $romance,
                sessionsCount: 20,
                rewardSum: 14.0,
                rewardAvg: 0.70
            );
            $manager->persist($stats5);
        }

        // Scenario 3: INVOICE_FRAUD - All personas in cold start (< 3 sessions)
        if ($elderlyPerson && $invoiceFraud) {
            $stats6 = new PersonaPerformanceStatsEntity(
                $elderlyPerson,
                $invoiceFraud,
                sessionsCount: 2,
                rewardSum: 1.2,
                rewardAvg: 0.60
            );
            $manager->persist($stats6);
        }

        if ($genericUser && $invoiceFraud) {
            $stats7 = new PersonaPerformanceStatsEntity(
                $genericUser,
                $invoiceFraud,
                sessionsCount: 1,
                rewardSum: 0.5,
                rewardAvg: 0.50
            );
            $manager->persist($stats7);
        }

        // Scenario 4: TECH_SUPPORT - Ex aequo (tied performance)
        if ($elderlyPerson && $techSupport) {
            $stats8 = new PersonaPerformanceStatsEntity(
                $elderlyPerson,
                $techSupport,
                sessionsCount: 15,
                rewardSum: 10.5,
                rewardAvg: 0.70
            );
            $manager->persist($stats8);
        }

        if ($smallBusinessOwner && $techSupport) {
            $stats9 = new PersonaPerformanceStatsEntity(
                $smallBusinessOwner,
                $techSupport,
                sessionsCount: 20,
                rewardSum: 14.0,
                rewardAvg: 0.70  // Same avg as elderly_person
            );
            $manager->persist($stats9);
        }

        // Scenario 5: Edge cases - Very low performance
        if ($lonelyPerson && $phishing) {
            $stats10 = new PersonaPerformanceStatsEntity(
                $lonelyPerson,
                $phishing,
                sessionsCount: 10,
                rewardSum: 1.0,
                rewardAvg: 0.10
            );
            $manager->persist($stats10);
        }

        // Scenario 6: Perfect performance
        if ($smallBusinessOwner && $romance) {
            $stats11 = new PersonaPerformanceStatsEntity(
                $smallBusinessOwner,
                $romance,
                sessionsCount: 5,
                rewardSum: 5.0,
                rewardAvg: 1.00  // Perfect score
            );
            $manager->persist($stats11);
        }

        $manager->flush();
    }

    public function getDependencies(): array
    {
        return [
            PersonaFixtures::class,
            ScamTypeFixtures::class,
        ];
    }
}
