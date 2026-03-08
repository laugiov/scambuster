<?php

declare(strict_types=1);

namespace Tests\Integration\Infrastructure\EventListener;

use App\Domain\Scambaiting\Event\ConversationEndedEvent;
use App\Infrastructure\EventListener\ConversationEndedListener;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class ConversationEndedListenerTest extends KernelTestCase
{
    private ConversationEndedListener $listener;
    private \Doctrine\ORM\EntityManagerInterface $em;

    protected function setUp(): void
    {
        self::bootKernel();

        $this->listener = self::getContainer()->get(ConversationEndedListener::class);
        $this->em = self::getContainer()->get('doctrine.orm.entity_manager');
    }

    public function testOnConversationEndedUpdatesStats(): void
    {
        $event = new ConversationEndedEvent(
            conversationId: '123',
            scamTypeCode: 'PHISHING',
            personaCode: 'elderly_person',
            durationSec: 3600,
            turnsCount: 8,
            iocsTotal: 5,
            iocsSensibles: 2,
            isCompleted: true
        );

        // Act
        $this->listener->onConversationEnded($event);

        // Assert: vérifier que les stats ont été mises à jour
        $persona = $this->em->getRepository(\App\Domain\Communication\Persona::class)
            ->findOneBy(['personaCode' => 'elderly_person']);

        $scamType = $this->em->getRepository(\App\Domain\Communication\ScamType::class)
            ->findOneBy(['code' => 'PHISHING']);

        $statsRepo = $this->em->getRepository(\App\Infrastructure\Doctrine\Entity\PersonaPerformanceStatsEntity::class);
        $stats = $statsRepo->findByPersonaAndScamType($persona, $scamType);

        $this->assertNotNull($stats);
        $this->assertGreaterThan(0, $stats->getSessionsCount());
        $this->assertGreaterThan(0.0, $stats->getRewardAvg());
    }

    public function testOnConversationEndedIgnoresConversationsWithoutPersona(): void
    {
        $event = new ConversationEndedEvent(
            conversationId: '456',
            scamTypeCode: 'ROMANCE',
            personaCode: null,  // No persona
            durationSec: 1800,
            turnsCount: 4,
            iocsTotal: 1,
            iocsSensibles: 0,
            isCompleted: false
        );

        // Should not throw exception, just log and return
        $this->listener->onConversationEnded($event);

        $this->assertTrue(true); // No assertion needed, just verify no exception
    }
}
