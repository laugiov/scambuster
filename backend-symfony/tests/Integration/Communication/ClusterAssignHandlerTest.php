<?php

declare(strict_types=1);

namespace App\Tests\Integration\Communication;

use App\Application\Campaign\ClusterAssignHandler;
use App\Domain\Communication\Channel;
use App\Domain\Communication\Conversation;
use App\Domain\Communication\Direction;
use App\Domain\Communication\Message;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;

/**
 * Integration tests for ClusterAssignHandler
 *
 * Tests the clustering assignment logic with real database
 */
class ClusterAssignHandlerTest extends KernelTestCase
{
    private ClusterAssignHandler $handler;
    private \Doctrine\ORM\EntityManagerInterface $em;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = static::getContainer();
        $this->handler = $container->get(ClusterAssignHandler::class);
        $this->em = $container->get('doctrine')->getManager();

        // Cleanup campaigns before each test
        $this->em->getConnection()->executeStatement('DELETE FROM message_campaign');
        $this->em->getConnection()->executeStatement('DELETE FROM campaign');
        $this->em->clear();
    }

    public function testHandleThrowsExceptionWhenMessageNotFound(): void
    {
        $fakeUuid = Uuid::v7();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage("Message not found: {$fakeUuid->toRfc4122()}");

        $this->handler->handle($fakeUuid);
    }

    public function testHandleCreatesNewCampaignForNewMessage(): void
    {
        // Créer un message de test en base
        $messageId = $this->createTestMessage();

        $result = $this->handler->handle(Uuid::fromString($messageId));

        $this->assertArrayHasKey('campaign_id', $result);
        $this->assertArrayHasKey('is_new', $result);
        $this->assertArrayHasKey('confidence', $result);
        $this->assertTrue($result['is_new']);
        $this->assertEquals(1.0, $result['confidence']);
    }

    public function testHandleAssignsToExistingCampaignWithHighSimilarity(): void
    {
        // Créer deux messages similaires
        $msgId1 = $this->createTestMessage('Test Subject', 'This is a test message about phishing');
        $msgId2 = $this->createTestMessage('Test Subject', 'This is a test message about phishing too');

        // Premier message crée une campagne
        $result1 = $this->handler->handle(Uuid::fromString($msgId1));
        $this->assertTrue($result1['is_new']);
        $campaignId1 = $result1['campaign_id'];

        // Deuxième message devrait rejoindre la même campagne (similarité élevée)
        $result2 = $this->handler->handle(Uuid::fromString($msgId2));
        $this->assertFalse($result2['is_new']);
        $this->assertSame($campaignId1, $result2['campaign_id']);
        $this->assertGreaterThan(0.75, $result2['confidence']);
    }

    public function testHandleCreatesSeparateCampaignsForDissimilarMessages(): void
    {
        // Créer deux messages très différents
        $msgId1 = $this->createTestMessage('PayPal Account Suspended', 'Click here to verify your account');
        $msgId2 = $this->createTestMessage('Amazon Prime Renewal', 'Your subscription will expire soon');

        // Premier message crée une campagne
        $result1 = $this->handler->handle(Uuid::fromString($msgId1));
        $this->assertTrue($result1['is_new']);
        $campaignId1 = $result1['campaign_id'];

        // Deuxième message devrait créer une nouvelle campagne (similarité basse)
        $result2 = $this->handler->handle(Uuid::fromString($msgId2));
        $this->assertTrue($result2['is_new']);
        $this->assertNotSame($campaignId1, $result2['campaign_id']);
    }

    private function createTestMessage(
        string $subject = 'Test Subject',
        string $body = 'Test body content'
    ): string {
        // Récupérer les fixtures nécessaires
        $conversation = $this->em->getRepository(Conversation::class)->findOneBy([]);
        $channel = $this->em->getRepository(Channel::class)->findOneBy([]);
        $direction = $this->em->getRepository(Direction::class)->findOneBy(['code' => 'in']);

        if (!$conversation || !$channel || !$direction) {
            $this->fail('Required fixtures not found');
        }

        $headers = [
            'auth' => ['dkim' => false, 'spf' => false],
            'from' => 'test-clustering@example.com',
        ];

        $msgId = Uuid::v7()->toRfc4122();

        $message = new Message(
            msgId: $msgId,
            conversation: $conversation,
            channel: $channel,
            direction: $direction,
            langDetect: 'fr',
            subject: $subject,
            bodyText: $body,
            bodyHtml: null,
            headers: $headers,
            compositeHash: md5('test-' . uniqid()),
            vectorId: null,
            replyTo: null,
            tsMsg: new \DateTimeImmutable(),
            tsIngest: new \DateTimeImmutable()
        );

        $this->em->persist($message);
        $this->em->flush();

        return $msgId;
    }
}
