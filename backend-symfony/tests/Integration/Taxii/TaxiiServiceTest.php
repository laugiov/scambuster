<?php

declare(strict_types=1);

namespace App\Tests\Integration\Taxii;

use App\Application\Stix\IocStixExportHandler;
use App\Application\Taxii\TaxiiService;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Integration tests for TaxiiService
 *
 * Tests TAXII 2.1 discovery, API root, collection listing,
 * and STIX object retrieval for IOC, Campaign, and Threat Actor collections.
 */
class TaxiiServiceTest extends KernelTestCase
{
    private TaxiiService $taxiiService;

    use \App\Tests\Support\CorroboratesIoc;

    /** Corroborated non-financial indicator guaranteed to be in the shared feed. */
    private const FEED_INDICATOR = 'a1b2c3d4-feed-4000-8000-000000000001';

    protected function setUp(): void
    {
        self::bootKernel();
        $container = static::getContainer();
        $this->taxiiService = $container->get(TaxiiService::class);

        // Non-financial IOCs export only once corroborated (IocExportPolicy). The
        // fixture IOCs are single-sighting, so seed one corroborated domain the
        // "read the feed" tests can rely on being present.
        $conn = $container->get(\Doctrine\DBAL\Connection::class);
        $conn->executeStatement("DELETE FROM indicator WHERE indicator_id = :id", ['id' => self::FEED_INDICATOR]);
        $conn->executeStatement(
            "INSERT INTO indicator (indicator_id, type, value, value_norm, first_seen, last_seen, occurrences, tlp, created_at, updated_at)
             VALUES (:id, 'domain', 'feed-corroborated.example', 'feed-corroborated.example', NOW(), NOW(), 2, 'AMBER', NOW(), NOW())",
            ['id' => self::FEED_INDICATOR],
        );
        $this->corroborateIndicator($conn, self::FEED_INDICATOR);
    }

    // ------------------------------------------------------------------ //
    //  Discovery & API Root
    // ------------------------------------------------------------------ //

    public function testGetDiscoveryReturnsExpectedStructure(): void
    {
        $discovery = $this->taxiiService->getDiscovery();

        $this->assertArrayHasKey('title', $discovery);
        $this->assertArrayHasKey('description', $discovery);
        $this->assertArrayHasKey('contact', $discovery);
        $this->assertArrayHasKey('default', $discovery);
        $this->assertArrayHasKey('api_roots', $discovery);
        $this->assertIsArray($discovery['api_roots']);
        $this->assertNotEmpty($discovery['api_roots']);
        $this->assertStringContainsString('ScamBuster', $discovery['title']);
    }

    public function testGetApiRootReturnsExpectedStructure(): void
    {
        $apiRoot = $this->taxiiService->getApiRoot();

        $this->assertArrayHasKey('title', $apiRoot);
        $this->assertArrayHasKey('description', $apiRoot);
        $this->assertArrayHasKey('versions', $apiRoot);
        $this->assertArrayHasKey('max_content_length', $apiRoot);
        $this->assertIsArray($apiRoot['versions']);
        $this->assertContains('application/taxii+json;version=2.1', $apiRoot['versions']);
    }

    // ------------------------------------------------------------------ //
    //  Collections
    // ------------------------------------------------------------------ //

    public function testGetCollectionsReturnsThreeCollections(): void
    {
        $result = $this->taxiiService->getCollections();

        $this->assertArrayHasKey('collections', $result);
        $this->assertCount(3, $result['collections']);
    }

    public function testGetCollectionsStructure(): void
    {
        $result = $this->taxiiService->getCollections();
        $collections = $result['collections'];

        foreach ($collections as $collection) {
            $this->assertArrayHasKey('id', $collection);
            $this->assertArrayHasKey('title', $collection);
            $this->assertArrayHasKey('description', $collection);
            $this->assertArrayHasKey('can_read', $collection);
            $this->assertArrayHasKey('can_write', $collection);
            $this->assertArrayHasKey('media_types', $collection);
            $this->assertTrue($collection['can_read']);
            $this->assertFalse($collection['can_write']);
            $this->assertContains('application/stix+json;version=2.1', $collection['media_types']);
        }
    }

