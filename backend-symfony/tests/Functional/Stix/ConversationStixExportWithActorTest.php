<?php

declare(strict_types=1);

namespace Tests\Functional\Stix;

use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Integration test: conversation STIX export with threat-actor enrichment.
 */
final class ConversationStixExportWithActorTest extends WebTestCase
{
    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = static::createClient();
    }

    private function getConvIdWithIocs(): string
    {
        $container = static::getContainer();
        /** @var \Doctrine\DBAL\Connection $conn */
        $conn = $container->get('doctrine.dbal.default_connection');

        $convId = $conn->fetchOne(
            'SELECT c.conv_id FROM conversation c'
            . ' JOIN message m ON c.conv_id = m.conv_id'
            . ' JOIN observed_ioc oi ON m.msg_id = oi.msg_id'
            . ' GROUP BY c.conv_id HAVING COUNT(oi.obs_id) > 0 LIMIT 1',
        );

        if (!\is_string($convId) || $convId === '') {
            $this->markTestSkipped('No conversation with IOCs in test database');
        }

        return $convId;
    }

    private function getConvIdWithoutIocs(): string
    {
        $container = static::getContainer();
        /** @var \Doctrine\DBAL\Connection $conn */
        $conn = $container->get('doctrine.dbal.default_connection');

        $convId = $conn->fetchOne(
            'SELECT c.conv_id FROM conversation c'
            . ' WHERE NOT EXISTS ('
            . '   SELECT 1 FROM message m JOIN observed_ioc oi ON m.msg_id = oi.msg_id WHERE m.conv_id = c.conv_id'
            . ' ) LIMIT 1',
        );

        if (!\is_string($convId) || $convId === '') {
            $this->markTestSkipped('No conversation without IOCs in test database');
        }

        return $convId;
    }

    private function getConvIdWithMitre(): string
    {
        $container = static::getContainer();
        /** @var \Doctrine\DBAL\Connection $conn */
        $conn = $container->get('doctrine.dbal.default_connection');

        $convId = $conn->fetchOne(
            'SELECT c.conv_id FROM conversation c'
            . ' JOIN lkp_scam_type st ON c.scam_type_id = st.scam_type_id'
            . ' JOIN message m ON c.conv_id = m.conv_id'
            . ' JOIN observed_ioc oi ON m.msg_id = oi.msg_id'
            . ' WHERE st.attck_technique IS NOT NULL'
            . " AND st.attck_technique != ''"
            . ' GROUP BY c.conv_id HAVING COUNT(oi.obs_id) > 0 LIMIT 1',
        );

        if (!\is_string($convId) || $convId === '') {
            $this->markTestSkipped('No conversation with MITRE technique and IOCs in test database');
        }

        return $convId;
    }

    public function testExportIncludesThreatActor(): void
    {
        $convId = $this->getConvIdWithIocs();

        $this->client->request('GET', '/api/v1/conversations/' . $convId . '/export/stix', [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer fake-jwt',
        ]);

        $this->assertResponseIsSuccessful();
        $data = $this->decodeResponse();

        $types = array_column($data['objects'], 'type');
        $this->assertContains('threat-actor', $types);
    }

    public function testExportIncludesAttackPatternWhenMitreAvailable(): void
    {
        $convId = $this->getConvIdWithMitre();

        $this->client->request('GET', '/api/v1/conversations/' . $convId . '/export/stix', [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer fake-jwt',
        ]);

        $data = $this->decodeResponse();
        $types = array_column($data['objects'], 'type');
        $this->assertContains('attack-pattern', $types);
    }

    public function testExportIncludesIndicatesRelationships(): void
    {
        $convId = $this->getConvIdWithIocs();

        $this->client->request('GET', '/api/v1/conversations/' . $convId . '/export/stix', [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer fake-jwt',
        ]);

        $data = $this->decodeResponse();
        $indicatesRels = array_values(array_filter(
            $data['objects'],
            fn (array $o) => ($o['type'] ?? '') === 'relationship' && ($o['relationship_type'] ?? '') === 'indicates',
        ));

        $this->assertNotEmpty($indicatesRels, 'Expected at least one indicates relationship');

        $threatActors = array_values(array_filter($data['objects'], fn (array $o) => ($o['type'] ?? '') === 'threat-actor'));
        $threatActorId = $threatActors[0]['id'] ?? '';

        foreach ($indicatesRels as $rel) {
            $this->assertSame($threatActorId, $rel['target_ref']);
            $this->assertStringStartsWith('indicator--', $rel['source_ref']);
        }
    }

    public function testExportIncludesUsesRelationship(): void
    {
        $convId = $this->getConvIdWithMitre();

        $this->client->request('GET', '/api/v1/conversations/' . $convId . '/export/stix', [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer fake-jwt',
        ]);

        $data = $this->decodeResponse();
        $usesRels = array_filter($data['objects'], fn (array $o) => ($o['type'] ?? '') === 'relationship' && ($o['relationship_type'] ?? '') === 'uses');

        $this->assertNotEmpty($usesRels, 'Expected uses relationship (threat-actor → attack-pattern)');
    }

    public function testExportWithoutThreatActorParam(): void
    {
        $convId = $this->getConvIdWithIocs();

        $this->client->request('GET', '/api/v1/conversations/' . $convId . '/export/stix?include_threat_actor=false', [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer fake-jwt',
        ]);

        $this->assertResponseIsSuccessful();
        $data = $this->decodeResponse();

        $types = array_column($data['objects'], 'type');
        $this->assertNotContains('threat-actor', $types);
        $this->assertContains('indicator', $types);
    }

    public function testExportWithNoIocsReturnsNoThreatActor(): void
    {
        $convId = $this->getConvIdWithoutIocs();

        $this->client->request('GET', '/api/v1/conversations/' . $convId . '/export/stix', [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer fake-jwt',
        ]);

        $this->assertResponseIsSuccessful();
        $data = $this->decodeResponse();

        $types = array_column($data['objects'], 'type');
        $this->assertNotContains('threat-actor', $types);
    }

    public function testThreatActorHasCorrectFields(): void
    {
        $convId = $this->getConvIdWithIocs();

        $this->client->request('GET', '/api/v1/conversations/' . $convId . '/export/stix', [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer fake-jwt',
        ]);

        $data = $this->decodeResponse();
        $actors = array_values(array_filter($data['objects'], fn (array $o) => ($o['type'] ?? '') === 'threat-actor'));

        $this->assertCount(1, $actors);
        $actor = $actors[0];

        $this->assertSame('2.1', $actor['spec_version']);
        $this->assertSame(['criminal'], $actor['threat_actor_types']);
        $this->assertSame('personal-gain', $actor['primary_motivation']);
        $this->assertArrayHasKey('sophistication', $actor);
        $this->assertArrayHasKey('goals', $actor);
        $this->assertArrayHasKey('extensions', $actor);
        $this->assertArrayHasKey('x_scambuster_actor', $actor['extensions']);
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeResponse(): array
    {
        $content = (string) $this->client->getResponse()->getContent();
        $data = json_decode($content, true);
        $this->assertIsArray($data);
        $this->assertSame('bundle', $data['type'] ?? null);

        return $data;
    }
}
