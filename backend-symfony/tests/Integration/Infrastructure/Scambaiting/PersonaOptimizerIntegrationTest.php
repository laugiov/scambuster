<?php

declare(strict_types=1);

namespace Tests\Integration\Infrastructure\Scambaiting;

use App\Application\Scambaiting\PersonaOptimizer;
use App\Domain\Communication\Persona;
use App\Domain\Communication\ScamType;
use App\Infrastructure\Doctrine\Entity\PersonaPerformanceStatsEntity;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Integration tests for PersonaOptimizer with real database and fixtures
 */
final class PersonaOptimizerIntegrationTest extends KernelTestCase
{
    private PersonaOptimizer $optimizer;
    private \Doctrine\ORM\EntityManagerInterface $em;

    protected function setUp(): void
    {
        self::bootKernel();

        $this->optimizer = self::getContainer()->get(PersonaOptimizer::class);
        $this->em = self::getContainer()->get('doctrine.orm.entity_manager');

        $this->seedPhishingStats();
    }

    /**
     * Owns the PHISHING bandit precondition instead of trusting shared fixture
     * state. A sibling integration test wipes persona_performance_stats for
     * PHISHING in its setUp and only restores it in tearDown; if that restore
     * does not hold before this test runs, every persona cold-starts and
     * selection degrades to a uniform array_rand over all personas (the source
     * of the "elderly ~= 3/100" flake). Seeding here (DAMA-rolled-back)
     * guarantees elderly_person is the dominant, non-cold-start arm.
     */
    private function seedPhishingStats(): void
    {
        $phishing = $this->em->getRepository(ScamType::class)->findOneBy(['code' => 'PHISHING']);
        self::assertNotNull($phishing);

        $seed = [
            'elderly_person' => [25, 20.5, 0.82],
            'generic_user' => [18, 9.0, 0.50],
            'small_business_owner' => [12, 7.2, 0.60],
        ];

        foreach ($seed as $code => [$sessions, $rewardSum, $rewardAvg]) {
            $persona = $this->em->getRepository(Persona::class)->findOneBy(['personaCode' => $code]);
            self::assertNotNull($persona);

            $existing = $this->em->getRepository(PersonaPerformanceStatsEntity::class)
                ->findOneBy(['persona' => $persona, 'scamType' => $phishing]);

            if ($existing === null) {
                $this->em->persist(new PersonaPerformanceStatsEntity($persona, $phishing, $sessions, $rewardSum, $rewardAvg));
            }
        }

        $this->em->flush();
    }

    public function testSelectPersonaForPhishingExploitsBestPerformer(): void
    {
        // PHISHING has elderly_person with 0.82 avg (best)
        $selections = [];
        for ($i = 0; $i < 100; $i++) {
            $persona = $this->optimizer->selectPersona('PHISHING');
            if ($persona) {
                $selections[] = $persona;
            }
        }

        $this->assertNotEmpty($selections);
        $counts = array_count_values($selections);

        // elderly_person should be selected most often
        $this->assertArrayHasKey('elderly_person', $counts);
        $this->assertGreaterThan(50, $counts['elderly_person']);
    }

    public function testSelectPersonaConsistency(): void
    {
        for ($i = 0; $i < 20; $i++) {
            $persona = $this->optimizer->selectPersona('PHISHING');
            $this->assertIsString($persona);
            $this->assertNotEmpty($persona);
        }
    }
}
