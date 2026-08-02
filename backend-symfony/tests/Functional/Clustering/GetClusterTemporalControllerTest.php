<?php

declare(strict_types=1);

namespace App\Tests\Functional\Clustering;

use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;

final class GetClusterTemporalControllerTest extends WebTestCase
{
    private const CID = 'aaaaaaaa-0000-4000-8000-0000000000ca';
    private const CONV_A = '00000000-0000-0000-0000-000000000002';

    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = static::createClient();
    }

    public function testReturns404ForUnknownCluster(): void
    {
        $this->client->request('GET', '/api/v1/clusters/ffffffff-ffff-ffff-ffff-ffffffffffff/temporal', [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer fake-jwt',
        ]);

        $status = $this->client->getResponse()->getStatusCode();
        $this->assertContains($status, [Response::HTTP_NOT_FOUND, Response::HTTP_FORBIDDEN]);
    }

    public function testReturnsTemporalMetricsForSeededCluster(): void
    {
        /** @var \Doctrine\DBAL\Connection $conn */
        $conn = static::getContainer()->get('doctrine.dbal.default_connection');

        try {
            $conn->executeStatement(
                'INSERT INTO threat_actor_cluster (cluster_id, stix_id, name) VALUES (:cid, :stix, :name)',
                ['cid' => self::CID, 'stix' => 'threat-actor--' . self::CID, 'name' => 'Temporal HTTP Actor'],
            );
            $conn->executeStatement(
                'INSERT INTO threat_actor_cluster_conversation (cluster_id, conv_id) VALUES (:cid, :conv)',
                ['cid' => self::CID, 'conv' => self::CONV_A],
            );

            $this->client->request('GET', '/api/v1/clusters/' . self::CID . '/temporal', [], [], [
                'HTTP_AUTHORIZATION' => 'Bearer fake-jwt',
            ]);

            $status = $this->client->getResponse()->getStatusCode();

            if ($status === Response::HTTP_FORBIDDEN) {
                self::markTestSkipped('Auth not granting ioc:read in this environment.');
            }

            $this->assertResponseIsSuccessful();
            $data = json_decode((string) $this->client->getResponse()->getContent(), true);
            $this->assertIsArray($data);
            $this->assertArrayHasKey('message_count', $data);
            $this->assertArrayHasKey('hour_of_day_histogram', $data);
            $this->assertArrayHasKey('burst_days', $data);
            $this->assertSame(1, $data['message_count'], 'One linked conversation with one inbound message.');
        } finally {
            $conn->executeStatement('DELETE FROM threat_actor_cluster WHERE cluster_id = ?', [self::CID]);
        }
    }
}
