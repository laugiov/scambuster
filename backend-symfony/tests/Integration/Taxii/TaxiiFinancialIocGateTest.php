<?php

declare(strict_types=1);

namespace App\Tests\Integration\Taxii;

use App\Application\Taxii\TaxiiService;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Financial IOCs (IBAN, wallets, …) frequently belong to money mules who are
 * themselves scam victims. The shared TAXII feed must therefore HOLD financial
 * indicators until an analyst confirms them, and never ship anything an analyst
 * marked false_positive — whatever its type.
 */
final class TaxiiFinancialIocGateTest extends KernelTestCase
{
    private const IBAN_HELD = 'eeeeeee1-0000-4000-8000-000000000001';
    private const IBAN_CONFIRMED = 'eeeeeee1-0000-4000-8000-000000000002';
    private const DOMAIN_FALSE_POSITIVE = 'eeeeeee1-0000-4000-8000-000000000003';
    private const DOMAIN_CONTROL = 'eeeeeee1-0000-4000-8000-000000000004';
    private const IBAN_HELD_MIXED_CASE = 'eeeeeee1-0000-4000-8000-000000000005';

    private const IOC_COLLECTION = 'a1b2c3d4-0001-4000-8000-000000000001';

    private const VALUES = [
        self::IBAN_HELD => ['iban', 'AT611904300234573201'],
        self::IBAN_CONFIRMED => ['iban', 'DE89370400440532013000'],
        self::DOMAIN_FALSE_POSITIVE => ['domain', 'fp-gate-test.example'],
        self::DOMAIN_CONTROL => ['domain', 'control-gate-test.example'],
        // Ingest stores the type verbatim — the hold must not be bypassable
        // by case or padding.
        self::IBAN_HELD_MIXED_CASE => [' IBAN ', 'CY17002001280000001200527600'],
    ];

    private TaxiiService $taxiiService;
    private Connection $conn;
    private \DateTimeImmutable $windowStart;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = static::getContainer();
        $this->taxiiService = $container->get(TaxiiService::class);
        $this->conn = $container->get(Connection::class);

        // Only rows inserted by this test are newer than this instant, so the
        // addedAfter filter isolates them from the shared fixtures.
        $this->windowStart = new \DateTimeImmutable('-1 second');

        foreach (self::VALUES as $id => [$type, $value]) {
            $this->conn->executeStatement('DELETE FROM ioc_analyst_feedback WHERE indicator_id = :id', ['id' => $id]);
            $this->conn->executeStatement('DELETE FROM indicator WHERE indicator_id = :id', ['id' => $id]);
            $this->conn->executeStatement(
                "INSERT INTO indicator (indicator_id, type, value, value_norm, first_seen, last_seen, occurrences, tlp, created_at, updated_at)
                 VALUES (:id, :type, :value, :value, NOW(), NOW(), 1, 'AMBER', NOW(), NOW())",
                ['id' => $id, 'type' => $type, 'value' => $value],
            );
        }

        $this->recordVerdict(self::IBAN_CONFIRMED, 'confirmed');
        $this->recordVerdict(self::DOMAIN_FALSE_POSITIVE, 'false_positive');
    }

    private function recordVerdict(string $indicatorId, string $verdict): void
    {
        $this->conn->executeStatement(
            'INSERT INTO ioc_analyst_feedback (indicator_id, verdict, note, analyst_id, created_at)
             VALUES (:id, :verdict, :note, :analyst, NOW())
             ON CONFLICT (indicator_id) DO UPDATE SET verdict = EXCLUDED.verdict',
            ['id' => $indicatorId, 'verdict' => $verdict, 'note' => 'gate test', 'analyst' => 'gate-test'],
        );
    }

    /**
     * @return list<string> JSON-encoded STIX objects from the IOC collection window
     */
    private function fetchWindowObjects(): array
    {
        $result = $this->taxiiService->getCollectionObjects(self::IOC_COLLECTION, $this->windowStart, 100);

        return array_map(
            static fn (array $obj): string => (string) json_encode($obj),
            $result['envelope']['objects'],
        );
    }

    private static function anyContains(array $encodedObjects, string $needle): bool
    {
        foreach ($encodedObjects as $encoded) {
            if (str_contains($encoded, $needle)) {
                return true;
            }
        }

        return false;
    }

    public function testUnreviewedFinancialIocIsHeldFromTheFeed(): void
    {
        $objects = $this->fetchWindowObjects();

        self::assertTrue(
            self::anyContains($objects, self::VALUES[self::DOMAIN_CONTROL][1]),
            'control: an unreviewed non-financial IOC must be in the feed (window/query sanity)',
        );
        self::assertFalse(
            self::anyContains($objects, self::VALUES[self::IBAN_HELD][1]),
            'an IBAN without an analyst verdict must be HELD from the shared feed (possible mule/victim account)',
        );
        self::assertFalse(
            self::anyContains($objects, self::VALUES[self::IBAN_HELD_MIXED_CASE][1]),
            'a mixed-case/padded financial type must not bypass the export hold',
        );
    }

    public function testConfirmedFinancialIocIsReleasedToTheFeed(): void
    {
        $objects = $this->fetchWindowObjects();

        self::assertTrue(
            self::anyContains($objects, self::VALUES[self::IBAN_CONFIRMED][1]),
            'an analyst-confirmed IBAN must be exported',
        );
    }

    public function testFalsePositiveIocNeverExportsWhateverItsType(): void
    {
        $objects = $this->fetchWindowObjects();

        self::assertFalse(
            self::anyContains($objects, self::VALUES[self::DOMAIN_FALSE_POSITIVE][1]),
            'an analyst-declared false positive must never appear in the feed',
        );
    }
}
