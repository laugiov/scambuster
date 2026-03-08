<?php

declare(strict_types=1);

namespace Tests\Integration\Infrastructure\Doctrine\Repository;

use App\Domain\Communication\Persona;
use App\Domain\Communication\ScamType;
use App\Infrastructure\Doctrine\Entity\PersonaPerformanceStatsEntity;
use App\Infrastructure\Doctrine\Repository\PersonaPerformanceStatsRepository;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class PersonaPerformanceStatsRepositoryTest extends KernelTestCase
{
    private PersonaPerformanceStatsRepository $repository;
    private \Doctrine\ORM\EntityManagerInterface $em;

    protected function setUp(): void
    {
        self::bootKernel();

        $this->em = self::getContainer()->get('doctrine.orm.entity_manager');
        $this->repository = $this->em->getRepository(PersonaPerformanceStatsEntity::class);

        // Clean database
        $this->em->createQuery('DELETE FROM App\Infrastructure\Doctrine\Entity\PersonaPerformanceStatsEntity')->execute();
        $this->em->flush();
        $this->em->clear();
    }

    public function testFindOrCreateReturnsExistingEntity(): void
    {
        // Arrange: créer une entité en base
        $persona = $this->em->getRepository(Persona::class)->findOneBy(['personaCode' => 'elderly_person']);
        $scamType = $this->em->getRepository(ScamType::class)->findOneBy(['code' => 'PHISHING']);

        $stats = new PersonaPerformanceStatsEntity($persona, $scamType, 5, 3.2, 0.64);
        $this->em->persist($stats);
        $this->em->flush();

        // Act
        $result = $this->repository->findOrCreate($persona, $scamType);

        // Assert
        $this->assertEquals(5, $result->getSessionsCount());
        $this->assertEquals(0.64, $result->getRewardAvg());
    }

    public function testFindOrCreateCreatesNewEntityWhenNotExists(): void
    {
        $persona = $this->em->getRepository(Persona::class)->findOneBy(['personaCode' => 'generic_user']);
        $scamType = $this->em->getRepository(ScamType::class)->findOneBy(['code' => 'ROMANCE']);

        $result = $this->repository->findOrCreate($persona, $scamType);

        $this->assertEquals(0, $result->getSessionsCount());
        $this->assertEquals(0.0, $result->getRewardAvg());
    }

    public function testAddRewardUpdatesMovingAverage(): void
    {
        $persona = $this->em->getRepository(Persona::class)->findOneBy(['personaCode' => 'generic_user']);
        $scamType = $this->em->getRepository(ScamType::class)->findOneBy(['code' => 'ROMANCE']);

        $stats = new PersonaPerformanceStatsEntity($persona, $scamType, 2, 1.2, 0.6);
        $this->em->persist($stats);
        $this->em->flush();

        // Act
        $stats->addReward(0.8);
        $this->em->flush();

        // Assert
        $this->assertEquals(3, $stats->getSessionsCount());
        // reward_avg = (0.6 * 2 + 0.8) / 3 = 0.6667
        $this->assertEqualsWithDelta(0.6667, $stats->getRewardAvg(), 0.001);
    }

    public function testFindBestPerformingPersonaReturnsCorrectResult(): void
    {
        $scamType = $this->em->getRepository(ScamType::class)->findOneBy(['code' => 'PHISHING']);

        $persona1 = $this->em->getRepository(Persona::class)->findOneBy(['personaCode' => 'elderly_person']);
        $persona2 = $this->em->getRepository(Persona::class)->findOneBy(['personaCode' => 'generic_user']);

        $stats1 = new PersonaPerformanceStatsEntity($persona1, $scamType, 10, 7.0, 0.70);
        $stats2 = new PersonaPerformanceStatsEntity($persona2, $scamType, 5, 4.0, 0.80);

        $this->em->persist($stats1);
        $this->em->persist($stats2);
        $this->em->flush();

        $best = $this->repository->findBestPerformingPersona($scamType);

        $this->assertEquals('generic_user', $best->getPersona()->getPersonaCode());
        $this->assertEquals(0.80, $best->getRewardAvg());
    }
}
