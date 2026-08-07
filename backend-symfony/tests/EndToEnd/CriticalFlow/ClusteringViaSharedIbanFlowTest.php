<?php

declare(strict_types=1);

namespace App\Tests\EndToEnd\CriticalFlow;

/**
 * Flow 3: Threat actor attribution via shared IBAN clustering.
 *
 * Two emails from different senders sharing the same IBAN should be clustered
 * into the same threat-actor cluster. Verifies: IOC extraction, clustering
 * (triggered via service after IOC persist), cluster listing, cluster detail,
 * and STIX export.
 */
final class ClusteringViaSharedIbanFlowTest extends AbstractCriticalFlowTestCase
{
    public function test_shared_iban_clusters_two_conversations(): void
    {
        $client = static::createClient();
        $jwt = $this->getJwt($client);
        $uniqueSuffix = bin2hex(random_bytes(4));

        // Use a unique IBAN per test run to avoid stix_id collisions with
        // clusters from prior runs (STIX UUID v5 is deterministic on value).
        // The check digits are computed so the IBAN passes the mod-97 validation
        // now enforced at extraction (a fabricated one would be rejected).
        $randomDigits = str_pad((string) random_int(10000000, 99999999), 8, '0', STR_PAD_LEFT);
        $sharedIban = self::ibanWithValidCheckDigits('DE', '370400440532' . $randomDigits);

        // Step 1: Ingest email A from sender1 with the shared IBAN
        $resultA = $this->ingestEmail(
            $client,
            $jwt,
            "sender1-{$uniqueSuffix}@evil.test",
            "Invoice A - {$uniqueSuffix}",
            "Please pay to IBAN {$sharedIban} immediately. Reference: INV-A-{$uniqueSuffix}",
        );
        $msgIdA = $resultA['msg_id'];
        $convIdA = $resultA['conv_id'];

        // Step 2: Extract IOCs from email A (persist) — puts IBAN into indicator table
        $client->request(
            'POST',
            "/api/v1/communication/message/{$msgIdA}/extract-iocs",
            [],
            [],
            [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_AUTHORIZATION' => 'Bearer ' . $jwt,
            ],
            json_encode(['method' => 'regex', 'persist' => true]),
        );
        $this->assertResponseStatusCodeSame(200);
        $extractA = json_decode($client->getResponse()->getContent(), true);
        $typesA = array_column($extractA['iocs'], 'type');
        $this->assertContains('iban', $typesA, 'IBAN must be extracted from email A');

        // Step 3: Ingest email B from sender2 with the SAME IBAN
        $resultB = $this->ingestEmail(
            $client,
            $jwt,
            "sender2-{$uniqueSuffix}@evil.test",
            "Invoice B - {$uniqueSuffix}",
            "Wire to IBAN {$sharedIban} for your order. Reference: INV-B-{$uniqueSuffix}",
        );
        $msgIdB = $resultB['msg_id'];
        $convIdB = $resultB['conv_id'];
        $this->assertNotSame($convIdA, $convIdB, 'Emails from different senders should create different conversations');

        // Step 4: Extract IOCs from email B (persist)
        $client->request(
            'POST',
            "/api/v1/communication/message/{$msgIdB}/extract-iocs",
            [],
            [],
            [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_AUTHORIZATION' => 'Bearer ' . $jwt,
            ],
            json_encode(['method' => 'regex', 'persist' => true]),
        );
        $this->assertResponseStatusCodeSame(200);
        $extractB = json_decode($client->getResponse()->getContent(), true);
        $typesB = array_column($extractB['iocs'], 'type');
        $this->assertContains('iban', $typesB, 'IBAN must be extracted from email B');

        // Step 5: Trigger clustering for both conversations.
        // Clustering runs during ingest post-processing, but only on IOCs that
        // existed at ingest time (header IOCs). Body IOCs like IBAN are extracted
        // via the explicit extract-iocs endpoint, so we re-trigger clustering now.
        /** @var \App\Application\Clustering\IocClusteringService $clusteringService */
        $clusteringService = $client->getContainer()->get(\App\Application\Clustering\IocClusteringService::class);
        $clusteringService->clusterConversation($convIdA);
        $clusteringService->clusterConversation($convIdB);

        // Step 6: List clusters and find one that contains both conversations
        $client->request(
            'GET',
            '/api/v1/clusters',
            [],
            [],
            ['HTTP_AUTHORIZATION' => 'Bearer ' . $jwt],
        );
        $this->assertResponseIsSuccessful();
        $clusters = json_decode($client->getResponse()->getContent(), true);
        $this->assertIsArray($clusters);

        // Find the cluster containing convIdA
        $matchingCluster = null;
        foreach ($clusters as $cluster) {
            $clusterId = $cluster['id'] ?? $cluster['cluster_id'] ?? null;
            if ($clusterId === null) {
                continue;
            }

            // Get cluster detail
            $client->request(
                'GET',
                "/api/v1/clusters/{$clusterId}",
                [],
                [],
                ['HTTP_AUTHORIZATION' => 'Bearer ' . $jwt],
            );
            if ($client->getResponse()->getStatusCode() !== 200) {
                continue;
            }
            $detail = json_decode($client->getResponse()->getContent(), true);
            $convIds = array_column($detail['conversations'] ?? [], 'conv_id');
            if (in_array($convIdA, $convIds, true)) {
                $matchingCluster = $detail;
                break;
            }
        }

        $this->assertNotNull($matchingCluster, 'A cluster containing conversation A must exist');
        $clusterConvIds = array_column($matchingCluster['conversations'] ?? [], 'conv_id');
        $this->assertContains($convIdB, $clusterConvIds, 'Cluster must also contain conversation B (shared IBAN)');
        $this->assertGreaterThanOrEqual(2, count($clusterConvIds), 'Cluster must contain at least 2 conversations');

        // Step 7: Verify anchor IOCs include the IBAN
        $anchorIocs = $matchingCluster['anchor_iocs'] ?? [];
        $ibanFound = false;
        foreach ($anchorIocs as $ioc) {
            // ClusterQueryService returns ioc_value, ioc_value_norm, ioc_type from the JOIN
            $val = $ioc['ioc_value_norm'] ?? $ioc['ioc_value'] ?? $ioc['value'] ?? '';
            if (str_contains(str_replace(' ', '', (string) $val), $sharedIban)) {
                $ibanFound = true;
                break;
            }
        }
        $this->assertTrue($ibanFound, 'Cluster anchor IOCs must include the shared IBAN. Got: ' . json_encode(array_map(fn($i) => $i['ioc_value_norm'] ?? $i['ioc_value'] ?? $i['value'] ?? 'N/A', $anchorIocs)));

        // Step 8: Export STIX for the cluster
        $clusterId = $matchingCluster['id'] ?? $matchingCluster['cluster_id'];
        $client->request(
            'GET',
            "/api/v1/clusters/{$clusterId}/export/stix",
            [],
            [],
            ['HTTP_AUTHORIZATION' => 'Bearer ' . $jwt],
        );
        $this->assertResponseIsSuccessful();
        $stixBundle = json_decode($client->getResponse()->getContent(), true);
        $this->assertSame('bundle', $stixBundle['type']);
        $stixTypes = array_column($stixBundle['objects'], 'type');
        $this->assertContains('threat-actor', $stixTypes, 'Cluster STIX export must contain a threat-actor object');
    }

    /**
     * Build an IBAN whose ISO 7064 mod-97 check digits are correct for the given
     * country + BBAN, so it survives the extraction-time checksum validation.
     */
    private static function ibanWithValidCheckDigits(string $country, string $bban): string
    {
        $rearranged = strtoupper($bban . $country . '00');
        $numeric = '';

        for ($i = 0, $n = \strlen($rearranged); $i < $n; $i++) {
            $ch = $rearranged[$i];
            $numeric .= ctype_alpha($ch) ? (string) (\ord($ch) - 55) : $ch;
        }

        $mod = 0;

        for ($i = 0, $n = \strlen($numeric); $i < $n; $i++) {
            $mod = ($mod * 10 + (int) $numeric[$i]) % 97;
        }

        return $country . str_pad((string) (98 - $mod), 2, '0', STR_PAD_LEFT) . $bban;
    }
}
