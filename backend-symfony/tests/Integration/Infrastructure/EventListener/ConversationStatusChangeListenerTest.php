<?php

declare(strict_types=1);

namespace App\Tests\Integration\Infrastructure\EventListener;

use App\Domain\Communication\Conversation;
use App\Domain\Communication\ConversationStatus;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

class ConversationStatusChangeListenerTest extends KernelTestCase
{
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
    }

    public function testOrphanClosureTriggersRewardCalculation(): void
    {
        $conv = $this->em->getRepository(Conversation::class)->find('00000000-0000-0000-0000-000000000001');
        $this->assertNotNull($conv);

        // Ensure conversation is OPEN with no reward
        $reflection = new \ReflectionProperty(Conversation::class, 'status');
        $reflection->setValue($conv, ConversationStatus::OPEN);
        $reflectionReward = new \ReflectionProperty(Conversation::class, 'rewardValue');
        $reflectionReward->setValue($conv, null);
        $this->em->flush();

        // Close directly via entity (bypassing ConversationClosureService)
        $conv->setStatus(ConversationStatus::CLOSED);
        $this->em->flush();

        // Listener should have calculated reward
        $this->em->refresh($conv);
        $this->assertNotNull($conv->getRewardValue(), 'Orphan closure should trigger reward calculation');
    }

    public function testDoesNotDoubleProcessWhenClosureServiceUsed(): void
    {
        $conv = $this->em->getRepository(Conversation::class)->find('00000000-0000-0000-0000-000000000001');
        $this->assertNotNull($conv);

        // Simulate ConversationClosureService behavior: set reward THEN set status
        $reflection = new \ReflectionProperty(Conversation::class, 'status');
        $reflection->setValue($conv, ConversationStatus::OPEN);
        $this->em->flush();

        $conv->setRewardValue(0.75);
        $conv->setStatus(ConversationStatus::CLOSED);
        $this->em->flush();

        // Listener should skip because reward already set
        $this->em->refresh($conv);
        $this->assertSame(0.75, round($conv->getRewardValue(), 2),
            'Should not recalculate reward when ConversationClosureService already set it'
        );
    }

    public function testIgnoresNonClosedStatusChanges(): void
    {
        $conv = $this->em->getRepository(Conversation::class)->find('00000000-0000-0000-0000-000000000001');
        $this->assertNotNull($conv);

        // Reset to OPEN
        $reflection = new \ReflectionProperty(Conversation::class, 'status');
        $reflection->setValue($conv, ConversationStatus::OPEN);
        $reflectionReward = new \ReflectionProperty(Conversation::class, 'rewardValue');
        $reflectionReward->setValue($conv, null);
        $this->em->flush();

        // Change to ABANDONED (not CLOSED)
        $conv->setStatus(ConversationStatus::ABANDONED);
        $this->em->flush();

        // Listener should NOT calculate reward for ABANDONED
        $this->em->refresh($conv);
        $this->assertNull($conv->getRewardValue(), 'Listener should only fire for CLOSED, not ABANDONED');
    }

    public function testHandlesConversationWithoutPersonaGracefully(): void
    {
        $conv = $this->em->getRepository(Conversation::class)->find('00000000-0000-0000-0000-000000000001');
        $this->assertNotNull($conv);

        // Remove persona and reset state
        $reflectionPersona = new \ReflectionProperty(Conversation::class, 'persona');
        $reflectionPersona->setValue($conv, null);
        $reflection = new \ReflectionProperty(Conversation::class, 'status');
        $reflection->setValue($conv, ConversationStatus::OPEN);
        $reflectionReward = new \ReflectionProperty(Conversation::class, 'rewardValue');
        $reflectionReward->setValue($conv, null);
        $this->em->flush();

        // Close without persona -- should not throw regardless of outcome
        $conv->setStatus(ConversationStatus::CLOSED);
        $this->em->flush();

        // No exception means graceful handling
        $this->em->refresh($conv);
        $this->assertSame(ConversationStatus::CLOSED, $conv->getStatus());
    }
}
