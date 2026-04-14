<?php

declare(strict_types=1);

namespace App\Tests\Unit\EventListener;

use App\Domain\Communication\Persona;
use App\Domain\Communication\ScamType;
use App\Domain\Scambaiting\Event\ConversationEndedEvent;
use App\Infrastructure\Doctrine\Entity\PersonaPerformanceStatsEntity;
use App\Infrastructure\Doctrine\Repository\PersonaPerformanceStatsRepository;
use App\Infrastructure\EventListener\ConversationEndedListener;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

class ConversationEndedListenerTest extends TestCase
{
    private PersonaPerformanceStatsRepository&MockObject $statsRepo;
    private EntityManagerInterface&MockObject $em;
    private ConversationEndedListener $listener;

    protected function setUp(): void
    {
        $this->statsRepo = $this->createMock(PersonaPerformanceStatsRepository::class);
        $this->em = $this->createMock(EntityManagerInterface::class);
        $this->listener = new ConversationEndedListener(
            $this->statsRepo,
            $this->em,
            new NullLogger()
        );
    }

    public function testSkipsConversationWithoutPersona(): void
    {
        $event = new ConversationEndedEvent(
            'conv-1', 'PHISHING', null, 3600, 10, 5, 2, true
        );

        $this->em->expects($this->never())->method('beginTransaction');
        $this->listener->onConversationEnded($event);
    }

    public function testUpdatesStatsForConversationWithPersona(): void
    {
        $event = new ConversationEndedEvent(
            'conv-2', 'ROMANCE', 'elderly_person', 7200, 20, 10, 5, true
        );

        $persona = $this->createMock(Persona::class);
        $persona->method('getPersonaCode')->willReturn('elderly_person');

        $scamType = $this->createMock(ScamType::class);
        $scamType->method('getCode')->willReturn('ROMANCE');

        $personaRepo = $this->createMock(EntityRepository::class);
        $personaRepo->method('findOneBy')
            ->with(['personaCode' => 'elderly_person'])
            ->willReturn($persona);

        $scamTypeRepo = $this->createMock(EntityRepository::class);
        $scamTypeRepo->method('findOneBy')
            ->with(['code' => 'ROMANCE'])
            ->willReturn($scamType);

        $this->em->method('getRepository')
            ->willReturnCallback(fn (string $class) => match ($class) {
                Persona::class => $personaRepo,
                ScamType::class => $scamTypeRepo,
            });

        $stats = $this->createMock(PersonaPerformanceStatsEntity::class);
        $stats->method('getSessionsCount')->willReturn(5);
        $stats->method('getRewardAvg')->willReturn(0.75);
        $stats->expects($this->once())->method('addReward');

        $this->statsRepo->method('findOrCreate')->willReturn($stats);

        $this->em->expects($this->once())->method('beginTransaction');
        $this->em->expects($this->once())->method('flush');
        $this->em->expects($this->once())->method('commit');
        $this->em->expects($this->never())->method('persist');

        $this->listener->onConversationEnded($event);
    }

    public function testPersistsNewStats(): void
    {
        $event = new ConversationEndedEvent(
            'conv-3', 'PHISHING', 'generic_user', 1800, 5, 3, 1, false
        );

        $persona = $this->createMock(Persona::class);
        $scamType = $this->createMock(ScamType::class);

        $personaRepo = $this->createMock(EntityRepository::class);
        $personaRepo->method('findOneBy')->willReturn($persona);

        $scamTypeRepo = $this->createMock(EntityRepository::class);
        $scamTypeRepo->method('findOneBy')->willReturn($scamType);

        $this->em->method('getRepository')
            ->willReturnCallback(fn (string $class) => match ($class) {
                Persona::class => $personaRepo,
                ScamType::class => $scamTypeRepo,
            });

        $stats = $this->createMock(PersonaPerformanceStatsEntity::class);
        $stats->method('getSessionsCount')->willReturn(0);
        $stats->method('getRewardAvg')->willReturn(0.0);

        $this->statsRepo->method('findOrCreate')->willReturn($stats);

        $this->em->expects($this->once())->method('persist')->with($stats);
        $this->em->expects($this->once())->method('beginTransaction');
        $this->em->expects($this->once())->method('flush');
        $this->em->expects($this->once())->method('commit');

        $this->listener->onConversationEnded($event);
    }

    public function testHandlesPersonaNotFound(): void
    {
        $event = new ConversationEndedEvent(
            'conv-4', 'PHISHING', 'nonexistent_persona', 1000, 3, 1, 0, false
        );

        $personaRepo = $this->createMock(EntityRepository::class);
        $personaRepo->method('findOneBy')->willReturn(null);

        $scamTypeRepo = $this->createMock(EntityRepository::class);
        $scamTypeRepo->method('findOneBy')->willReturn($this->createMock(ScamType::class));

        $this->em->method('getRepository')
            ->willReturnCallback(fn (string $class) => match ($class) {
                Persona::class => $personaRepo,
                ScamType::class => $scamTypeRepo,
            });

        $this->em->expects($this->never())->method('beginTransaction');
        $this->listener->onConversationEnded($event);
    }

    public function testHandlesScamTypeNotFound(): void
    {
        $event = new ConversationEndedEvent(
            'conv-5', 'NONEXISTENT', 'generic_user', 1000, 3, 1, 0, false
        );

        $personaRepo = $this->createMock(EntityRepository::class);
        $personaRepo->method('findOneBy')->willReturn($this->createMock(Persona::class));

        $scamTypeRepo = $this->createMock(EntityRepository::class);
        $scamTypeRepo->method('findOneBy')->willReturn(null);

        $this->em->method('getRepository')
            ->willReturnCallback(fn (string $class) => match ($class) {
                Persona::class => $personaRepo,
                ScamType::class => $scamTypeRepo,
            });

        $this->em->expects($this->never())->method('beginTransaction');
        $this->listener->onConversationEnded($event);
    }

    public function testRollsBackOnFlushException(): void
    {
        $event = new ConversationEndedEvent(
            'conv-6', 'PHISHING', 'generic_user', 1000, 3, 1, 0, true
        );

        $persona = $this->createMock(Persona::class);
        $scamType = $this->createMock(ScamType::class);

        $personaRepo = $this->createMock(EntityRepository::class);
        $personaRepo->method('findOneBy')->willReturn($persona);

        $scamTypeRepo = $this->createMock(EntityRepository::class);
        $scamTypeRepo->method('findOneBy')->willReturn($scamType);

        $this->em->method('getRepository')
            ->willReturnCallback(fn (string $class) => match ($class) {
                Persona::class => $personaRepo,
                ScamType::class => $scamTypeRepo,
            });

        $stats = $this->createMock(PersonaPerformanceStatsEntity::class);
        $stats->method('getSessionsCount')->willReturn(5);
        $this->statsRepo->method('findOrCreate')->willReturn($stats);

        $this->em->expects($this->once())->method('beginTransaction');
        $this->em->method('flush')->willThrowException(new \Exception('DB error'));
        $this->em->expects($this->once())->method('rollback');
        $this->em->expects($this->never())->method('commit');

        // Should not rethrow, just log
        $this->listener->onConversationEnded($event);
    }
}
