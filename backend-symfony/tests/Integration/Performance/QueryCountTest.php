<?php

declare(strict_types=1);

namespace App\Tests\Integration\Performance;

use App\Application\Clustering\ClusterQueryService;
use App\Application\Clustering\IocClusteringService;
use App\Application\Communication\IngestHandler;
use App\Application\Communication\IngestRawRequestDto;
use App\Domain\Communication\Conversation;
use App\Domain\Communication\Message;
use App\Tests\Fixtures\ClusteringFixtures;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Logging\DebugStack;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Query count regression tests for critical Doctrine paths.
 *
 * Ensures eager-loading annotations and query patterns remain bounded
 * (no N+1 regressions). Uses the deprecated DebugStack intentionally
 * in test context -- it is the simplest DBAL 3.x query counter.
 *
 * @group performance
 *
 */
class QueryCountTest extends KernelTestCase
{
    /** @phpstan-ignore-next-line (Initialized in setUp) */
    private EntityManagerInterface $em;
    /** @phpstan-ignore-next-line (Initialized in setUp) */
    private Connection $conn;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = self::getContainer();

        /** @var EntityManagerInterface $em */
        $em = $container->get('doctrine.orm.entity_manager');
        $this->em = $em;

        /** @var Connection $conn */
        $conn = $container->get(Connection::class);
        $this->conn = $conn;
    }

    // ──────────────────────────────────────────────────
    // Helpers
    // ──────────────────────────────────────────────────

    /**
     * Attach a DebugStack SQL logger and return it.
     *
     * @suppress DebugStack deprecation -- intentional use in test context
     */
    private function attachLogger(): DebugStack
    {
        /** @phpstan-ignore new.deprecated */
        $logger = new DebugStack();
        /** @phpstan-ignore method.deprecated */
        $this->conn->getConfiguration()->setSQLLogger($logger);

        return $logger;
    }

    private function detachLogger(): void
    {
        /** @phpstan-ignore method.deprecated */
        $this->conn->getConfiguration()->setSQLLogger(null);
    }

    /**
     * Count queries recorded since the logger was attached, excluding
     * Doctrine internal queries (e.g. SAVEPOINT, RELEASE, BEGIN, COMMIT).
     *
     * @param array<int, array<string, mixed>> $queries
     */
    private function countUserQueries(array $queries): int
    {
        $count = 0;

        foreach ($queries as $q) {
            /** @var string $rawSql */
            $rawSql = $q['sql'] ?? '';
            $sql = strtoupper(trim($rawSql));

            // Skip transaction control statements
            if (
                str_starts_with($sql, 'SAVEPOINT')
                || str_starts_with($sql, 'RELEASE')
                || $sql === 'BEGIN'
                || $sql === 'COMMIT'
                || $sql === 'ROLLBACK'
                || str_starts_with($sql, '"SAVEPOINT"')
                || str_starts_with($sql, '"RELEASE"')
            ) {
                continue;
            }

            ++$count;
        }

        return $count;
    }

    /**
     * Fetch a scalar string value from DB or skip the test.
     */
    private function fetchScalarOrSkip(string $sql, string $skipMessage): string
    {
        /** @var string|int|false $value */
        $value = $this->conn->fetchOne($sql);

        if ($value === false) {
            $this->markTestSkipped($skipMessage);
        }

        /** @var string|int $nonFalseValue */
        $nonFalseValue = $value;

        return (string) $nonFalseValue;
    }

    // ──────────────────────────────────────────────────
    // Test: Single conversation fetch with scam type
    // ──────────────────────────────────────────────────

    public function testFetchSingleConversationWithScamType(): void
    {
        $convId = $this->fetchScalarOrSkip(
            'SELECT conv_id FROM conversation LIMIT 1',
            'No conversations in test database'
        );

        $this->em->clear();

        $logger = $this->attachLogger();

        $conv = $this->em->getRepository(Conversation::class)->find($convId);
        $this->assertNotNull($conv);

        // Access the scam type to trigger any lazy load
        $conv->getScamType()->getCode();

        $queryCount = $this->countUserQueries($logger->queries);
        $this->detachLogger();

        // With EAGER fetch on ScamType, this should be at most 2 queries
        // (1 for conversation, possibly 1 for scam type join / subselect)
        $this->assertLessThanOrEqual(2, $queryCount, sprintf(
            'Fetching single conversation + scam type should be <= 2 queries, got %d',
            $queryCount
        ));
    }

    // ──────────────────────────────────────────────────
    // Test: Single message fetch with direction + channel
    // ──────────────────────────────────────────────────

    public function testFetchSingleMessageWithDirectionAndChannel(): void
    {
        $msgId = $this->fetchScalarOrSkip(
            'SELECT msg_id FROM message LIMIT 1',
            'No messages in test database'
        );

        $this->em->clear();

        $logger = $this->attachLogger();

        $message = $this->em->getRepository(Message::class)->find($msgId);
        $this->assertNotNull($message);

        // Access direction and channel to trigger any lazy loads
        $message->getDirection();
        $message->getChannel();

        $queryCount = $this->countUserQueries($logger->queries);
        $this->detachLogger();

        // With EAGER fetch on Direction + Channel, max 2 queries
        // (1 for message with eager joins, possibly 1 for conversation FK)
        $this->assertLessThanOrEqual(2, $queryCount, sprintf(
            'Fetching single message + direction + channel should be <= 2 queries, got %d',
            $queryCount
        ));
    }

    // ──────────────────────────────────────────────────
    // Test: List conversations (page of 20)
    // ──────────────────────────────────────────────────

    public function testListConversationsPage(): void
    {
        $this->em->clear();

        $logger = $this->attachLogger();

        /** @var list<Conversation> $conversations */
        $conversations = $this->em->getRepository(Conversation::class)
            ->createQueryBuilder('c')
            ->setMaxResults(20)
            ->getQuery()
            ->getResult();

        // Access scam type on each conversation to detect N+1
        foreach ($conversations as $conv) {
            $conv->getScamType()->getCode();
        }

        $queryCount = $this->countUserQueries($logger->queries);
        $this->detachLogger();

        // Max 3 queries: 1 list + eager joins for scam type (+ possibly 1 for count)
        $this->assertLessThanOrEqual(3, $queryCount, sprintf(
            'Listing 20 conversations + scam types should be <= 3 queries, got %d',
            $queryCount
        ));
    }

    // ──────────────────────────────────────────────────
    // Test: Cluster detail query count
    // ──────────────────────────────────────────────────

    public function testClusterDetailQueryCount(): void
    {
        // Set up clustering fixtures
        ClusteringFixtures::cleanup($this->conn);
        ClusteringFixtures::load($this->conn);

        $service = new IocClusteringService($this->conn, new NullLogger());
        $service->clusterConversation(sprintf('cccccccc-aaaa-4000-8000-%012d', 1));

        /** @var string|int|false $clusterId */
        $clusterId = $this->conn->fetchOne(
            "SELECT cluster_id FROM threat_actor_cluster WHERE status != 'merged' LIMIT 1"
        );

        if ($clusterId === false) {
            ClusteringFixtures::cleanup($this->conn);
            $this->markTestSkipped('No clusters created by fixtures');
        }

        $queryService = new ClusterQueryService($this->conn);

        $logger = $this->attachLogger();

        $detail = $queryService->getDetail((string) $clusterId);
        $this->assertNotNull($detail);

        $queryCount = $this->countUserQueries($logger->queries);
        $this->detachLogger();

        ClusteringFixtures::cleanup($this->conn);

        // Cluster detail: cluster row + anchors + indicator-conv map + conversations
        // + sample excerpts + behavioral profile + dominant stimulus count
        // + templated excerpt count + anchor behaviors = bounded set of queries
        $this->assertLessThanOrEqual(10, $queryCount, sprintf(
            'Cluster detail should be <= 10 queries, got %d',
            $queryCount
        ));
    }

    // ──────────────────────────────────────────────────
    // Test: IngestHandler query count bounded (not N+1)
    // ──────────────────────────────────────────────────

    public function testIngestHandlerQueryCountBounded(): void
    {
        $accountId = $this->fetchScalarOrSkip(
            'SELECT account_id FROM mail_account LIMIT 1',
            'No mail accounts in test database'
        );

        /** @var IngestHandler $ingestHandler */
        $ingestHandler = self::getContainer()->get(IngestHandler::class);

        // Build a minimal RFC822 raw email with IOCs embedded
        $uniqueId = bin2hex(random_bytes(8));
        $messageId = '<perf-test-' . $uniqueId . '@evil.example>';
        $rawEmail = "From: scammer-perf@evil.example\r\n"
            . "To: honeypot-perf@test.example\r\n"
            . "Subject: Performance test\r\n"
            . "Message-ID: " . $messageId . "\r\n"
            . "Date: " . date('r') . "\r\n"
            . "MIME-Version: 1.0\r\n"
            . "Content-Type: text/plain; charset=utf-8\r\n"
            . "\r\n"
            . "Hello, please wire \$5000 to IBAN GB82WEST12345698765432\r\n"
            . "and call +33612345678 or visit https://evil-phishing.test/steal\r\n";

        $dto = new IngestRawRequestDto();
        $dto->account_id = $accountId;
        $dto->channel = 'email';
        $dto->ts_received = (new \DateTimeImmutable())->format('c');
        $dto->rspamd = ['score' => 5.0];
        $dto->score_risk = 50;
        $dto->raw_source = base64_encode($rawEmail);
        $dto->message_id = $messageId;

        $this->em->clear();

        $logger = $this->attachLogger();

        try {
            $result = $ingestHandler->ingest($dto);
            $this->assertArrayHasKey('msg_id', $result);
        } catch (\Throwable $e) {
            $this->detachLogger();
            $this->fail('IngestHandler threw: ' . $e->getMessage());
        }

        $queryCount = $this->countUserQueries($logger->queries);
        $this->detachLogger();

        // Ingest involves: account/channel/direction lookups, dedup check,
        // conversation find/create, scam type lookup, message persist,
        // attachment processing, IOC extraction + upsert per IOC found,
        // classification, risk scoring, rate limiting, audit logging.
        // The full pipeline is query-heavy but bounded -- not proportional
        // to the number of IOCs in a multiplicative way.
        // Upper bound: 120 queries for a single message with 3 embedded IOCs.
        $this->assertLessThanOrEqual(120, $queryCount, sprintf(
            'IngestHandler should have bounded query count, got %d (check for N+1)',
            $queryCount
        ));
    }
}
