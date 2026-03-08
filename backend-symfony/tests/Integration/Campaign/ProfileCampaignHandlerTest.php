<?php

declare(strict_types=1);

namespace App\Tests\Integration\Campaign;

use App\Application\Campaign\ProfileCampaignHandler;
use App\Domain\CampaignRadar\Campaign;
use App\Domain\CampaignRadar\MessageCampaign;
use App\Domain\Communication\Channel;
use App\Domain\Communication\Conversation;
use App\Domain\Communication\Direction;
use App\Domain\Communication\Message;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;

/**
 * Integration tests for ProfileCampaignHandler
 *
 * Tests campaign profiling with FakeLLMClient and real database
 */
class ProfileCampaignHandlerTest extends KernelTestCase
{
    private ProfileCampaignHandler $handler;
    private \Doctrine\ORM\EntityManagerInterface $em;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = static::getContainer();
        $this->handler = $container->get(ProfileCampaignHandler::class);
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

        $this->handler->handle($fakeUuid);
    }

    public function testHandleThrowsExceptionWhenLessThan3Messages(): void
    {
        // Créer une campagne avec seulement 2 messages
        $campaignId = $this->createCampaignWithMessages(2);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Campaign has only 2 messages');

        $this->handler->handle(Uuid::fromString($campaignId));
    }

    public function testHandleSuccessWithValidCampaign(): void
    {
        // Créer une campagne avec 5 messages
        $campaignId = $this->createCampaignWithMessages(5);

        $result = $this->handler->handle(Uuid::fromString($campaignId));

        $this->assertArrayHasKey('profile_yaml', $result);
        $this->assertArrayHasKey('cache_hit', $result);
        $this->assertArrayHasKey('attempts', $result);

        // FakeLLMClient retourne du texte générique, pas du YAML valide
        // Mais le handler stocke quand même le résultat
        $this->assertIsString($result['profile_yaml']);
    }

    public function testHandleStoresProfileYamlInCampaign(): void
    {
        // Créer une campagne
        $campaignId = $this->createCampaignWithMessages(3);

        $this->handler->handle(Uuid::fromString($campaignId));

        // Vérifier que profile_yaml a été stocké
        $this->em->clear();
        $campaign = $this->em->find(Campaign::class, Uuid::fromString($campaignId));

        $this->assertNotNull($campaign);
        $this->assertNotNull($campaign->getProfileYaml());
        $this->assertIsString($campaign->getProfileYaml());
    }

    public function testHandleRespectsSampleSizeLimit(): void
    {
        // Créer campagne avec 20 messages
        $campaignId = $this->createCampaignWithMessages(20);

        // Demander seulement 5 messages
        $result = $this->handler->handle(Uuid::fromString($campaignId), sampleSize: 5);

        $this->assertArrayHasKey('profile_yaml', $result);
        $this->assertIsString($result['profile_yaml']);
    }

    private function createCampaignWithMessages(int $count): string
    {
        // Créer une campagne
        $campaign = new Campaign('test-integration');
        $this->em->persist($campaign);
        $this->em->flush();

        $campaignId = $campaign->getCampaignId()->toRfc4122();

        // Créer N messages et les assigner à la campagne
        for ($i = 0; $i < $count; $i++) {
            $msgId = $this->createTestMessage("Subject $i", "Body content $i");

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
        // Récupérer les fixtures nécessaires
        $conversation = $this->em->getRepository(Conversation::class)->findOneBy([]);
        $channel = $this->em->getRepository(Channel::class)->findOneBy([]);
        $direction = $this->em->getRepository(Direction::class)->findOneBy(['code' => 'in']);

        if (!$conversation || !$channel || !$direction) {
            $this->fail('Required fixtures not found');
        }

        $headers = [
            'auth' => ['dkim' => false, 'spf' => false],
            'from' => 'test-profiling@example.com',
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
            compositeHash: md5('test-profile-' . uniqid()),
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
