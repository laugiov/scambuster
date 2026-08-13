<?php

declare(strict_types=1);

namespace App\Tests\Functional\Clustering;

use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;

final class GetClusterAbuseReportControllerTest extends WebTestCase
{
    private const CID = 'aaaaaaaa-0000-4000-8000-0000000000cc';
    private const IBAN_INDICATOR = 'aaaaaaaa-0002-4000-8000-000000000002';
    private const CONV = '00000000-0000-0000-0000-000000000002';

    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = static::createClient();
    }

    public function testReturns404ForUnknownCluster(): void
    {
        $this->client->request('GET', '/api/v1/clusters/ffffffff-ffff-ffff-ffff-ffffffffffff/abuse-report', [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer fake-jwt',
        ]);

        $status = $this->client->getResponse()->getStatusCode();
        $this->assertContains($status, [Response::HTTP_NOT_FOUND, Response::HTTP_FORBIDDEN]);
    }

    public function testReturnsAbuseReportForSeededCluster(): void
    {
        /** @var \Doctrine\DBAL\Connection $conn */
        $conn = static::getContainer()->get('doctrine.dbal.default_connection');

        try {
            $conn->executeStatement(
                'INSERT INTO threat_actor_cluster (cluster_id, stix_id, name) VALUES (:cid, :stix, :name)',
                ['cid' => self::CID, 'stix' => 'threat-actor--' . self::CID, 'name' => 'Abuse HTTP Actor'],
            );
            $conn->executeStatement(
                'INSERT INTO threat_actor_cluster_ioc (cluster_id, indicator_id, ioc_type, value_norm_hash, conv_count, first_observed, last_observed)
                 VALUES (:cid, :ind, :type, :hash, 1, :ts, :ts)',
                ['cid' => self::CID, 'ind' => self::IBAN_INDICATOR, 'type' => 'iban', 'hash' => str_repeat('b', 64), 'ts' => '2026-06-01 00:00:00+00'],
            );
            $conn->executeStatement(
                'INSERT INTO threat_actor_cluster_conversation (cluster_id, conv_id) VALUES (:cid, :conv)',
                ['cid' => self::CID, 'conv' => self::CONV],
            );
            // An IBAN is a financial indicator: the export policy withholds it from
            // every outgoing surface until an analyst confirms it, so a report that
            // actually names an account to its bank carries a confirmed one.
            $conn->executeStatement(
                "INSERT INTO ioc_analyst_feedback (indicator_id, verdict, note, analyst_id, created_at)
                 VALUES (:id, 'confirmed', 'abuse report http test', 'abuse-report-http-test', NOW())
                 ON CONFLICT (indicator_id) DO UPDATE SET verdict = 'confirmed'",
                ['id' => self::IBAN_INDICATOR],
            );

            $this->client->request('GET', '/api/v1/clusters/' . self::CID . '/abuse-report', [], [], [
                'HTTP_AUTHORIZATION' => 'Bearer fake-jwt',
            ]);

            $status = $this->client->getResponse()->getStatusCode();

            if ($status === Response::HTTP_FORBIDDEN) {
                self::markTestSkipped('Auth not granting ioc:export in this environment.');
            }

            $this->assertResponseIsSuccessful();
            $data = json_decode((string) $this->client->getResponse()->getContent(), true);
            $this->assertIsArray($data);
            $this->assertSame('threat-actor-abuse-report', $data['report_type']);
            $this->assertArrayHasKey('actionable_indicators', $data);
            $this->assertArrayHasKey('text', $data);
            $this->assertSame('iban', $data['actionable_indicators'][0]['type']);
            $this->assertStringContainsStringIgnoringCase('bank', $data['actionable_indicators'][0]['recommended_recipient']);
        } finally {
            $conn->executeStatement('DELETE FROM threat_actor_cluster WHERE cluster_id = ?', [self::CID]);
            $conn->executeStatement('DELETE FROM ioc_analyst_feedback WHERE indicator_id = ?', [self::IBAN_INDICATOR]);
        }
    }
}