    public function testGetCollectionsContainsIocCollection(): void
    {
        $result = $this->taxiiService->getCollections();
        $titles = array_column($result['collections'], 'title');

        $this->assertContains('ScamBuster IOCs', $titles);
    }

    public function testGetCollectionsContainsCampaignsCollection(): void
    {
        $result = $this->taxiiService->getCollections();
        $titles = array_column($result['collections'], 'title');

        $this->assertContains('ScamBuster Campaigns', $titles);
    }

    public function testGetCollectionsContainsThreatActorsCollection(): void
    {
        $result = $this->taxiiService->getCollections();
        $titles = array_column($result['collections'], 'title');

        $this->assertContains('ScamBuster Threat Actors', $titles);
    }

    // ------------------------------------------------------------------ //
    //  isValidCollection
    // ------------------------------------------------------------------ //

    public function testIsValidCollectionReturnsTrueForIocCollection(): void
    {
        $this->assertTrue($this->taxiiService->isValidCollection('a1b2c3d4-0001-4000-8000-000000000001'));
    }

    public function testIsValidCollectionReturnsTrueForCampaignCollection(): void
    {
        $this->assertTrue($this->taxiiService->isValidCollection('a1b2c3d4-0002-4000-8000-000000000002'));
    }

    public function testIsValidCollectionReturnsTrueForThreatActorsCollection(): void
    {
        $this->assertTrue($this->taxiiService->isValidCollection('a1b2c3d4-0003-4000-8000-000000000003'));
    }

    public function testIsValidCollectionReturnsFalseForUnknownCollection(): void
    {
        $this->assertFalse($this->taxiiService->isValidCollection('ffffffff-ffff-ffff-ffff-ffffffffffff'));
    }

    // ------------------------------------------------------------------ //
    //  getCollectionObjects — IOC collection
    // ------------------------------------------------------------------ //

    public function testGetIocCollectionObjectsReturnsEnvelope(): void
    {
        $result = $this->taxiiService->getCollectionObjects(
            'a1b2c3d4-0001-4000-8000-000000000001'
        );

        $this->assertArrayHasKey('envelope', $result);
        $this->assertArrayHasKey('firstAdded', $result);
        $this->assertArrayHasKey('lastAdded', $result);
        $this->assertArrayHasKey('objects', $result['envelope']);
        $this->assertArrayHasKey('more', $result['envelope']);
        $this->assertIsBool($result['envelope']['more']);
    }

    public function testGetIocCollectionObjectsIndicatorsHaveStixStructure(): void
    {
        $result = $this->taxiiService->getCollectionObjects(
            'a1b2c3d4-0001-4000-8000-000000000001',
            null,
            10
        );

        $objects = $result['envelope']['objects'];

        foreach ($objects as $obj) {
            if (($obj['type'] ?? '') === 'indicator') {
                $this->assertSame('2.1', $obj['spec_version']);
                $this->assertStringStartsWith('indicator--', $obj['id']);
                $this->assertArrayHasKey('pattern', $obj);
                $this->assertSame('stix', $obj['pattern_type']);
                $this->assertArrayHasKey('confidence', $obj);
                $this->assertGreaterThanOrEqual(0, $obj['confidence']);
                $this->assertLessThanOrEqual(100, $obj['confidence']);

                break; // Only need to check first indicator
            }
        }
    }

    public function testGetIocCollectionObjectsWithLimitOfOne(): void
    {
        $result = $this->taxiiService->getCollectionObjects(
            'a1b2c3d4-0001-4000-8000-000000000001',
            null,
            1
        );

        $indicatorCount = 0;

        foreach ($result['envelope']['objects'] as $obj) {
            if (($obj['type'] ?? '') === 'indicator') {
                $indicatorCount++;
            }
        }

        // Limit 1 means at most 1 indicator row; threat actors may also be added
        $this->assertLessThanOrEqual(1, $indicatorCount);
    }

    public function testGetIocCollectionObjectsWithFutureAddedAfterReturnsEmpty(): void
    {
        $futureDate = new \DateTimeImmutable('+10 years');
        $result = $this->taxiiService->getCollectionObjects(
            'a1b2c3d4-0001-4000-8000-000000000001',
            $futureDate,
            100
        );

        $indicatorCount = 0;

        foreach ($result['envelope']['objects'] as $obj) {
            if (($obj['type'] ?? '') === 'indicator') {
                $indicatorCount++;
            }
        }

        $this->assertSame(0, $indicatorCount);
    }

