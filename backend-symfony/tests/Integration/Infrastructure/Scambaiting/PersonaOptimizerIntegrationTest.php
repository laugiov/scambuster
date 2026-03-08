<?php

declare(strict_types=1);

namespace Tests\Integration\Infrastructure\Scambaiting;

use App\Application\Scambaiting\PersonaOptimizer;
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
