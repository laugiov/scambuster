<?php

declare(strict_types=1);

namespace Tests\Integration\Communication;

use App\Application\Communication\ReplyHandler;
use App\Domain\Communication\Conversation;
use App\Domain\Communication\Message;
use App\Domain\Communication\Persona;
use App\Domain\Communication\ScamType;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Regression tests to ensure PersonaOptimizer integration didn't break existing ReplyHandler functionality
 */
final class ReplyHandlerRegressionTest extends KernelTestCase
{
    private ReplyHandler $replyHandler;
    private \Doctrine\ORM\EntityManagerInterface $em;

    protected function setUp(): void
    {
        self::bootKernel();

        $this->replyHandler = self::getContainer()->get(ReplyHandler::class);
        $this->em = self::getContainer()->get('doctrine.orm.entity_manager');
    }

    public function testGetConversationContextStillWorksWithExistingPersona(): void
    {
        // Regression: ensure conversations with already-assigned persona still work
        $conversations = $this->em->getRepository(Conversation::class)
            ->createQueryBuilder('c')
            ->where('c.persona IS NOT NULL')
            ->andWhere('c.status = :status')
            ->andWhere('c.deletedAt IS NULL')
            ->setParameter('status', 'open')
            ->setMaxResults(1)
            ->getQuery()
            ->getResult();

        if (empty($conversations)) {
            $this->markTestSkipped('No conversations with persona found');
        }

        $conversation = $conversations[0];
        $context = $this->replyHandler->getConversationContext($conversation->getConvId());

        $this->assertNotNull($context);
        $this->assertArrayHasKey('persona', $context);
        $this->assertArrayHasKey('scam_type', $context);
        $this->assertArrayHasKey('last_messages', $context);
        $this->assertArrayHasKey('cadence', $context);
        
        // Verify persona matches conversation's assigned persona
        $this->assertEquals($conversation->getPersona()->getPersonaCode(), $context['persona']);
    }

    public function testGetConversationContextAssignsPersonaForNewConversation(): void
    {
        // Regression: ensure new conversations get persona assigned via PersonaOptimizer
        $conversations = $this->em->getRepository(Conversation::class)
            ->createQueryBuilder('c')
            ->where('c.persona IS NULL')
            ->andWhere('c.status = :status')
            ->andWhere('c.deletedAt IS NULL')
            ->setParameter('status', 'open')
            ->setMaxResults(1)
            ->getQuery()
            ->getResult();

        if (empty($conversations)) {
            $this->markTestSkipped('No conversations without persona found');
        }

        $conversation = $conversations[0];
        $convId = $conversation->getConvId();
        
        $context = $this->replyHandler->getConversationContext($convId);

        $this->assertNotNull($context);
        $this->assertArrayHasKey('persona', $context);
        
        // Refresh conversation to check if persona was assigned
        $this->em->refresh($conversation);
        
        // Either persona was assigned or fallback to 'generic_user'
        $this->assertTrue(
            $conversation->getPersona() !== null || $context['persona'] === 'generic_user'
        );
    }

    public function testGetConversationContextHandlesClosedConversations(): void
    {
        // Regression: closed conversations should return null
        // Filter out soft-deleted rows — getConversationContext returns
        // null for those, which is correct production behaviour but not
        // what this test is asserting against.
        $closedConversations = $this->em->getRepository(Conversation::class)
            ->findBy(['status' => 'closed', 'deletedAt' => null], null, 1);

        if (empty($closedConversations)) {
            $this->markTestSkipped('No closed conversations found');
        }

        $conversation = $closedConversations[0];
        $context = $this->replyHandler->getConversationContext($conversation->getConvId());

        // Closed conversations should still return context (they're just not editable)
        $this->assertNotNull($context);
        $this->assertEquals('closed', $context['status']);
    }

    public function testGetConversationContextReturnsCorrectStructure(): void
    {
        // Regression: ensure context structure hasn't changed
        $conversation = $this->em->getRepository(Conversation::class)
            ->createQueryBuilder('c')
            ->where('c.status = :status')
            ->andWhere('c.deletedAt IS NULL')
            ->setParameter('status', 'open')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();

        if (!$conversation) {
            $this->markTestSkipped('No open conversation found');
        }

        $context = $this->replyHandler->getConversationContext($conversation->getConvId());

        $this->assertNotNull($context);
        
        // Verify all expected keys exist
        $expectedKeys = [
            'conv_id',
            'status',
            'scam_type',
            'persona',
            'cadence',
            'last_messages',
            'extracted_iocs',
        ];

        foreach ($expectedKeys as $key) {
            $this->assertArrayHasKey($key, $context, "Missing key: {$key}");
        }

        // Verify scam_type structure
        $this->assertArrayHasKey('code', $context['scam_type']);
        $this->assertArrayHasKey('label', $context['scam_type']);

        // Verify cadence structure
        $this->assertArrayHasKey('min_hours_between_replies', $context['cadence']);
        $this->assertEquals(6, $context['cadence']['min_hours_between_replies']);

        // Verify last_messages is array
        $this->assertIsArray($context['last_messages']);
    }

    public function testGetConversationContextLimitsMessageCount(): void
    {
        // Regression: ensure message limit still works
        $conversation = $this->em->getRepository(Conversation::class)
            ->createQueryBuilder('c')
            ->where('c.status = :status')
            ->andWhere('c.deletedAt IS NULL')
            ->setParameter('status', 'open')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();

        if (!$conversation) {
            $this->markTestSkipped('No open conversation found');
        }

        $context = $this->replyHandler->getConversationContext($conversation->getConvId(), 3);

        $this->assertNotNull($context);
        $this->assertLessThanOrEqual(3, count($context['last_messages']));
    }

    public function testGetConversationContextHandlesUnknownScamType(): void
    {
        // Regression: conversations with 'unknown' scam_type should trigger classification
        $unknownConversations = $this->em->getRepository(Conversation::class)
            ->createQueryBuilder('c')
            ->join('c.scamType', 'st')
            ->where('st.code = :unknown')
            ->andWhere('c.status = :status')
            ->andWhere('c.deletedAt IS NULL')
            ->setParameter('unknown', 'unknown')
            ->setParameter('status', 'open')
            ->setMaxResults(1)
            ->getQuery()
            ->getResult();

        if (empty($unknownConversations)) {
            $this->markTestSkipped('No conversations with unknown scam_type found');
        }

        $conversation = $unknownConversations[0];
        $context = $this->replyHandler->getConversationContext($conversation->getConvId());

        $this->assertNotNull($context);
        $this->assertArrayHasKey('scam_type', $context);
        
        // After automatic classification, scam_type might have changed from 'unknown'
        // But context should still be valid
        $this->assertNotEmpty($context['scam_type']['code']);
    }

    public function testGetMessageDelegationStillWorks(): void
    {
        // Regression: getMessage() should still delegate to MessageHandler
        $message = $this->em->getRepository(Message::class)
            ->findOneBy(['deletedAt' => null]);

        if (!$message) {
            $this->markTestSkipped('No messages found');
        }

        $retrievedMessage = $this->replyHandler->getMessage($message->getMsgId());

        $this->assertNotNull($retrievedMessage);
        $this->assertEquals($message->getMsgId(), $retrievedMessage->getMsgId());
    }

}
