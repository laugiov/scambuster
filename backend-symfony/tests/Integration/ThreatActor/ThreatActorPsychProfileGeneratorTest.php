<?php

declare(strict_types=1);

namespace App\Tests\Integration\ThreatActor;

use App\Application\Clustering\ClusterQueryService;
use App\Application\LLM\Port\LLMClientInterface;
use App\Application\ThreatActor\ThreatActorPsychProfileGenerator;
use App\Tests\Fixtures\ClusteringFixtures;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\MockObject\MockObject;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

/**
 * Exercises the real Postgres upsert path (CAST TEXT[], ON CONFLICT) against a
 * clustered fixture. The LLM is mocked; everything below it is real.
 */
final class ThreatActorPsychProfileGeneratorTest extends KernelTestCase
{
    private Connection $conn;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->conn = self::getContainer()->get(Connection::class);

        ClusteringFixtures::cleanup($this->conn);
        ClusteringFixtures::load($this->conn);

        // Build the clusters the fixture is designed to produce.
        $app = new Application(self::$kernel);
        (new CommandTester($app->find('app:clustering:backfill')))->execute([]);

        // The fixture picks an arbitrary direction; make the clustered messages
        // inbound so the generator has a scammer corpus to profile.
        $this->conn->executeStatement(
            "UPDATE message SET direction = (SELECT dir_id FROM lkp_direction WHERE code = 'in')
             WHERE conv_id IN (SELECT conv_id FROM threat_actor_cluster_conversation)",
        );
    }

    protected function tearDown(): void
    {
        $this->conn->executeStatement('DELETE FROM threat_actor_psych_profile');
        ClusteringFixtures::cleanup($this->conn);
        parent::tearDown();
    }

    private function clusterWithInboundMessages(): string
    {
        $id = $this->conn->fetchOne(
            "SELECT tacc.cluster_id
             FROM threat_actor_cluster_conversation tacc
             JOIN message m ON m.conv_id = tacc.conv_id
             JOIN lkp_direction d ON d.dir_id = m.direction AND d.code = 'in'
             JOIN threat_actor_cluster tac ON tac.cluster_id = tacc.cluster_id
             WHERE tac.merged_into_id IS NULL
             GROUP BY tacc.cluster_id
             ORDER BY COUNT(*) DESC
             LIMIT 1",
        );

        self::assertIsString($id, 'fixture should produce a cluster with inbound messages');

        return $id;
    }

    private function generatorWithLlmReturning(string $json): ThreatActorPsychProfileGenerator
    {
        /** @var LLMClientInterface&MockObject $llm */
        $llm = $this->createMock(LLMClientInterface::class);
        $llm->method('chat')->willReturn($json);

        return new ThreatActorPsychProfileGenerator(
            $llm,
            $this->conn,
            new ClusterQueryService($this->conn),
            $this->createMock(EventDispatcherInterface::class),
            new NullLogger(),
        );
    }

    public function testGenerateForClusterPersistsAProfileRow(): void
    {
        $clusterId = $this->clusterWithInboundMessages();
        $json = (string) json_encode([
            'dominant_lever'      => 'Urgency',
            'secondary_levers'    => ['Authority', 'Scarcity'],
            'behavioural_summary' => 'Escalates deadlines and cites authority.',
            'escalation_pattern'  => 'rapid',
            'victim_targeting'    => 'Time-poor account holders.',
        ]);

        $profile = $this->generatorWithLlmReturning($json)->generateForCluster($clusterId);

        self::assertNotNull($profile);

        $row = $this->conn->fetchAssociative(
            'SELECT dominant_lever, secondary_levers, escalation_pattern FROM threat_actor_psych_profile WHERE cluster_id = :c',
            ['c' => $clusterId],
        );

        self::assertIsArray($row);
        self::assertSame('Urgency', $row['dominant_lever']);
        self::assertSame('rapid', $row['escalation_pattern']);
        // Postgres renders text[] as {Authority,Scarcity}
        self::assertStringContainsString('Authority', (string) $row['secondary_levers']);
        self::assertStringContainsString('Scarcity', (string) $row['secondary_levers']);
    }

    public function testSecondGenerationUpsertsRatherThanDuplicates(): void
    {
        $clusterId = $this->clusterWithInboundMessages();

        $first = (string) json_encode([
            'dominant_lever' => 'Urgency', 'secondary_levers' => [],
            'behavioural_summary' => 'first', 'escalation_pattern' => 'rapid', 'victim_targeting' => 'a',
        ]);
        $second = (string) json_encode([
            'dominant_lever' => 'Authority', 'secondary_levers' => [],
            'behavioural_summary' => 'second', 'escalation_pattern' => 'gradual', 'victim_targeting' => 'b',
        ]);

        $this->generatorWithLlmReturning($first)->generateForCluster($clusterId);
        self::assertTrue($this->generatorWithLlmReturning($second)->exists($clusterId));
        $this->generatorWithLlmReturning($second)->generateForCluster($clusterId);

        $count = (int) $this->conn->fetchOne(
            'SELECT COUNT(*) FROM threat_actor_psych_profile WHERE cluster_id = :c',
            ['c' => $clusterId],
        );
        self::assertSame(1, $count, 'upsert must keep exactly one row per cluster');

        $lever = $this->conn->fetchOne(
            'SELECT dominant_lever FROM threat_actor_psych_profile WHERE cluster_id = :c',
            ['c' => $clusterId],
        );
        self::assertSame('Authority', $lever, 'second run should overwrite the first');
    }
}