    public function testGetIocCollectionObjectsWithTypeFilter(): void
    {
        $result = $this->taxiiService->getCollectionObjects(
            'a1b2c3d4-0001-4000-8000-000000000001',
            null,
            100,
            'email'
        );

        $this->assertArrayHasKey('envelope', $result);

        // Each indicator should be of the filtered type
        foreach ($result['envelope']['objects'] as $obj) {
            if (($obj['type'] ?? '') === 'indicator') {
                $this->assertStringContainsString('email', $obj['name']);
            }
        }
    }

    // ------------------------------------------------------------------ //
    //  getCollectionObjects — Campaign collection
    // ------------------------------------------------------------------ //

    public function testGetCampaignCollectionObjectsReturnsEnvelope(): void
    {
        $result = $this->taxiiService->getCollectionObjects(
            'a1b2c3d4-0002-4000-8000-000000000002'
        );

        $this->assertArrayHasKey('envelope', $result);
        $this->assertArrayHasKey('objects', $result['envelope']);
        $this->assertArrayHasKey('more', $result['envelope']);
    }

    public function testGetCampaignCollectionCampaignsHaveStixStructure(): void
    {
        // Own the precondition: sibling tests DELETE the shared campaign table
        // in their setUp, so seed a promoted, non-RED campaign here (DAMA rolls
        // it back) instead of trusting the fixture campaign to survive. Without
        // this the collection can be empty, the loop no-ops and PHPUnit marks
        // the test risky ("did not perform any assertions").
        /** @var Connection $conn */
        $conn = static::getContainer()->get(Connection::class);
        $seededId = 'caf0bbbb-0000-4000-8000-000000000001';
        $conn->executeStatement("DELETE FROM campaign WHERE created_by = 'stix-struct-test'");
        $conn->executeStatement(
            "INSERT INTO campaign (campaign_id, first_seen, status, tlp, severity, dsl_hash, created_by, created_at, updated_at)
             VALUES (:id, NOW(), 'promoted', 'TLP:AMBER', 3, :hash, 'stix-struct-test', NOW(), NOW())",
            ['id' => $seededId, 'hash' => str_repeat('d', 64)],
        );

        try {
            // limit 1000 so the seeded campaign is always within the window,
            // regardless of how many other promoted campaigns exist.
            $result = $this->taxiiService->getCollectionObjects(
                'a1b2c3d4-0002-4000-8000-000000000002',
                null,
                1000
            );
            $objects = $result['envelope']['objects'];

            $this->assertNotEmpty($objects, 'Campaign collection must expose the seeded promoted campaign.');
            foreach ($objects as $obj) {
                $this->assertSame('campaign', $obj['type']);
                $this->assertSame('2.1', $obj['spec_version']);
                $this->assertStringStartsWith('campaign--', $obj['id']);
                $this->assertArrayHasKey('name', $obj);
                $this->assertArrayHasKey('first_seen', $obj);
                $this->assertContains('scam', $obj['labels']);
            }
            $this->assertContains('campaign--' . $seededId, array_column($objects, 'id'));
        } finally {
            $conn->executeStatement("DELETE FROM campaign WHERE created_by = 'stix-struct-test'");
        }
    }

    // ------------------------------------------------------------------ //
    //  getCollectionObjects — Threat Actors collection
    // ------------------------------------------------------------------ //

    public function testGetThreatActorsCollectionReturnsEnvelope(): void
    {
        $result = $this->taxiiService->getCollectionObjects(
            'a1b2c3d4-0003-4000-8000-000000000003'
        );

        $this->assertArrayHasKey('envelope', $result);
        $this->assertArrayHasKey('objects', $result['envelope']);
        $this->assertArrayHasKey('more', $result['envelope']);
    }

    // ------------------------------------------------------------------ //
    //  Pagination
    // ------------------------------------------------------------------ //

