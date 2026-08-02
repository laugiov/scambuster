<?php

declare(strict_types=1);

namespace App\Tests\Functional\ThreatActor;

use App\Application\Clustering\IocClusteringService;
use App\Tests\Fixtures\ClusteringFixtures;
use Doctrine\DBAL\Connection;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class GetActorPsychProfileControllerTest extends WebTestCase
{
    private KernelBrowser $client;
    private Connection $conn;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->conn = static::getContainer()->get(Connection::class);

        ClusteringFixtures::cleanup($this->conn);
        ClusteringFixtures::load($this->conn);

        $service = new IocClusteringService($this->conn, new NullLogger());
        $service->clusterConversation(sprintf('cccccccc-aaaa-4000-8000-%012d', 1));
        $service->clusterConversation(sprintf('cccccccc-bbbb-4000-8000-%012d', 1));
    }

    protected function tearDown(): void
    {
        $this->conn->executeStatement('DELETE FROM threat_actor_psych_profile');
        ClusteringFixtures::cleanup($this->conn);
        parent::tearDown();
    }

    private function firstClusterId(): string
    {
        $id = $this->conn->fetchOne('SELECT cluster_id FROM threat_actor_cluster WHERE merged_into_id IS NULL LIMIT 1');
        self::assertIsString($id);

        return $id;
    }

    private function seedProfile(string $clusterId): void
    {
        $this->conn->executeStatement(
            "INSERT INTO threat_actor_psych_profile
                (cluster_id, dominant_lever, secondary_levers, behavioural_summary, escalation_pattern,
                 victim_targeting, dominant_stimulus, avg_urgency, hesitation_events, language_switches,
                 conversation_count, message_count, generated_at, generated_by_model, prompt_version)
             VALUES
                (:cid, 'Urgency', CAST('{Authority,Scarcity}' AS TEXT[]), 'Escalates deadlines.', 'rapid',
                 'Time-poor holders.', 'fear', 0.7, 2, 1, 3, 20, NOW(), 'gpt-4o-mini', 'v1')",
            ['cid' => $clusterId],
        );
    }

    public function testUnauthenticatedIsRejected(): void
    {
        $this->client->request('GET', '/api/v1/clusters/' . $this->firstClusterId() . '/psych-profile');

        self::assertSame(401, $this->client->getResponse()->getStatusCode());
    }

    public function testReturns404WhenNoProfileExists(): void
    {
        $this->client->request('GET', '/api/v1/clusters/' . $this->firstClusterId() . '/psych-profile', [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer fake-jwt',
        ]);

        self::assertSame(404, $this->client->getResponse()->getStatusCode());
    }

    public function testReturnsProfileWhenPresent(): void
    {
        $clusterId = $this->firstClusterId();
        $this->seedProfile($clusterId);

        $this->client->request('GET', '/api/v1/clusters/' . $clusterId . '/psych-profile', [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer fake-jwt',
        ]);

        self::assertResponseIsSuccessful();
        $data = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertIsArray($data);
        self::assertSame('Urgency', $data['dominant_lever']);
        self::assertSame(['Authority', 'Scarcity'], $data['secondary_levers']);
        self::assertSame('rapid', $data['escalation_pattern']);
        self::assertSame('fear', $data['dominant_stimulus']);
        self::assertSame($clusterId, $data['cluster_id']);
    }
}
