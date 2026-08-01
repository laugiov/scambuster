<?php

declare(strict_types=1);

namespace App\Tests\Integration\Clustering;

use App\Application\Clustering\IocClusteringService;
use App\Tests\Fixtures\ClusteringFixtures;
use Doctrine\DBAL\Connection;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * G2 fuzzy anchor matching: conversations sharing a formatting-variant anchor IOC
 * (same value, different case/separators) must cluster; genuinely different values
 * (one digit apart) must NOT.
 */
final class FuzzyAnchorClusteringTest extends KernelTestCase
{
    private Connection $conn;
    private IocClusteringService $service;

    /** @var array<string, string> */
    private array $convs;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->conn = self::getContainer()->get(Connection::class);
        $this->service = new IocClusteringService($this->conn, new NullLogger());

        ClusteringFixtures::cleanup($this->conn);
        $this->convs = ClusteringFixtures::loadFuzzyScenario($this->conn);

        foreach ($this->convs as $convId) {
            $this->service->clusterConversation($convId);
        }
    }

    protected function tearDown(): void
    {
        ClusteringFixtures::cleanup($this->conn);
        parent::tearDown();
    }

    private function clusterId(string $key): ?string
    {
        $id = $this->conn->fetchOne(
            'SELECT cluster_id FROM threat_actor_cluster_conversation WHERE conv_id = :c',
            ['c' => $this->convs[$key]],
        );

        return \is_string($id) ? $id : null;
    }

    public function testCaseVariantEthWalletsCluster(): void
    {
        $a = $this->clusterId('f1');
        $b = $this->clusterId('f2');

        self::assertNotNull($a, 'checksum-case ETH wallet conversation should be clustered');
        self::assertSame($a, $b, 'checksum-case and lowercase ETH wallet must land in the same cluster');
    }

    public function testDashVariantIbansCluster(): void
    {
        $a = $this->clusterId('f3');
        $b = $this->clusterId('f4');

        self::assertNotNull($a, 'no-separator IBAN conversation should be clustered');
        self::assertSame($a, $b, 'dashed and undashed IBAN must land in the same cluster');
    }

    public function testDistinctIbansDoNotCluster(): void
    {
        // Two IBANs differing by the last digit are different accounts — no merge.
        self::assertNull($this->clusterId('f5'), 'distinct IBAN must stay a singleton');
        self::assertNull($this->clusterId('f6'), 'distinct IBAN must stay a singleton');
    }

    public function testTheTwoFuzzyPairsAreSeparateClusters(): void
    {
        self::assertNotSame(
            $this->clusterId('f1'),
            $this->clusterId('f3'),
            'the ETH pair and the IBAN pair are different actors',
        );
    }
}