    public function testLimitIsClampedToMaximum(): void
    {
        // Very large limit should be clamped to 1000 internally
        $result = $this->taxiiService->getCollectionObjects(
            'a1b2c3d4-0001-4000-8000-000000000001',
            null,
            9999
        );

        // Should not throw; envelope returned
        $this->assertArrayHasKey('envelope', $result);
    }

    public function testLimitIsClampedToMinimum(): void
    {
        // Very small / negative limit should be clamped to 1
        $result = $this->taxiiService->getCollectionObjects(
            'a1b2c3d4-0001-4000-8000-000000000001',
            null,
            -5
        );

        $this->assertArrayHasKey('envelope', $result);
    }

    // ================================================================== //
    //  Merged from TaxiiServiceAdditionalTest
    // ================================================================== //

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

    // ------------------------------------------------------------------ //
    //  TAXII vs HTTP equivalence on x_scambuster_context
    // ------------------------------------------------------------------ //

    /**
     * Regression guard: TAXII and HTTP must produce the same
     * x_scambuster_context block for the same indicator. Both paths feed the
     * row through IocContextStixExtensionBuilder today; this test pins that
     * contract so a future SQL refactor cannot silently drift the two
     * extractor shapes apart.
     *
     * Inserts a deterministic ioc_context row tied to an existing observed_ioc
     * so the assertion has all 9 context fields populated. DAMA wraps this
     * test in a transaction that rolls back, so the insert is non-persistent.
     */
    public function testTaxiiAndHttpExportProduceIdenticalContextExtensionForSameIndicator(): void
    {
        $container = static::getContainer();
        $em = $container->get(EntityManagerInterface::class);
        \assert($em instanceof EntityManagerInterface);
        $conn = $em->getConnection();
        \assert($conn instanceof Connection);

        // Pick an indicator that has at least one observed_ioc and no
        // existing ioc_context (so we can deterministically insert one).
        $row = $conn->executeQuery(
            'SELECT i.indicator_id::text AS indicator_id, oi.obs_id::text AS obs_id
             FROM indicator i
             INNER JOIN observed_ioc oi ON oi.indicator_id = i.indicator_id
             LEFT JOIN ioc_context ic ON ic.obs_id = oi.obs_id
             WHERE ic.obs_id IS NULL
               AND i.value_norm <> \'feed-corroborated.example\'
               AND (SELECT COUNT(*) FROM observed_ioc o2 WHERE o2.indicator_id = i.indicator_id) = 1
             ORDER BY i.indicator_id ASC
             LIMIT 1'
        )->fetchAssociative();

        if ($row === false) {
            self::markTestSkipped('No indicator/observed_ioc pair without ioc_context in fixtures.');
        }

        $indicatorId = (string) $row['indicator_id'];
        $obsId = (string) $row['obs_id'];

        // Non-financial IOCs export only once corroborated or confirmed
        // (IocExportPolicy). This test is about context-extension parity, not the
        // corroboration gate, so release the picked indicator with a confirmed
        // verdict (avoids seeding context-less observations that would perturb the
        // x_scambuster_context assertion below).
        $conn->executeStatement(
            "INSERT INTO ioc_analyst_feedback (indicator_id, verdict, note, analyst_id, created_at)
             VALUES (:id, 'confirmed', NULL, 'ctx-parity-test', NOW())
             ON CONFLICT (indicator_id) DO UPDATE SET verdict = 'confirmed'",
            ['id' => $indicatorId],
        );

        // Pin the picked indicator to the front of the TAXII (updated_at ASC)
        // window so it is always inside the limit-100 page, regardless of how
        // many rows the `indicator` table has accumulated across runs: it has no
        // mapped Doctrine entity, so doctrine:fixtures:load never purges it. The
        // x_scambuster_context under test is built solely from ioc_context
        // columns, never from indicator.updated_at, so pinning it cannot weaken
        // the HTTP-vs-TAXII parity assertion below.
        $conn->update('indicator', ['updated_at' => '2000-01-01 00:00:00'], ['indicator_id' => $indicatorId]);

        // Insert a fully-populated ioc_context row (status=enriched so all
        // 9 context fields surface in the extension).
        $conn->insert(
            'ioc_context',
            [
                'indicator_id' => $indicatorId,
                'obs_id' => $obsId,
                'enrichment_status' => 'enriched',
                'scam_type_code' => 'ROMANCE',
                'scam_type_attck' => 'T1566.003',
                'scam_type_misp' => 'misp-galaxy:scam-type="romance"',
                'persona_code' => 'lonely_person',
                'persona_label' => 'Lonely retiree, single, online dating user',
                'extraction_method' => 'llm',
                'revelation_turn' => 4,
                'total_turns' => 11,
                'revelation_turn_ratio' => '0.364',
                'engagement_hours' => '25.50',
                'reward_value' => '0.7000',
                'stimulus_msg_id' => '33333333-3333-4333-8333-333333333333',
                'co_revealed_types' => '{iban,phone}',
                'co_revealed_count' => 2,
                'campaign_id' => '44444444-4444-4444-8444-444444444444',
                'semantic_role' => 'PAYMENT_DESTINATION',
                'stimulus_type' => 'TRUST_BUILDING',
                'urgency_score' => '0.875',
                'language_switch' => true,
                'hesitation_detected' => false,
                'context_excerpt' => 'Scammer requested IBAN after building emotional trust',
                'enrichment_confidence' => '0.910',
                'enrichment_model' => 'gpt-4o-mini',
                'computed_at' => '2026-06-15 12:00:00+00',
            ],
            [
                'language_switch' => Types::BOOLEAN,
                'hesitation_detected' => Types::BOOLEAN,
            ]
        );

        // Sanity check — the INSERT must be visible inside the same
        // transaction the handlers will read from.
        $countAfter = $conn->fetchOne('SELECT COUNT(*) FROM ioc_context WHERE obs_id = ?', [$obsId]);
        self::assertSame(1, (int) $countAfter, 'Seeded ioc_context row not visible after insert.');

        // Discriminant: HTTP and TAXII use different STIX indicator ids
        // (HTTP derives UUIDv5 from value, TAXII uses the raw DB UUID), so
        // we match each side by the indicator that carries our seeded
        // x_scambuster_context — easy because only one was inserted.
        $extractFirstContext = static function (array $objects): ?array {
            foreach ($objects as $obj) {
                if (!\is_array($obj) || ($obj['type'] ?? '') !== 'indicator') {
                    continue;
                }
                $ext = $obj['extensions'][\App\Application\Stix\ScambusterStixExtensions::CONTEXT_ID]['x_scambuster_context'] ?? null;

                if (\is_array($ext)) {
                    return $ext;
                }
            }

            return null;
        };

        // --- HTTP path ---
        $httpHandler = $container->get(IocStixExportHandler::class);
        \assert($httpHandler instanceof IocStixExportHandler);
        $httpBundle = $httpHandler->export([$indicatorId]);
        $httpExt = $extractFirstContext($httpBundle['objects'] ?? []);
        self::assertIsArray($httpExt, 'HTTP export must produce x_scambuster_context for the seeded indicator.');

        // --- TAXII path ---
        $taxiiResult = $this->taxiiService->getCollectionObjects(
            'a1b2c3d4-0001-4000-8000-000000000001',
            null,
            100
        );
        $taxiiExt = $extractFirstContext($taxiiResult['envelope']['objects'] ?? []);
        self::assertIsArray($taxiiExt, 'TAXII export must produce x_scambuster_context for the seeded indicator.');

        // Canonical sort then compare — order of keys is not part of the
        // STIX contract, but key/value pairs must be identical.
        ksort($httpExt);
        ksort($taxiiExt);

        self::assertSame(
            $httpExt,
            $taxiiExt,
            'TAXII and HTTP must emit byte-equal x_scambuster_context for the same indicator (regression guard).'
        );

        // Sanity: the 9 context fields actually landed in the output, not
        // just an empty array on both sides giving false equivalence.
        foreach (['misp_taxonomy', 'persona_label', 'stimulus_msg_id', 'reward_value', 'campaign_id', 'co_revealed_count', 'enrichment_model', 'hesitation_detected', 'language_switch'] as $key) {
            self::assertArrayHasKey($key, $taxiiExt, "Expected context field '{$key}' to be present in the equivalence assertion.");
        }
    }

