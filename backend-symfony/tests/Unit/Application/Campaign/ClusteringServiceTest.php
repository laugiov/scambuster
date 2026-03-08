<?php

declare(strict_types=1);

namespace App\Tests\Unit\Application\Campaign;

use App\Application\Campaign\ClusteringService;
use App\Application\Campaign\FeatureExtractor;
use App\Domain\CampaignRadar\Campaign;
use App\Domain\Communication\Message;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Uid\Uuid;

final class ClusteringServiceTest extends TestCase
{
    private ClusteringService $service;

    protected function setUp(): void
    {
        $extractor = new FeatureExtractor();

        // Mock EntityManager
        $connection = $this->createMock(Connection::class);
        $connection->method('fetchAssociative')->willReturn(null);

        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('getConnection')->willReturn($connection);

        $this->service = new ClusteringService($extractor, $em, new NullLogger());
    }

    public function testAssignCampaignReturnsNullWhenNoCampaignsExist(): void
    {
        $message = $this->createMockMessage();

        $result = $this->service->assignCampaign($message, []);

        $this->assertNull($result['campaign_id']);
        $this->assertSame(1.0, $result['confidence']);
        $this->assertArrayHasKey('features', $result);
    }

    public function testAssignCampaignReturnsResultStructure(): void
    {
        $message = $this->createMockMessage();
        $campaign = new Campaign('test');

        $result = $this->service->assignCampaign($message, [$campaign]);

        $this->assertArrayHasKey('campaign_id', $result);
        $this->assertArrayHasKey('confidence', $result);
        $this->assertArrayHasKey('features', $result);
    }

    public function testAssignCampaignReturnsFeaturesInResult(): void
    {
        $message = $this->createMockMessage('Test Subject', 'Test body content');

        $result = $this->service->assignCampaign($message, []);

        $this->assertIsArray($result['features']);
        $this->assertArrayHasKey('text', $result['features']);
        $this->assertArrayHasKey('infra', $result['features']);
        $this->assertArrayHasKey('style', $result['features']);
    }

    public function testAssignCampaignAssignsToExistingCampaignWithHighSimilarity(): void
    {
        // Créer une campagne avec un centroid
        $campaign = new Campaign('test');
        $simhash = md5('test subject test body');
        $campaign->setCentroidSimhash($simhash);

        // Mock database to return campaign message features for Jaccard similarity
        $connection = $this->createMock(Connection::class);
        $connection->method('fetchAssociative')->willReturn([
            'subject' => 'test subject',
            'body' => 'test body',
        ]);

        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('getConnection')->willReturn($connection);

        // Create service with mocked EM
        $extractor = new FeatureExtractor();
        $service = new ClusteringService($extractor, $em, new NullLogger());

        // Message très similaire (même texte)
        $message = $this->createMockMessage('test subject', 'test body');

        $result = $service->assignCampaign($message, [$campaign]);

        // Devrait assigner à la campagne existante (100% Jaccard similarity)
        $this->assertNotNull($result['campaign_id']);
        $this->assertSame($campaign->getCampaignId()->toRfc4122(), $result['campaign_id']);
    }

    private function createMockMessage(string $subject = 'Test', string $body = 'Body'): Message
    {
        $message = $this->createMock(Message::class);
        $message->method('getSubject')->willReturn($subject);
        $message->method('getBodyText')->willReturn($body);
        $message->method('getBodyHtml')->willReturn(null);
        $message->method('getHeaders')->willReturn(['auth' => ['dkim' => false, 'spf' => false]]);

        return $message;
    }
}
