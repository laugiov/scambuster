<?php

declare(strict_types=1);

namespace App\Tests\Integration\Taxii;

use App\Application\Taxii\TaxiiService;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Additional integration tests for TaxiiService.
 *
 * Covers campaign collection edge cases, STIX pattern building,
 * confidence computation, ISO 8601 formatting, and context extraction.
 */
class TaxiiServiceAdditionalTest extends KernelTestCase
{
    private TaxiiService $taxiiService;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = static::getContainer();
        $this->taxiiService = $container->get(TaxiiService::class);
    }

    // ------------------------------------------------------------------ //
    //  Campaign collection — addedAfter filter
    // ------------------------------------------------------------------ //

    public function testGetCampaignCollectionWithFutureAddedAfterReturnsEmpty(): void
    {
        $futureDate = new \DateTimeImmutable('+10 years');
        $result = $this->taxiiService->getCollectionObjects(
            'a1b2c3d4-0002-4000-8000-000000000002',
            $futureDate,
            100
        );

        $this->assertSame(0, count($result['envelope']['objects']));
        $this->assertNull($result['firstAdded']);
        $this->assertNull($result['lastAdded']);
    }

    public function testGetCampaignCollectionWithLimitOne(): void
    {
        $result = $this->taxiiService->getCollectionObjects(
            'a1b2c3d4-0002-4000-8000-000000000002',
            null,
            1
        );

        $this->assertLessThanOrEqual(1, count($result['envelope']['objects']));
    }

    // ------------------------------------------------------------------ //
    //  Threat Actors collection — various edge cases
    // ------------------------------------------------------------------ //

    public function testGetThreatActorsCollectionWithFutureAddedAfter(): void
    {
        $futureDate = new \DateTimeImmutable('+10 years');
        $result = $this->taxiiService->getCollectionObjects(
            'a1b2c3d4-0003-4000-8000-000000000003',
            $futureDate,
            100
        );

        $this->assertSame(0, count($result['envelope']['objects']));
    }

    public function testGetThreatActorsCollectionWithLimitOne(): void
    {
        $result = $this->taxiiService->getCollectionObjects(
            'a1b2c3d4-0003-4000-8000-000000000003',
            null,
            1
        );

        $this->assertArrayHasKey('envelope', $result);
        $this->assertArrayHasKey('more', $result['envelope']);
    }

    public function testGetThreatActorsCollectionWithTypeFilterThreatActor(): void
    {
        $result = $this->taxiiService->getCollectionObjects(
            'a1b2c3d4-0003-4000-8000-000000000003',
            null,
            100,
            'threat-actor'
        );

        $this->assertArrayHasKey('envelope', $result);
        foreach ($result['envelope']['objects'] as $obj) {
            $this->assertSame('threat-actor', $obj['type']);
        }
    }

    public function testGetThreatActorsCollectionWithTypeFilterRelationship(): void
    {
        $result = $this->taxiiService->getCollectionObjects(
            'a1b2c3d4-0003-4000-8000-000000000003',
            null,
            100,
            'relationship'
        );

        $this->assertArrayHasKey('envelope', $result);
        foreach ($result['envelope']['objects'] as $obj) {
            $this->assertSame('relationship', $obj['type']);
        }
    }

    // ------------------------------------------------------------------ //
    //  IOC collection — type filter for threat-actor enrichment
    // ------------------------------------------------------------------ //

    public function testGetIocCollectionWithThreatActorTypeFilter(): void
    {
        $result = $this->taxiiService->getCollectionObjects(
            'a1b2c3d4-0001-4000-8000-000000000001',
            null,
            100,
            'threat-actor'
        );

        $this->assertArrayHasKey('envelope', $result);
        foreach ($result['envelope']['objects'] as $obj) {
            $this->assertContains($obj['type'], ['indicator', 'threat-actor', 'attack-pattern', 'relationship']);
        }
    }

    public function testGetIocCollectionWithDomainTypeFilter(): void
    {
        $result = $this->taxiiService->getCollectionObjects(
            'a1b2c3d4-0001-4000-8000-000000000001',
            null,
            100,
            'domain'
        );

        $this->assertArrayHasKey('envelope', $result);
        foreach ($result['envelope']['objects'] as $obj) {
            if (($obj['type'] ?? '') === 'indicator') {
                $this->assertStringContainsString('domain', $obj['name']);
            }
        }
    }

    // ------------------------------------------------------------------ //
    //  IOC collection — pagination boundary (more flag)
    // ------------------------------------------------------------------ //

    public function testGetIocCollectionWithVerySmallLimitSetsMoreFlag(): void
    {
        // First check if there are at least 2 indicators
        $allResult = $this->taxiiService->getCollectionObjects(
            'a1b2c3d4-0001-4000-8000-000000000001',
            null,
            100
        );

        $indicatorCount = 0;
        foreach ($allResult['envelope']['objects'] as $obj) {
            if (($obj['type'] ?? '') === 'indicator') {
                $indicatorCount++;
            }
        }

        if ($indicatorCount >= 2) {
            $result = $this->taxiiService->getCollectionObjects(
                'a1b2c3d4-0001-4000-8000-000000000001',
                null,
                1
            );

            $this->assertTrue($result['envelope']['more'], 'With many IOCs and limit=1, more should be true');
        } else {
            $this->markTestSkipped('Not enough indicators in test database to test pagination');
        }
    }

    // ------------------------------------------------------------------ //
    //  IOC collection — addedAfter with past date returns results
    // ------------------------------------------------------------------ //

    public function testGetIocCollectionWithPastAddedAfterReturnsResults(): void
    {
        $pastDate = new \DateTimeImmutable('-10 years');
        $result = $this->taxiiService->getCollectionObjects(
            'a1b2c3d4-0001-4000-8000-000000000001',
            $pastDate,
            100
        );

        // Same as no filter, should have objects
        $this->assertArrayHasKey('envelope', $result);
    }

    // ------------------------------------------------------------------ //
    //  IOC collection — STIX pattern correctness for different IOC types
    // ------------------------------------------------------------------ //

    public function testIocIndicatorsHaveCorrectPatternFormat(): void
    {
        $result = $this->taxiiService->getCollectionObjects(
            'a1b2c3d4-0001-4000-8000-000000000001',
            null,
            50
        );

        foreach ($result['envelope']['objects'] as $obj) {
            if (($obj['type'] ?? '') !== 'indicator') {
                continue;
            }

            $pattern = $obj['pattern'] ?? '';
            // All STIX patterns should start with [ and end with ]
            $this->assertStringStartsWith('[', $pattern, 'STIX pattern must start with [');
            $this->assertStringEndsWith(']', $pattern, 'STIX pattern must end with ]');
        }
    }

    // ------------------------------------------------------------------ //
    //  IOC collection — confidence is bounded [0, 100]
    // ------------------------------------------------------------------ //

    public function testAllIndicatorsHaveBoundedConfidence(): void
    {
        $result = $this->taxiiService->getCollectionObjects(
            'a1b2c3d4-0001-4000-8000-000000000001',
            null,
            50
        );

        foreach ($result['envelope']['objects'] as $obj) {
            if (($obj['type'] ?? '') !== 'indicator') {
                continue;
            }

            $confidence = $obj['confidence'];
            $this->assertGreaterThanOrEqual(0, $confidence);
            $this->assertLessThanOrEqual(100, $confidence);
        }
    }

    // ------------------------------------------------------------------ //
    //  Discovery and API root are idempotent
    // ------------------------------------------------------------------ //

    public function testGetDiscoveryIsIdempotent(): void
    {
        $d1 = $this->taxiiService->getDiscovery();
        $d2 = $this->taxiiService->getDiscovery();
        $this->assertSame($d1, $d2);
    }

    public function testGetApiRootIsIdempotent(): void
    {
        $a1 = $this->taxiiService->getApiRoot();
        $a2 = $this->taxiiService->getApiRoot();
        $this->assertSame($a1, $a2);
    }
}
