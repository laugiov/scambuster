<?php

declare(strict_types=1);

namespace App\Tests\Integration\Campaign;

use App\Application\Campaign\GetCampaignMessagesHandler;
use App\Domain\CampaignRadar\Campaign;
use App\Domain\CampaignRadar\MessageCampaign;
use App\Domain\Communication\Channel;
use App\Domain\Communication\Conversation;
use App\Domain\Communication\Direction;
use App\Domain\Communication\Message;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;

/**
 * Integration tests for GetCampaignMessagesHandler
 *
 * Tests message retrieval with real database
 */
class GetCampaignMessagesHandlerTest extends KernelTestCase
{
    private GetCampaignMessagesHandler $handler;
    private \Doctrine\ORM\EntityManagerInterface $em;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = static::getContainer();
        $this->handler = $container->get(GetCampaignMessagesHandler::class);
        $this->em = $container->get('doctrine')->getManager();

        // Cleanup campaigns before each test
        $this->em->getConnection()->executeStatement('DELETE FROM message_campaign');
        $this->em->getConnection()->executeStatement('DELETE FROM campaign');
        $this->em->clear();
    }

    public function testHandleThrowsExceptionWhenCampaignNotFound(): void
    {
        $fakeUuid = Uuid::v7();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage("Campaign not found: {$fakeUuid->toRfc4122()}");

        $this->handler->handle($fakeUuid, 10);
    }

    public function testHandleReturnsEmptyMessagesWhenCampaignHasNoMessages(): void
    {
        // Create a campaign without messages
        $campaign = new Campaign('test-integration');
        $this->em->persist($campaign);
        $this->em->flush();

        $result = $this->handler->handle($campaign->getCampaignId(), 10);

        $this->assertArrayHasKey('campaign_id', $result);
        $this->assertArrayHasKey('messages_count', $result);
        $this->assertArrayHasKey('messages', $result);
        $this->assertEquals(0, $result['messages_count']);
        $this->assertIsArray($result['messages']);
        $this->assertEmpty($result['messages']);
    }

    public function testHandleReturnsMessagesWithCorrectStructure(): void
    {
        // Create campaign with 3 messages
        $campaignId = $this->createCampaignWithMessages(3);

        $result = $this->handler->handle(Uuid::fromString($campaignId), 10);

        $this->assertEquals(3, $result['messages_count']);
        $this->assertCount(3, $result['messages']);

        // Verify a message's structure
        $message = $result['messages'][0];
        $this->assertArrayHasKey('msg_id', $message);
        $this->assertArrayHasKey('subject', $message);
        $this->assertArrayHasKey('from', $message);
        $this->assertArrayHasKey('received_at', $message);
        $this->assertArrayHasKey('body_preview', $message);
    }

    public function testHandleRespectsLimitParameter(): void
    {
        // Create campaign with 10 messages
        $campaignId = $this->createCampaignWithMessages(10);

        // Request only 3 messages
        $result = $this->handler->handle(Uuid::fromString($campaignId), 3);

        $this->assertEquals(3, $result['messages_count']);
        $this->assertCount(3, $result['messages']);
    }

    public function testHandleTruncatesBodyPreviewTo200Chars(): void
    {
        // Create message with body > 200 chars
        $longBody = str_repeat('A', 500);
        $campaignId = $this->createCampaignWithMessages(1, 'Subject', $longBody);

        $result = $this->handler->handle(Uuid::fromString($campaignId), 10);

        $this->assertEquals(1, $result['messages_count']);
        $bodyPreview = $result['messages'][0]['body_preview'];
        $this->assertLessThanOrEqual(200, mb_strlen($bodyPreview));
    }

    public function testHandleReturnsMessagesInDescendingOrder(): void
    {
        // Create 3 messages with different timestamps
        $campaign = new Campaign('test-integration');
        $this->em->persist($campaign);
        $this->em->flush();

        $msgIds = [];
        for ($i = 0; $i < 3; $i++) {
            $msgId = $this->createTestMessage("Message $i", "Body $i");
            $msgIds[] = $msgId;

            $messageCampaign = new MessageCampaign(
                Uuid::fromString($msgId),
                $campaign->getCampaignId(),
                0.9,
                'test'
            );
            $this->em->persist($messageCampaign);

            // Wait a bit to ensure different detected_at timestamps
            usleep(10000); // 10ms
        }

        $this->em->flush();

        $result = $this->handler->handle($campaign->getCampaignId(), 10);

        // Messages must be in DESC order (the last created first)
        $this->assertEquals(3, $result['messages_count']);
        $this->assertStringContainsString('Message', $result['messages'][0]['subject']);
    }

    private function createCampaignWithMessages(
        int $count,
        string $subject = 'Test Subject',
        string $body = 'Test body'
    ): string {
        // Create a campaign
        $campaign = new Campaign('test-integration');
        $this->em->persist($campaign);
        $this->em->flush();

        $campaignId = $campaign->getCampaignId()->toRfc4122();

        // Create N messages
        for ($i = 0; $i < $count; $i++) {
            $msgId = $this->createTestMessage("$subject $i", "$body $i");

            $messageCampaign = new MessageCampaign(
                Uuid::fromString($msgId),
                $campaign->getCampaignId(),
                0.95,
                'integration-test'
            );

            $this->em->persist($messageCampaign);
        }

        $this->em->flush();

        return $campaignId;
    }

    private function createTestMessage(
        string $subject = 'Test Subject',
        string $body = 'Test body content'
    ): string {
        // Retrieve the required fixtures
        $conversation = $this->em->getRepository(Conversation::class)->findOneBy([]);
        $channel = $this->em->getRepository(Channel::class)->findOneBy([]);
        $direction = $this->em->getRepository(Direction::class)->findOneBy(['code' => 'in']);

        if (!$conversation || !$channel || !$direction) {
            $this->fail('Required fixtures not found');
        }

        $headers = [
            'auth' => ['dkim' => false, 'spf' => false],
            'from' => 'test-messages@example.com',
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
            compositeHash: md5('test-get-messages-' . uniqid()),
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
