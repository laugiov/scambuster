<?php

declare(strict_types=1);

namespace Tests\Functional\Stix;

use App\Application\Clustering\IocClusteringService;
use App\Tests\Fixtures\ClusteringFixtures;
use Doctrine\DBAL\Connection;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Conversation export cluster delegation.
 *
 * When a conversation belongs to a cluster, the conversation export must:
 *   - emit the CLUSTER threat-actor (not a per-conversation one)
 *   - target every `indicates` relationship at the cluster threat-actor
 *   - include the cluster threat-actor in the report's object_refs
 *
 * Loads ClusteringFixtures (3 expected clusters: A=5 conv, B=3 conv, C=3 conv).
 */
final class ConversationStixExportClusterDelegationTest extends WebTestCase
{
    private KernelBrowser $client;
    private Connection $conn;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->conn = static::getContainer()->get(Connection::class);

        ClusteringFixtures::cleanup($this->conn);
        ClusteringFixtures::load($this->conn);

        // Create the 3 expected clusters from fixture data
        $service = new IocClusteringService($this->conn, new NullLogger());
        $service->clusterConversation(sprintf('cccccccc-aaaa-4000-8000-%012d', 1));
        $service->clusterConversation(sprintf('cccccccc-bbbb-4000-8000-%012d', 1));
        $service->clusterConversation(sprintf('cccccccc-cccc-4000-8000-%012d', 2));
    }

    protected function tearDown(): void
    {
        ClusteringFixtures::cleanup($this->conn);
        parent::tearDown();
    }

    /**
     * Returns the conv ID of a conversation in cluster A.
     */
    private function getClusteredConvId(): string
    {
        return sprintf('cccccccc-aaaa-4000-8000-%012d', 1);
    }

    public function testClusteredConversationUsesClusterThreatActor(): void
    {
        $convId = $this->getClusteredConvId();

        $this->client->request('GET', '/api/v1/conversations/' . $convId . '/export/stix', [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer fake-jwt',
        ]);

        $this->assertResponseIsSuccessful();
        $data = $this->decodeResponse();

        $actors = array_values(array_filter($data['objects'], fn (array $o) => ($o['type'] ?? '') === 'threat-actor'));
        $this->assertCount(1, $actors, 'Clustered conversation must contain exactly one threat-actor (the cluster one).');

        $name = \is_string($actors[0]['name'] ?? null) ? $actors[0]['name'] : '';
        $this->assertStringStartsWith(
            'ScamBuster Cluster',
            $name,
            'Clustered conversation must use the cluster threat-actor name, not the per-conversation one.',
        );
    }

    public function testClusteredConversationHasNoPerConversationActor(): void
    {
        $convId = $this->getClusteredConvId();

        $this->client->request('GET', '/api/v1/conversations/' . $convId . '/export/stix', [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer fake-jwt',
        ]);

        $data = $this->decodeResponse();
        $actors = array_filter($data['objects'], fn (array $o) => ($o['type'] ?? '') === 'threat-actor');

        foreach ($actors as $actor) {
            $name = \is_string($actor['name'] ?? null) ? $actor['name'] : '';
            $this->assertStringStartsNotWith(
                'ScamBuster Actor - ',
                $name,
                'Clustered conversation must not emit a per-conversation threat-actor.',
            );
            $this->assertStringStartsNotWith(
                'Unattributed Scam Actor',
                $name,
                'Clustered conversation must not emit an unattributed singleton actor.',
            );
        }
    }

    public function testIndicatesRelationshipsTargetClusterActorWhenAvailable(): void
    {
        $convId = $this->getClusteredConvId();

        $this->client->request('GET', '/api/v1/conversations/' . $convId . '/export/stix', [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer fake-jwt',
        ]);

        $data = $this->decodeResponse();

        $actors = array_values(array_filter($data['objects'], fn (array $o) => ($o['type'] ?? '') === 'threat-actor'));
        $this->assertCount(1, $actors);
        $clusterActorId = $actors[0]['id'] ?? '';
        $this->assertStringStartsWith('threat-actor--', (string) $clusterActorId);

        $indicatesRels = array_values(array_filter(
            $data['objects'],
            fn (array $o) => ($o['type'] ?? '') === 'relationship' && ($o['relationship_type'] ?? '') === 'indicates',
        ));

        $this->assertNotEmpty($indicatesRels, 'Clustered conversation must still have indicates relationships.');

        foreach ($indicatesRels as $rel) {
            $this->assertSame(
                $clusterActorId,
                $rel['target_ref'] ?? null,
                'Every indicates relationship must target the cluster threat-actor.',
            );
        }
    }

    public function testReportObjectRefsIncludeClusterActor(): void
    {
        $convId = $this->getClusteredConvId();

        $this->client->request('GET', '/api/v1/conversations/' . $convId . '/export/stix', [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer fake-jwt',
        ]);

        $data = $this->decodeResponse();

        $actors = array_values(array_filter($data['objects'], fn (array $o) => ($o['type'] ?? '') === 'threat-actor'));
        $this->assertCount(1, $actors);
        $clusterActorId = $actors[0]['id'] ?? '';

        $reports = array_values(array_filter($data['objects'], fn (array $o) => ($o['type'] ?? '') === 'report'));
        $this->assertCount(1, $reports);
        $objectRefs = \is_array($reports[0]['object_refs'] ?? null) ? $reports[0]['object_refs'] : [];

        $this->assertContains(
            $clusterActorId,
            $objectRefs,
            'The report object_refs must include the cluster threat-actor STIX ID.',
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeResponse(): array
    {
        $content = (string) $this->client->getResponse()->getContent();
        $data = json_decode($content, true);
        $this->assertIsArray($data);
        $this->assertSame('bundle', $data['type'] ?? null);

        return $data;
    }
}
