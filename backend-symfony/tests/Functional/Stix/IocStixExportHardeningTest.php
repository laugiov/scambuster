<?php

declare(strict_types=1);

namespace Tests\Functional\Stix;

use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Bulk IOC STIX export hardening.
 *
 * Asserts that the bulk IOC export bundle no longer contains the
 * `related-to` indicator-to-indicator relationships produced by
 * IocStixExportHandler::buildRelationships(), and that the rest of the
 * bundle (indicator objects, report wrapper, indicates relationships)
 * is preserved.
 */
final class IocStixExportHardeningTest extends WebTestCase
{
    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = static::createClient();
    }

    /**
     * Picks at least 2 indicator IDs that already share a conversation, so the
     * dropped buildRelationships() would have produced at least 1 co-occurrence
     * pair if it were still active.
     *
     * @return array<int, string>
     */
    private function getCoOccurringIndicatorIds(): array
    {
        $container = static::getContainer();
        /** @var \Doctrine\DBAL\Connection $conn */
        $conn = $container->get('doctrine.dbal.default_connection');

        $convId = $conn->fetchOne(
            'SELECT c.conv_id FROM conversation c'
            . ' JOIN message m ON c.conv_id = m.conv_id'
            . ' JOIN observed_ioc oi ON m.msg_id = oi.msg_id'
            . ' GROUP BY c.conv_id HAVING COUNT(DISTINCT oi.indicator_id) >= 2'
            . ' LIMIT 1',
        );

        if (!\is_string($convId) || $convId === '') {
            $this->markTestSkipped('No conversation with >=2 distinct IOCs in test database');
        }

        $rows = $conn->fetchAllAssociative(
            'SELECT DISTINCT oi.indicator_id FROM observed_ioc oi'
            . ' JOIN message m ON oi.msg_id = m.msg_id'
            . ' WHERE m.conv_id = :convId LIMIT 10',
            ['convId' => $convId],
        );

        $ids = [];

        foreach ($rows as $row) {
            if (\is_string($row['indicator_id'] ?? null)) {
                $ids[] = $row['indicator_id'];
            }
        }

        if (\count($ids) < 2) {
            $this->markTestSkipped('Could not load co-occurring indicators');
        }

        return $ids;
    }

    /**
     * @return array<string, mixed>
     */
    private function exportIndicators(array $indicatorIds): array
    {
        $this->client->request('POST', '/api/v1/iocs/export/stix', [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer fake-jwt',
            'CONTENT_TYPE' => 'application/json',
        ], (string) json_encode(['indicator_ids' => $indicatorIds]));

        $this->assertResponseIsSuccessful();
        $content = (string) $this->client->getResponse()->getContent();
        $data = json_decode($content, true);
        $this->assertIsArray($data);
        $this->assertSame('bundle', $data['type'] ?? null);

        return $data;
    }

    /**
     * P0 acceptance: a regenerated bulk IOC bundle must contain ZERO
     * `related-to` relationships whose source AND target are both indicators.
     */
    public function testBulkExportNoRelatedToIndicatorIndicatorRelationships(): void
    {
        $ids = $this->getCoOccurringIndicatorIds();
        $data = $this->exportIndicators($ids);

        $relatedToMesh = array_filter(
            $data['objects'],
            function (array $o): bool {
                if (($o['type'] ?? '') !== 'relationship') {
                    return false;
                }

                if (($o['relationship_type'] ?? '') !== 'related-to') {
                    return false;
                }

                $source = \is_string($o['source_ref'] ?? null) ? $o['source_ref'] : '';
                $target = \is_string($o['target_ref'] ?? null) ? $o['target_ref'] : '';

                return str_starts_with($source, 'indicator--') && str_starts_with($target, 'indicator--');
            },
        );

        $this->assertCount(
            0,
            $relatedToMesh,
            'Bulk IOC export must not contain any related-to indicator-to-indicator relationships.',
        );
    }

    /**
     * Stronger acceptance: the total count of `related-to` relationships in
     * the bulk export must be exactly zero (no other related-to flavour was
     * generating noise either).
     */
    public function testBulkExportRelationshipCountReducedToZero(): void
    {
        $ids = $this->getCoOccurringIndicatorIds();
        $data = $this->exportIndicators($ids);

        $allRelatedTo = array_filter(
            $data['objects'],
            fn (array $o) => ($o['type'] ?? '') === 'relationship' && ($o['relationship_type'] ?? '') === 'related-to',
        );

        $this->assertCount(0, $allRelatedTo, 'Bulk IOC export must contain zero related-to relationships of any kind.');
    }

    /**
     * Non-regression: indicator objects must still be present in the bundle.
     */
    public function testBulkExportPreservesIndicatorObjects(): void
    {
        $ids = $this->getCoOccurringIndicatorIds();
        $data = $this->exportIndicators($ids);

        $indicators = array_filter($data['objects'], fn (array $o) => ($o['type'] ?? '') === 'indicator');
        $this->assertNotEmpty($indicators, 'Bulk IOC export must still contain indicator objects.');
    }

    /**
     * Non-regression: the report wrapper that ties the bundle together must
     * still be present.
     */
    public function testBulkExportPreservesReportObject(): void
    {
        $ids = $this->getCoOccurringIndicatorIds();
        $data = $this->exportIndicators($ids);

        $reports = array_filter($data['objects'], fn (array $o) => ($o['type'] ?? '') === 'report');
        $this->assertCount(1, $reports, 'Bulk IOC export must still contain exactly one report object.');
    }
}
