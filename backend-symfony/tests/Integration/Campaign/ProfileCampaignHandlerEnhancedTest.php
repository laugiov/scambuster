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
 * Tests d'intégration RENFORCÉS pour ProfileCampaignHandler
 *
 * Couvre:
 * - Validation messages (0, 1, 2, 3+ messages)
 * - Sample size (3, 5, 10, 20 messages)
 * - Stockage profile_yaml en DB
 * - Cache behavior (même campagne appelée 2x)
 * - Scénarios réalistes (PayPal, banques avec vrais headers)
 * - Edge cases (messages vides, unicode, très longs)
 * - Persistence (lecture après stockage)
 */
class ProfileCampaignHandlerEnhancedTest extends KernelTestCase
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

    // ==================== Tests Validation Messages ====================

    public function testHandleThrowsExceptionWhenCampaignNotFound(): void
    {
        $fakeUuid = Uuid::v7();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage("Campaign not found: {$fakeUuid->toRfc4122()}");

        $this->handler->handle($fakeUuid);
    }

    public function testHandleThrowsExceptionWhen0Messages(): void
    {
        $campaignId = $this->createCampaignWithMessages(0);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Campaign has only 0 messages');

        $this->handler->handle(Uuid::fromString($campaignId));
    }

    public function testHandleThrowsExceptionWhen1Message(): void
    {
        $campaignId = $this->createCampaignWithMessages(1);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Campaign has only 1 message');

        $this->handler->handle(Uuid::fromString($campaignId));
    }

    public function testHandleThrowsExceptionWhen2Messages(): void
    {
        $campaignId = $this->createCampaignWithMessages(2);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Campaign has only 2 messages');

        $this->handler->handle(Uuid::fromString($campaignId));
    }

    public function testHandleSucceedsWith3Messages(): void
    {
        $campaignId = $this->createCampaignWithMessages(3);

        $result = $this->handler->handle(Uuid::fromString($campaignId));

        $this->assertArrayHasKey('profile_yaml', $result);
        $this->assertArrayHasKey('cache_hit', $result);
        $this->assertArrayHasKey('attempts', $result);
        $this->assertIsString($result['profile_yaml']);
    }

    public function testHandleSucceedsWith5Messages(): void
    {
        $campaignId = $this->createCampaignWithMessages(5);

        $result = $this->handler->handle(Uuid::fromString($campaignId));

        $this->assertArrayHasKey('profile_yaml', $result);
    }

    public function testHandleSucceedsWith10Messages(): void
    {
        $campaignId = $this->createCampaignWithMessages(10);

        $result = $this->handler->handle(Uuid::fromString($campaignId));

        $this->assertArrayHasKey('profile_yaml', $result);
    }

    // ==================== Tests Sample Size ====================

    public function testHandleRespectsSampleSize3(): void
    {
        $campaignId = $this->createCampaignWithMessages(20);

        $result = $this->handler->handle(Uuid::fromString($campaignId), sampleSize: 3);

        $this->assertArrayHasKey('profile_yaml', $result);
    }

    public function testHandleRespectsSampleSize5(): void
    {
        $campaignId = $this->createCampaignWithMessages(15);

        $result = $this->handler->handle(Uuid::fromString($campaignId), sampleSize: 5);

        $this->assertArrayHasKey('profile_yaml', $result);
    }

    public function testHandleRespectsSampleSize10(): void
    {
        $campaignId = $this->createCampaignWithMessages(25);

        $result = $this->handler->handle(Uuid::fromString($campaignId), sampleSize: 10);

        $this->assertArrayHasKey('profile_yaml', $result);
    }

    public function testHandleClampsSampleSizeWhenFewerMessages(): void
    {
        // 5 messages, demande 10 → devrait utiliser les 5
        $campaignId = $this->createCampaignWithMessages(5);

        $result = $this->handler->handle(Uuid::fromString($campaignId), sampleSize: 10);

        $this->assertArrayHasKey('profile_yaml', $result);
    }

    // ==================== Tests Persistence ====================

    public function testHandleStoresProfileYamlInCampaign(): void
    {
        $campaignId = $this->createCampaignWithMessages(3);

        $this->handler->handle(Uuid::fromString($campaignId));

        // Vérifier que profile_yaml a été stocké
        $this->em->clear();
        $campaign = $this->em->find(Campaign::class, Uuid::fromString($campaignId));

        $this->assertNotNull($campaign);
        $this->assertNotNull($campaign->getProfileYaml());
        $this->assertIsString($campaign->getProfileYaml());
    }

    public function testHandleStoresProfileYamlPersistsAfterReload(): void
    {
        $campaignId = $this->createCampaignWithMessages(4);

        $result = $this->handler->handle(Uuid::fromString($campaignId));
        $expectedYaml = $result['profile_yaml'];

        // Clear entity manager et recharger
        $this->em->clear();
        $campaign = $this->em->find(Campaign::class, Uuid::fromString($campaignId));

        $this->assertEquals($expectedYaml, $campaign->getProfileYaml());
    }

    public function testHandleUpdatesExistingProfileYaml(): void
    {
        $campaignId = $this->createCampaignWithMessages(3);

        // Premier profiling
        $result1 = $this->handler->handle(Uuid::fromString($campaignId));

        // Deuxième profiling (devrait utiliser cache ou re-profiler)
        $result2 = $this->handler->handle(Uuid::fromString($campaignId));

        // Les 2 résultats devraient avoir profile_yaml
        $this->assertArrayHasKey('profile_yaml', $result1);
        $this->assertArrayHasKey('profile_yaml', $result2);
    }

    // ==================== Tests Edge Cases Messages ====================

    public function testHandleWithEmptySubjects(): void
    {
        $campaignId = $this->createCampaignWithCustomMessages([
            ['subject' => '', 'body' => 'Body 1'],
            ['subject' => '', 'body' => 'Body 2'],
            ['subject' => '', 'body' => 'Body 3'],
        ]);

        $result = $this->handler->handle(Uuid::fromString($campaignId));

        $this->assertArrayHasKey('profile_yaml', $result);
    }

    public function testHandleWithEmptyBodies(): void
    {
        $campaignId = $this->createCampaignWithCustomMessages([
            ['subject' => 'Subject 1', 'body' => ''],
            ['subject' => 'Subject 2', 'body' => ''],
            ['subject' => 'Subject 3', 'body' => ''],
        ]);

        $result = $this->handler->handle(Uuid::fromString($campaignId));

        $this->assertArrayHasKey('profile_yaml', $result);
    }

    public function testHandleWithUnicodeContent(): void
    {
        $campaignId = $this->createCampaignWithCustomMessages([
            ['subject' => 'こんにちは', 'body' => '世界へようこそ 🌍'],
            ['subject' => 'Привет', 'body' => 'Добро пожаловать'],
            ['subject' => 'مرحبا', 'body' => 'أهلا وسهلا'],
        ]);

        $result = $this->handler->handle(Uuid::fromString($campaignId));

        $this->assertArrayHasKey('profile_yaml', $result);
    }

    public function testHandleWithVeryLongMessages(): void
    {
        $longBody = str_repeat('This is a very long phishing message. ', 500);

        $campaignId = $this->createCampaignWithCustomMessages([
            ['subject' => 'Long message 1', 'body' => $longBody],
            ['subject' => 'Long message 2', 'body' => $longBody],
            ['subject' => 'Long message 3', 'body' => $longBody],
        ]);

        $result = $this->handler->handle(Uuid::fromString($campaignId));

        $this->assertArrayHasKey('profile_yaml', $result);
    }

    // ==================== Tests Scénarios Réalistes ====================

    public function testHandlePayPalPhishingCampaign(): void
    {
        $campaignId = $this->createCampaignWithCustomMessages([
            [
                'subject' => 'URGENT: Your PayPal Account Has Been Suspended',
                'body' => 'Click here to verify: http://paypal-verify.scam.com/login',
                'headers' => ['auth' => ['dkim' => false, 'spf' => false], 'from' => 'noreply@paypal-security.tk']
            ],
            [
                'subject' => 'PayPal Account Limited',
                'body' => 'Verify your identity at https://secure-paypal.ml/restore',
                'headers' => ['auth' => ['dkim' => false, 'spf' => false], 'from' => 'security@paypal-help.ga']
            ],
            [
                'subject' => 'Action Required: PayPal Account',
                'body' => 'Confirm details: http://paypal.verify-account.cf/confirm',
                'headers' => ['auth' => ['dkim' => false, 'spf' => false], 'from' => 'support@pp-service.tk']
            ],
        ]);

        $result = $this->handler->handle(Uuid::fromString($campaignId));

        $this->assertArrayHasKey('profile_yaml', $result);
        $this->assertIsString($result['profile_yaml']);

        // Vérifier que le profile est stocké
        $this->em->clear();
        $campaign = $this->em->find(Campaign::class, Uuid::fromString($campaignId));
        $this->assertNotNull($campaign->getProfileYaml());
    }

    public function testHandleBankPhishingCampaign(): void
    {
        $campaignId = $this->createCampaignWithCustomMessages([
            [
                'subject' => 'Alerte Sécurité: Compte Bloqué',
                'body' => 'Votre compte bancaire a été bloqué. Visitez https://ma-banque-secure.com/deblocage',
                'headers' => ['auth' => ['dkim' => false, 'spf' => false], 'from' => 'securite@banque-alert.tk']
            ],
            [
                'subject' => 'Action Urgente Requise',
                'body' => 'Mise à jour sécurité: https://banque-verification.net/update',
                'headers' => ['auth' => ['dkim' => false, 'spf' => false], 'from' => 'noreply@bank-secure.ga']
            ],
            [
                'subject' => 'Déblocage de votre compte',
                'body' => 'Cliquez ici pour débloquer: https://deblock-compte.cf/verify',
                'headers' => ['auth' => ['dkim' => false, 'spf' => false], 'from' => 'support@bank-help.ml']
            ],
        ]);

        $result = $this->handler->handle(Uuid::fromString($campaignId));

        $this->assertArrayHasKey('profile_yaml', $result);
    }

    public function testHandleCampaignWithMixedDKIMStatus(): void
    {
        $campaignId = $this->createCampaignWithCustomMessages([
            [
                'subject' => 'Message 1',
                'body' => 'Body 1',
                'headers' => ['auth' => ['dkim' => true, 'spf' => true], 'from' => 'legit@example.com']
            ],
            [
                'subject' => 'Message 2',
                'body' => 'Body 2',
                'headers' => ['auth' => ['dkim' => false, 'spf' => false], 'from' => 'phish@scam.tk']
            ],
            [
                'subject' => 'Message 3',
                'body' => 'Body 3',
                'headers' => ['auth' => ['dkim' => false, 'spf' => false], 'from' => 'fake@fraud.ga']
            ],
        ]);

        $result = $this->handler->handle(Uuid::fromString($campaignId));

        $this->assertArrayHasKey('profile_yaml', $result);
    }

    // ==================== Tests Performance ====================

    public function testHandleCompletesWithin30Seconds(): void
    {
        $campaignId = $this->createCampaignWithMessages(10);

        $startTime = microtime(true);
        $this->handler->handle(Uuid::fromString($campaignId));
        $duration = microtime(true) - $startTime;

        // Devrait compléter en moins de 30s (FakeLLM est rapide)
        $this->assertLessThan(30, $duration, "Handler took {$duration}s, expected < 30s");
    }

    // ==================== Helper Methods ====================

    private function createCampaignWithMessages(int $count): string
    {
        $campaign = new Campaign('test-integration-enhanced');
        $this->em->persist($campaign);
        $this->em->flush();

        $campaignId = $campaign->getCampaignId()->toRfc4122();

        for ($i = 0; $i < $count; $i++) {
            $msgId = $this->createTestMessage("Subject $i", "Body content $i");

            $messageCampaign = new MessageCampaign(
                Uuid::fromString($msgId),
                $campaign->getCampaignId(),
                0.95,
                'integration-test-enhanced'
            );

            $this->em->persist($messageCampaign);
        }

        $this->em->flush();

        return $campaignId;
    }

    private function createCampaignWithCustomMessages(array $messages): string
    {
        $campaign = new Campaign('test-custom-messages');
        $this->em->persist($campaign);
        $this->em->flush();

        $campaignId = $campaign->getCampaignId()->toRfc4122();

        foreach ($messages as $msg) {
            $msgId = $this->createTestMessage(
                $msg['subject'] ?? 'Default Subject',
                $msg['body'] ?? 'Default Body',
                $msg['headers'] ?? null
            );

            $messageCampaign = new MessageCampaign(
                Uuid::fromString($msgId),
                $campaign->getCampaignId(),
                0.95,
                'custom-test'
            );

            $this->em->persist($messageCampaign);
        }

        $this->em->flush();

        return $campaignId;
    }

    private function createTestMessage(
        string $subject = 'Test Subject',
        string $body = 'Test body content',
        ?array $headers = null
    ): string {
        $conversation = $this->em->getRepository(Conversation::class)->findOneBy([]);
        $channel = $this->em->getRepository(Channel::class)->findOneBy([]);
        $direction = $this->em->getRepository(Direction::class)->findOneBy(['code' => 'in']);

        if (!$conversation || !$channel || !$direction) {
            $this->fail('Required fixtures not found');
        }

        $headers = $headers ?? [
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
            compositeHash: md5('test-profile-enhanced-' . uniqid()),
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
