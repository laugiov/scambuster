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

    // ------------------------------------------------------------------ //
    //  Spec 105 P3 — Cognitive Mirror Note SDO attached to threat-actor
    // ------------------------------------------------------------------ //

    public function testExportIncludesCognitiveMirrorNoteWhenMirrorIsCached(): void
    {
        $container = static::getContainer();
        /** @var \Doctrine\DBAL\Connection $conn */
        $conn = $container->get('doctrine.dbal.default_connection');

        // Pick any conversation with IOCs. Resolve effective persona via
        // the same fallback the handler uses: when c.persona_id is null,
        // 'generic_user' is the substitute. DAMA wraps each test in a
        // transaction that rolls back, so the seed is non-persistent.
        $row = $conn->fetchAssociative(
            'SELECT c.conv_id::text AS conv_id,'
            . ' COALESCE(c.persona_id, (SELECT persona_id FROM persona WHERE persona_code = \'generic_user\')) AS persona_id,'
            . ' COALESCE(cp.persona_code, \'generic_user\') AS persona_code,'
            . ' c.scam_type_id, st.code AS scam_type_code'
            . ' FROM conversation c'
            . ' LEFT JOIN persona cp ON cp.persona_id = c.persona_id'
            . ' JOIN lkp_scam_type st ON c.scam_type_id = st.scam_type_id'
            . ' JOIN message m ON c.conv_id = m.conv_id'
            . ' JOIN observed_ioc oi ON m.msg_id = oi.msg_id'
            . ' GROUP BY c.conv_id, c.persona_id, cp.persona_code, c.scam_type_id, st.code'
            . ' LIMIT 1'
        );

        if ($row === false) {
            $this->markTestSkipped('No conversation with IOCs in fixtures.');
        }

        $convId = (string) $row['conv_id'];

        // Seed the mirror keyed on the EFFECTIVE persona (post-fallback).
        $conn->executeStatement(
            'INSERT INTO persona_scam_mirror'
            . ' (persona_id, scam_type_id, hunted_victim_profile, cognitive_lever, mirror_explanation, generated_at, generated_by_model, prompt_version)'
            . ' VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
            . ' ON CONFLICT (persona_id, scam_type_id) DO NOTHING',
            [
                $row['persona_id'],
                $row['scam_type_id'],
                'Test profile for spec 105 P3',
                'Trust + urgency lever (test)',
                'Spec 105 P3 fixture mirror explanation.',
                '2026-06-15 12:00:00+00',
                'gpt-4o-mini',
                'v1',
            ]
        );

        $this->client->request('GET', '/api/v1/conversations/' . $convId . '/export/stix', [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer fake-jwt',
        ]);

        $this->assertResponseIsSuccessful();
        $data = $this->decodeResponse();
        $objects = \is_array($data['objects'] ?? null) ? $data['objects'] : [];

        $threatActor = null;
        $note = null;

        foreach ($objects as $obj) {
            if (!\is_array($obj)) {
                continue;
            }
            if (($obj['type'] ?? '') === 'threat-actor' && $threatActor === null) {
                $threatActor = $obj;
            }
            if (($obj['type'] ?? '') === 'note') {
                $note = $obj;
            }
        }

        $this->assertNotNull($threatActor, 'Expected a threat-actor in the bundle.');
        $this->assertNotNull($note, 'Spec 105 P3: a Cognitive Mirror Note must be attached when a mirror is cached.');

        $this->assertSame('2.1', $note['spec_version']);
        $this->assertStringStartsWith('note--', $note['id']);
        $this->assertSame([$threatActor['id']], $note['object_refs']);
        $this->assertSame(['scambuster-cognitive-mirror'], $note['labels']);
        $this->assertStringContainsString('Hunted victim profile:', $note['content']);
        $this->assertStringContainsString('Cognitive lever exploited:', $note['content']);
        $this->assertStringContainsString('Mirror analysis:', $note['content']);

        $mirror = $note['x_scambuster_mirror'];
        $this->assertIsArray($mirror);
        $this->assertSame((string) $row['persona_code'], $mirror['persona_code']);
        $this->assertSame(strtoupper((string) $row['scam_type_code']), $mirror['scam_type_code']);
        $this->assertArrayHasKey('hunted_victim_profile', $mirror);
        $this->assertArrayHasKey('cognitive_lever', $mirror);
        $this->assertArrayHasKey('mirror_explanation', $mirror);
        $this->assertArrayHasKey('generated_by_model', $mirror);
        $this->assertArrayHasKey('prompt_version', $mirror);

        // The note id must also land in the report's object_refs so OpenCTI
        // can navigate to it from the bundle's top-level report SDO.
        $report = null;
        foreach ($objects as $obj) {
            if (\is_array($obj) && ($obj['type'] ?? '') === 'report') {
                $report = $obj;
                break;
            }
        }
        $this->assertNotNull($report, 'Bundle must contain a report SDO.');
        $this->assertContains($note['id'], $report['object_refs'] ?? [], 'Note id must appear in report.object_refs.');
    }

    public function testExportSkipsCognitiveMirrorNoteWhenNoMirrorCached(): void
    {
        $container = static::getContainer();
        /** @var \Doctrine\DBAL\Connection $conn */
        $conn = $container->get('doctrine.dbal.default_connection');

        // Find a conv whose (persona, scam_type) pairing has NO cached
        // mirror. Bundle must still export cleanly — just without a Note.
        $convId = $conn->fetchOne(
            'SELECT c.conv_id::text FROM conversation c'
            . ' JOIN message m ON c.conv_id = m.conv_id'
            . ' JOIN observed_ioc oi ON m.msg_id = oi.msg_id'
            . ' WHERE NOT EXISTS ('
            . '   SELECT 1 FROM persona_scam_mirror psm'
            . '   WHERE psm.persona_id = c.persona_id AND psm.scam_type_id = c.scam_type_id'
            . ' )'
            . ' GROUP BY c.conv_id LIMIT 1'
        );

        if (!\is_string($convId) || $convId === '') {
            $this->markTestSkipped('No conversation without a mirror row in fixtures.');
        }

        $this->client->request('GET', '/api/v1/conversations/' . $convId . '/export/stix', [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer fake-jwt',
        ]);

        $this->assertResponseIsSuccessful();
        $data = $this->decodeResponse();
        $types = array_column($data['objects'] ?? [], 'type');

        $this->assertNotContains('note', $types, 'No Note SDO expected when no mirror is cached.');
        $this->assertContains('threat-actor', $types, 'Threat-actor must still be exported even without a mirror.');
    }

    // ------------------------------------------------------------------ //
    //  Spec 105 P6 — JSON schema validation on the live export response
    // ------------------------------------------------------------------ //

    public function testExportedBundleValidatesAgainstStixExtensionSchemas(): void
    {
        $container = static::getContainer();
        /** @var \Doctrine\DBAL\Connection $conn */
        $conn = $container->get('doctrine.dbal.default_connection');

        // Same setup as the cognitive-mirror happy-path test: pick a conv
        // with IOCs, seed a mirror, hit the endpoint. The bundle we get
        // back must validate clean against both custom schemas.
        $row = $conn->fetchAssociative(
            'SELECT c.conv_id::text AS conv_id,'
            . ' COALESCE(c.persona_id, (SELECT persona_id FROM persona WHERE persona_code = \'generic_user\')) AS persona_id,'
            . ' c.scam_type_id'
            . ' FROM conversation c'
            . ' JOIN message m ON c.conv_id = m.conv_id'
            . ' JOIN observed_ioc oi ON m.msg_id = oi.msg_id'
            . ' GROUP BY c.conv_id, c.persona_id, c.scam_type_id LIMIT 1'
        );

        if ($row === false) {
            $this->markTestSkipped('No conversation with IOCs in fixtures.');
        }

        $conn->executeStatement(
            'INSERT INTO persona_scam_mirror'
            . ' (persona_id, scam_type_id, hunted_victim_profile, cognitive_lever, mirror_explanation, generated_at, generated_by_model, prompt_version)'
            . ' VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
            . ' ON CONFLICT (persona_id, scam_type_id) DO NOTHING',
            [
                $row['persona_id'],
                $row['scam_type_id'],
                'Test profile for spec 105 P6',
                'Trust + urgency lever (P6 test)',
                'Spec 105 P6 fixture mirror explanation.',
                '2026-06-15 12:00:00+00',
                'gpt-4o-mini',
                'v1',
            ]
        );

        $this->client->request('GET', '/api/v1/conversations/' . (string) $row['conv_id'] . '/export/stix', [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer fake-jwt',
        ]);

        $this->assertResponseIsSuccessful();
        $bundle = $this->decodeResponse();

        $validator = $container->get(\App\Application\Stix\ExtensionSchemaValidator::class);
        $errors = $validator->validateBundle($bundle);

        $this->assertSame([], $errors, sprintf(
            "Bundle failed schema validation:\n%s\nBundle objects (types): %s",
            implode("\n", $errors),
            json_encode(array_column($bundle['objects'] ?? [], 'type')),
        ));
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