    // ------------------------------------------------------------------ //
    //  TLP: RED-never-public
    // ------------------------------------------------------------------ //
    public function testRedIndicatorsAreExcludedFromTheFeed(): void
    {
        /** @var Connection $conn */
        $conn = static::getContainer()->get(Connection::class);
        $iocCollection = 'a1b2c3d4-0001-4000-8000-000000000001';

        $conn->executeStatement("DELETE FROM indicator WHERE value_norm IN ('red-taxii.example', 'amber-taxii.example')");
        $insert = static function (string $id, string $tlp, string $val) use ($conn): void {
            $conn->executeStatement(
                "INSERT INTO indicator (indicator_id, type, value, value_norm, first_seen, last_seen, occurrences, tlp, created_at, updated_at)
                 VALUES (:id, 'domain', :v, :v, NOW(), NOW(), 1, :tlp, NOW(), NOW())",
                ['id' => $id, 'v' => $val, 'tlp' => $tlp],
            );
        };

        try {
            // added_after just before the inserts isolates our two indicators from
            // the (large) fixture set, which is ordered oldest-first under the limit.
            $before = new \DateTimeImmutable('-2 seconds');
            $insert('a1b2c3d4-ffff-4000-8000-000000000001', 'RED', 'red-taxii.example');
            $insert('a1b2c3d4-ffff-4000-8000-000000000002', 'AMBER', 'amber-taxii.example');
            // The AMBER control must be exportable to prove RED (not corroboration)
            // is what excludes it — corroborate it (non-financial export gate).
            $this->corroborateIndicator($conn, 'a1b2c3d4-ffff-4000-8000-000000000002');

            $result = $this->taxiiService->getCollectionObjects($iocCollection, $before, 1000, 'domain');
            $envelope = \is_array($result['envelope'] ?? null) ? $result['envelope'] : [];
            $objects = \is_array($envelope['objects'] ?? null) ? $envelope['objects'] : [];
            $patterns = implode(' ', array_map(
                static fn ($o): string => \is_array($o) && \is_string($o['pattern'] ?? null) ? $o['pattern'] : '',
                $objects,
            ));

            self::assertStringNotContainsString('red-taxii.example', $patterns, 'TLP:RED indicators must never appear in the TAXII feed');
            self::assertStringContainsString('amber-taxii.example', $patterns);
        } finally {
            $conn->executeStatement("DELETE FROM indicator WHERE value_norm IN ('red-taxii.example', 'amber-taxii.example')");
        }
    }

