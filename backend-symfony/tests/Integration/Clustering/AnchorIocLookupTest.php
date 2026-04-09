<?php

declare(strict_types=1);

namespace App\Tests\Integration\Clustering;

use App\Application\Clustering\IocClusteringService;
use App\Tests\Fixtures\ClusteringFixtures;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Integration tests for the anchor IOC lookup query.
 * Written FIRST (TDD red) — IocClusteringService does not exist yet.
 *
 * Uses ClusteringFixtures which creates:
 * - Cluster A: 5 conversations sharing IBAN FR76...
 * - Cluster B: 3 conversations sharing wallet_btc
 * - Cluster C: 3 conversations sharing phone + IBAN (transitive)
 * - 10 singletons with only MEDIUM IOCs (domains/emails)
 * - 2 conversations with no IOCs
 */
class AnchorIocLookupTest extends KernelTestCase
{
    private Connection $conn;
    private IocClusteringService $service;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->conn = self::getContainer()->get(Connection::class);
        $this->service = new IocClusteringService($this->conn, new \Psr\Log\NullLogger());

        // Clean + load fixtures (clean first to avoid stale data from previous tests)
        ClusteringFixtures::cleanup($this->conn);
        ClusteringFixtures::load($this->conn);
    }

    protected function tearDown(): void
    {
        ClusteringFixtures::cleanup($this->conn);
        parent::tearDown();
    }

    // UUID helpers matching ClusteringFixtures
    private function convA(int $i): string { return sprintf('cccccccc-aaaa-4000-8000-%012d', $i); }
    private function convB(int $i): string { return sprintf('cccccccc-bbbb-4000-8000-%012d', $i); }
    private function convC(int $i): string { return sprintf('cccccccc-cccc-4000-8000-%012d', $i); }
    private function convS(int $i): string { return sprintf('cccccccc-5555-4000-8000-%012d', $i); }
    private function convN(int $i): string { return sprintf('cccccccc-0000-4000-8000-%012d', $i); }

    public function testFindsSharedConversationsViaIban(): void
    {
        $shared = $this->service->findSharedConversations($this->convA(1));

        $sharedConvIds = array_column($shared, 'conv_id');
        $this->assertContains($this->convA(2), $sharedConvIds);
        $this->assertContains($this->convA(3), $sharedConvIds);
        $this->assertContains($this->convA(4), $sharedConvIds);
        $this->assertContains($this->convA(5), $sharedConvIds);
        $this->assertCount(4, $sharedConvIds, 'Should find exactly 4 shared conversations');
    }

    public function testFindsSharedConversationsViaWalletBtc(): void
    {
        $shared = $this->service->findSharedConversations($this->convB(1));

        $sharedConvIds = array_column($shared, 'conv_id');
        $this->assertContains($this->convB(2), $sharedConvIds);
        $this->assertContains($this->convB(3), $sharedConvIds);
        $this->assertCount(2, $sharedConvIds);
    }

    public function testIgnoresMediumSeverityTypes(): void
    {
        $shared = $this->service->findSharedConversations($this->convS(1));

        $this->assertEmpty($shared, 'Shared domain (MEDIUM severity) should NOT create a link');
    }

    public function testFindsMultipleSharedIocs(): void
    {
        $shared = $this->service->findSharedConversations($this->convC(2));

        $sharedConvIds = array_column($shared, 'conv_id');
        $this->assertContains($this->convC(1), $sharedConvIds, 'Should find c1 via shared phone');
        $this->assertContains($this->convC(3), $sharedConvIds, 'Should find c3 via shared IBAN');
        $this->assertCount(2, $sharedConvIds);
    }

    public function testExcludesCurrentConversation(): void
    {
        $shared = $this->service->findSharedConversations($this->convA(1));

        $sharedConvIds = array_column($shared, 'conv_id');
        $this->assertNotContains($this->convA(1), $sharedConvIds, 'Should not include self');
    }

    public function testReturnsEmptyForConversationWithNoIocs(): void
    {
        $shared = $this->service->findSharedConversations($this->convN(1));

        $this->assertEmpty($shared);
    }

    public function testReturnsEmptyForSingleton(): void
    {
        $shared = $this->service->findSharedConversations($this->convS(6));

        $this->assertEmpty($shared);
    }
}