    // ------------------------------------------------------------------ //
    //  TLP: RED-never-public — Campaign collection
    // ------------------------------------------------------------------ //

    /**
     * Regression for the audit leak: a promoted TLP:RED campaign must never appear
     * in the shared Campaign TAXII collection (previously it had no TLP filter).
     */
    public function testRedCampaignsAreExcludedFromTheCampaignCollection(): void
    {
        /** @var Connection $conn */
        $conn = static::getContainer()->get(Connection::class);
        $campaignCollection = 'a1b2c3d4-0002-4000-8000-000000000002';

        $conn->executeStatement("DELETE FROM campaign WHERE created_by = 'red-tlp-test'");
        $insert = static function (string $id, string $tlp) use ($conn): void {
            $conn->executeStatement(
                "INSERT INTO campaign (campaign_id, first_seen, status, tlp, severity, dsl_hash, created_by, created_at, updated_at)
                 VALUES (:id, NOW(), 'promoted', :tlp, 3, :hash, 'red-tlp-test', NOW(), NOW())",
                ['id' => $id, 'tlp' => $tlp, 'hash' => str_repeat('c', 64)],
            );
        };

        try {
            $before = new \DateTimeImmutable('-2 seconds');
            $insert('caf0aaaa-0000-4000-8000-000000000001', 'TLP:RED');
            $insert('caf0aaaa-0000-4000-8000-000000000002', 'TLP:AMBER');

            $result = $this->taxiiService->getCollectionObjects($campaignCollection, $before, 1000);
            $ids = array_map(
                static fn ($o): string => \is_array($o) && \is_string($o['id'] ?? null) ? $o['id'] : '',
                \is_array($result['envelope']['objects'] ?? null) ? $result['envelope']['objects'] : [],
            );

            self::assertNotContains('campaign--caf0aaaa-0000-4000-8000-000000000001', $ids, 'TLP:RED campaigns must never appear in the shared TAXII feed');
            self::assertContains('campaign--caf0aaaa-0000-4000-8000-000000000002', $ids);
        } finally {
            $conn->executeStatement("DELETE FROM campaign WHERE created_by = 'red-tlp-test'");
        }
    }

    // ------------------------------------------------------------------ //
    //  Analyst verdict overrides the emitted IOC confidence
    // ------------------------------------------------------------------ //

    /**
     * A human confirmed verdict outranks the occurrence-based confidence the TAXII
     * feed emits (pinned high); a false positive no longer ships at all — the
     * export hold (IocExportPolicy) removes it from the feed entirely.
     */
    public function testAnalystVerdictOverridesIocConfidence(): void
    {
        /** @var Connection $conn */
        $conn = static::getContainer()->get(Connection::class);
        $iocCollection = 'a1b2c3d4-0001-4000-8000-000000000001';
        $futureTs = '2099-04-01 12:00:00';

        $confirmed = 'a1b2c3d4-fee0-4000-8000-000000000001';
        $falsePos = 'a1b2c3d4-fee0-4000-8000-000000000002';
        $plain = 'a1b2c3d4-fee0-4000-8000-000000000003';

        // FK ioc_analyst_feedback -> indicator is ON DELETE CASCADE, so deleting the
        // indicator removes any verdict too.
        $conn->executeStatement("DELETE FROM indicator WHERE value_norm LIKE 'verdict-taxii-%.example'");

        try {
            foreach ([$confirmed, $falsePos, $plain] as $n => $id) {
                $conn->executeStatement(
                    "INSERT INTO indicator (indicator_id, type, value, value_norm, first_seen, last_seen, occurrences, tlp, created_at, updated_at)
                     VALUES (:id, 'domain', :v, :v, NOW(), NOW(), 1, 'AMBER', NOW(), :ts)",
                    ['id' => $id, 'v' => sprintf('verdict-taxii-%d.example', $n), 'ts' => $futureTs],
                );
            }
            $conn->executeStatement(
                "INSERT INTO ioc_analyst_feedback (indicator_id, verdict, note, analyst_id, created_at) VALUES (:id, 'confirmed', NULL, 'a', NOW())",
                ['id' => $confirmed],
            );
            $conn->executeStatement(
                "INSERT INTO ioc_analyst_feedback (indicator_id, verdict, note, analyst_id, created_at) VALUES (:id, 'false_positive', NULL, 'a', NOW())",
                ['id' => $falsePos],
            );
            // The unreviewed control must ship on its own merit (corroboration),
            // so the test isolates the *verdict* effect, not the corroboration gate.
            $this->corroborateIndicator($conn, $plain);

            $result = $this->taxiiService->getCollectionObjects($iocCollection, new \DateTimeImmutable('2099-03-31 00:00:00'), 1000);

            $byId = [];

            foreach (\is_array($result['envelope']['objects'] ?? null) ? $result['envelope']['objects'] : [] as $o) {
                if (\is_array($o) && ($o['type'] ?? null) === 'indicator' && \is_string($o['id'] ?? null)) {
                    $byId[$o['id']] = $o;
                }
            }

            self::assertArrayHasKey("indicator--{$confirmed}", $byId);
            self::assertSame(99, $byId["indicator--{$confirmed}"]['confidence'], 'confirmed verdict pins TAXII confidence high');
            self::assertArrayNotHasKey("indicator--{$falsePos}", $byId, 'a false positive is export-held and never ships (IocExportPolicy)');
            self::assertSame(55, $byId["indicator--{$plain}"]['confidence'], 'no verdict leaves the occurrence-based confidence untouched');
        } finally {
            $conn->executeStatement("DELETE FROM indicator WHERE value_norm LIKE 'verdict-taxii-%.example'");
        }
    }

    // ------------------------------------------------------------------ //
    //  Cursor pagination — skip-free across duplicate timestamps
    // ------------------------------------------------------------------ //

    /**
     * The whole reason for the opaque (updated_at, indicator_id) cursor: five
     * indicators sharing one identical updated_at must paginate with NO skip and
     * NO repeat. A naive added_after=lastTimestamp boundary would either drop or
     * duplicate rows that share the boundary second. We walk the pages with the
     * returned `next` cursor and assert the union is exactly the five seeded IDs.
     */
    public function testCursorPaginationIsSkipFreeAcrossDuplicateTimestamps(): void
    {
        /** @var Connection $conn */
        $conn = static::getContainer()->get(Connection::class);
        $iocCollection = 'a1b2c3d4-0001-4000-8000-000000000001';

        // All five share ONE updated_at, far in the future so added_after isolates
        // them from the fixture set. Distinct indicator_ids exercise the tie-break.
        $sharedTs = '2099-03-01 12:00:00';
        $ids = [
            'a1b2c3d4-eeee-4000-8000-0000000000a1',
            'a1b2c3d4-eeee-4000-8000-0000000000a2',
            'a1b2c3d4-eeee-4000-8000-0000000000a3',
            'a1b2c3d4-eeee-4000-8000-0000000000a4',
            'a1b2c3d4-eeee-4000-8000-0000000000a5',
        ];

        $conn->executeStatement("DELETE FROM indicator WHERE value_norm LIKE 'cursor-page-%.example'");

        try {
            foreach ($ids as $n => $id) {
                $val = sprintf('cursor-page-%d.example', $n);
                $conn->executeStatement(
                    "INSERT INTO indicator (indicator_id, type, value, value_norm, first_seen, last_seen, occurrences, tlp, created_at, updated_at)
                     VALUES (:id, 'domain', :v, :v, :ts, :ts, 1, 'AMBER', :ts, :ts)",
                    ['id' => $id, 'v' => $val, 'ts' => $sharedTs],
                );
                // Non-financial: must be corroborated to appear in the feed.
                $this->corroborateIndicator($conn, $id);
            }

            $addedAfter = new \DateTimeImmutable('2099-02-01 00:00:00');
            $collected = [];
            $cursor = null;
            $pages = 0;
            $lastMore = true;

            // limit=2 over 5 rows => pages of 2 + 2 + 1.
            do {
                $result = $this->taxiiService->getCollectionObjects($iocCollection, $addedAfter, 2, 'domain', $cursor);
                $envelope = \is_array($result['envelope'] ?? null) ? $result['envelope'] : [];
                $objects = \is_array($envelope['objects'] ?? null) ? $envelope['objects'] : [];

                foreach ($objects as $obj) {
                    if (\is_array($obj) && ($obj['type'] ?? '') === 'indicator' && \is_string($obj['id'] ?? null)) {
                        $collected[] = str_replace('indicator--', '', $obj['id']);
                    }
                }

                $lastMore = (bool) ($envelope['more'] ?? false);
                $cursor = \is_string($result['next'] ?? null) ? $result['next'] : null;
                ++$pages;

                self::assertLessThanOrEqual(4, $pages, 'Cursor pagination failed to terminate — possible cursor loop.');
            } while ($lastMore && $cursor !== null);

            sort($ids);
            $collectedSorted = $collected;
            sort($collectedSorted);

            self::assertSame($ids, $collectedSorted, 'Every seeded indicator must appear exactly once across the paged cursor walk (no skip, no repeat).');
            self::assertCount(\count($ids), array_unique($collected), 'No indicator may be returned twice across pages.');
            self::assertFalse($lastMore, 'The final page must report more=false.');
            self::assertNull($cursor, 'The final page must not emit a next cursor.');
            self::assertSame(3, $pages, 'Five rows at limit=2 must span exactly three pages.');
        } finally {
            $conn->executeStatement("DELETE FROM indicator WHERE value_norm LIKE 'cursor-page-%.example'");
        }
    }

    /**
     * A garbage / malformed cursor must be ignored (treated as first page), not
     * throw — defensive against clients that fabricate or truncate the token.
     */
    public function testMalformedCursorIsIgnoredNotFatal(): void
    {
        $result = $this->taxiiService->getCollectionObjects(
            'a1b2c3d4-0001-4000-8000-000000000001',
            null,
            5,
            null,
            'not-a-valid-base64-cursor!!!'
        );

        $this->assertArrayHasKey('envelope', $result);
        $this->assertArrayHasKey('objects', $result['envelope']);
    }
}
