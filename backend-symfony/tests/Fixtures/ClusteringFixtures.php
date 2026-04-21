<?php

declare(strict_types=1);

namespace App\Tests\Fixtures;

use Doctrine\DBAL\Connection;

/**
 * Creates test data for clustering integration tests.
 *
 * Expected clustering result (with anchor IOCs of severity HIGH only):
 *
 * Cluster A (5 conversations): share IBAN GB82WEST12345698765432
 *   - conv-clust-a1, conv-clust-a2, conv-clust-a3, conv-clust-a4, conv-clust-a5
 *   - IBAN appears in various formats: with spaces, with dashes, clean
 *
 * Cluster B (3 conversations): share wallet_btc 1A1zP1eP5QGefi2DMPTfTL5SLmv7DivfNa
 *   - conv-clust-b1, conv-clust-b2, conv-clust-b3
 *
 * Cluster C — Transitive (3 conversations):
 *   - conv-clust-c1 + conv-clust-c2 share phone +33698765432
 *   - conv-clust-c2 + conv-clust-c3 share IBAN DE89370400440532013000
 *   - Result: all 3 in same cluster via transitivity through conv-clust-c2
 *
 * Singletons (10 conversations): only MEDIUM IOCs (domains, emails, URLs)
 *   - conv-single-01 through conv-single-10
 *   - Some share the same domain (evil.com) — must NOT cluster (MEDIUM severity)
 *
 * No IOCs (2 conversations):
 *   - conv-noioc-01, conv-noioc-02
 *
 * Total: 23 conversations, 3 expected clusters, 12 singletons/no-IOC
 */
final class ClusteringFixtures
{
    /**
     * Load clustering test data into the database.
     *
     * Requires: scam_type, channel, mail_account, persona already in DB (from standard fixtures).
     * Creates: conversations, messages, indicators, observed_iocs.
     */
    public static function load(Connection $conn): void
    {
        $now = (new \DateTimeImmutable())->format('Y-m-d H:i:s');

        // Get existing FK references
        $scamTypeId = $conn->fetchOne('SELECT scam_type_id FROM lkp_scam_type LIMIT 1');
        $channelId = $conn->fetchOne('SELECT channel_id FROM lkp_channel LIMIT 1');
        $accountId = $conn->fetchOne('SELECT account_id FROM mail_account LIMIT 1');
        $personaId = $conn->fetchOne('SELECT persona_id FROM persona WHERE is_active = true LIMIT 1');

        if (!$scamTypeId || !$channelId || !$accountId) {
            throw new \RuntimeException('ClusteringFixtures requires scam_type, channel, and mail_account in DB');
        }

        // ─── Conversations ───
        // Use deterministic UUIDs for test reproducibility
        $convIds = [];

        // Cluster A: 5 conversations sharing IBAN
        for ($i = 1; $i <= 5; $i++) {
            $uuid = sprintf('cccccccc-aaaa-4000-8000-%012d', $i);
            $convIds["a{$i}"] = self::createConversation($conn, $uuid, $scamTypeId, $channelId, $accountId, $personaId, $now, $i);
        }

        // Cluster B: 3 conversations sharing wallet_btc
        for ($i = 1; $i <= 3; $i++) {
            $uuid = sprintf('cccccccc-bbbb-4000-8000-%012d', $i);
            $convIds["b{$i}"] = self::createConversation($conn, $uuid, $scamTypeId, $channelId, $accountId, $personaId, $now, $i + 5);
        }

        // Cluster C: 3 conversations (transitive via phone + IBAN)
        for ($i = 1; $i <= 3; $i++) {
            $uuid = sprintf('cccccccc-cccc-4000-8000-%012d', $i);
            $convIds["c{$i}"] = self::createConversation($conn, $uuid, $scamTypeId, $channelId, $accountId, $personaId, $now, $i + 8);
        }

        // Singletons: 10 conversations with only MEDIUM IOCs
        for ($i = 1; $i <= 10; $i++) {
            $uuid = sprintf('cccccccc-5555-4000-8000-%012d', $i);
            $convIds["s{$i}"] = self::createConversation($conn, $uuid, $scamTypeId, $channelId, $accountId, $personaId, $now, $i + 11);
        }

        // No IOCs: 2 conversations
        for ($i = 1; $i <= 2; $i++) {
            $uuid = sprintf('cccccccc-0000-4000-8000-%012d', $i);
            $convIds["n{$i}"] = self::createConversation($conn, $uuid, $scamTypeId, $channelId, $accountId, $personaId, $now, $i + 21);
        }

        // ─── Messages (1 per conversation) ───
        $msgIds = [];
        $msgIdx = 0;

        foreach ($convIds as $key => $convId) {
            $msgIdx++;
            $msgUuid = sprintf('dddddddd-0000-4000-8000-%012d', $msgIdx);
            $msgIds[$key] = self::createMessage($conn, $msgUuid, $convId, $now);
        }

        // ─── Indicators + ObservedIocs ───

        $obsCounter = 0;
        $nextObs = function () use (&$obsCounter): string {
            $obsCounter++;

            return sprintf('ffffffff-0000-4000-8000-%012d', $obsCounter);
        };

        // Cluster A: shared IBAN in various formats
        // Observations spread across 5 days to validate first_observed/last_observed computation
        $ibanIndicatorId = self::createIndicator($conn, 'eeeeeeee-0001-4000-8000-000000000001', 'iban', 'GB82WEST12345698765432', 'GB82WEST12345698765432', $now);
        $clusterAObsIds = [];
        $dayOffsets = ['a1' => 5, 'a2' => 4, 'a3' => 3, 'a4' => 2, 'a5' => 1];

        foreach (['a1', 'a2', 'a3', 'a4', 'a5'] as $key) {
            $obsId = $nextObs();
            $tsObserved = (new \DateTimeImmutable("-{$dayOffsets[$key]} days"))->format('Y-m-d H:i:s');
            self::createObservedIoc($conn, $obsId, $msgIds[$key], $ibanIndicatorId, 'iban', 'GB82WEST12345698765432', $now, $tsObserved);
            $clusterAObsIds[] = $obsId;
        }

        // Also add some MEDIUM IOCs to Cluster A conversations (should NOT affect clustering)
        $domainIndicatorA = self::createIndicator($conn, 'eeeeeeee-0002-4000-8000-000000000001', 'domain', 'evil-phishing.com', 'evil-phishing[.]com', $now);
        $clusterADomainObsIds = [];
        $obsIdA1Domain = $nextObs();
        self::createObservedIoc($conn, $obsIdA1Domain, $msgIds['a1'], $domainIndicatorA, 'domain', 'evil-phishing.com', $now);
        $clusterADomainObsIds[] = ['obs_id' => $obsIdA1Domain, 'conv_key' => 'a1'];
        $obsIdA2Domain = $nextObs();
        self::createObservedIoc($conn, $obsIdA2Domain, $msgIds['a2'], $domainIndicatorA, 'domain', 'evil-phishing.com', $now);
        $clusterADomainObsIds[] = ['obs_id' => $obsIdA2Domain, 'conv_key' => 'a2'];

        // Cluster B: shared wallet_btc
        $btcIndicatorId = self::createIndicator($conn, 'eeeeeeee-0003-4000-8000-000000000001', 'wallet_btc', '1A1zP1eP5QGefi2DMPTfTL5SLmv7DivfNa', '1A1zP1eP5QGefi2DMPTfTL5SLmv7DivfNa', $now);
        $clusterBObsIds = [];

        foreach (['b1', 'b2', 'b3'] as $key) {
            $obsId = $nextObs();
            self::createObservedIoc($conn, $obsId, $msgIds[$key], $btcIndicatorId, 'wallet_btc', '1A1zP1eP5QGefi2DMPTfTL5SLmv7DivfNa', $now);
            $clusterBObsIds[] = $obsId;
        }

        // Cluster C: transitive — c1+c2 share phone, c2+c3 share IBAN
        $phoneIndicatorId = self::createIndicator($conn, 'eeeeeeee-0004-4000-8000-000000000001', 'phone', '+33698765432', '+33698765432', $now);
        $clusterCPhoneObsIds = [];
        $obsId = $nextObs();
        self::createObservedIoc($conn, $obsId, $msgIds['c1'], $phoneIndicatorId, 'phone', '+33698765432', $now);
        $clusterCPhoneObsIds[] = $obsId;
        $obsId = $nextObs();
        self::createObservedIoc($conn, $obsId, $msgIds['c2'], $phoneIndicatorId, 'phone', '+33698765432', $now);
        $clusterCPhoneObsIds[] = $obsId;

        $ibanDeIndicatorId = self::createIndicator($conn, 'eeeeeeee-0005-4000-8000-000000000001', 'iban', 'DE89370400440532013000', 'DE89370400440532013000', $now);
        self::createObservedIoc($conn, $nextObs(), $msgIds['c2'], $ibanDeIndicatorId, 'iban', 'DE89370400440532013000', $now);
        self::createObservedIoc($conn, $nextObs(), $msgIds['c3'], $ibanDeIndicatorId, 'iban', 'DE89370400440532013000', $now);

        // Singletons: MEDIUM IOCs only (domains, emails) — some shared
        $sharedDomainId = self::createIndicator($conn, 'eeeeeeee-0006-4000-8000-000000000001', 'domain', 'phishing-kit.com', 'phishing-kit[.]com', $now);

        for ($i = 1; $i <= 5; $i++) {
            self::createObservedIoc($conn, $nextObs(), $msgIds["s{$i}"], $sharedDomainId, 'domain', 'phishing-kit.com', $now);
        }

        $emailIndicatorId = self::createIndicator($conn, 'eeeeeeee-0007-4000-8000-000000000001', 'email', 'scammer@evil.com', 'scammer@evil.com', $now);

        for ($i = 6; $i <= 10; $i++) {
            self::createObservedIoc($conn, $nextObs(), $msgIds["s{$i}"], $emailIndicatorId, 'email', 'scammer@evil.com', $now);
        }

        // conv-noioc-01 and conv-noioc-02: NO indicators at all

        // ─── IOC Context (behavioral enrichment) ───
        // Cluster A: 5 enriched contexts with urgency-pressure stimulus + Payment Destination role
        // First 3 share an identical excerpt → templated_excerpt_count = 1
        $ctxCounter = 0;
        $nextCtx = function () use (&$ctxCounter): string {
            $ctxCounter++;

            return sprintf('abcdef00-0000-4000-8000-%012d', $ctxCounter);
        };

        foreach ($clusterAObsIds as $i => $obsId) {
            // First 3 share the same excerpt (templated marker)
            $excerpt = $i < 3
                ? 'Wire transfer demanded urgently to avoid penalties'
                : sprintf('Variant excerpt #%d for cluster A', $i);

            // First 2 conversations show hesitation (a1, a2)
            $hesitation = $i < 2;

            self::createIocContext(
                $conn,
                $nextCtx(),
                $obsId,
                $ibanIndicatorId,
                'INVOICE_FRAUD',
                'Payment Destination',
                'urgency-pressure',
                0.80,
                1,
                $hesitation,
                false,
                $excerpt,
                $now
            );
        }

        // Add ioc_context for the domain IOCs of cluster A — same conversations a1, a2
        // Hesitation = true on BOTH → if we count rows we get 4 (2 IBAN + 2 domain),
        // but if we count distinct conversations we get 2 (a1, a2). This exposes the bug.
        foreach ($clusterADomainObsIds as $entry) {
            self::createIocContext(
                $conn,
                $nextCtx(),
                $entry['obs_id'],
                $domainIndicatorA,
                'INVOICE_FRAUD',
                'Phishing URL',
                'urgency-pressure',
                0.70,
                1,
                true, // hesitation also detected on the domain context
                false,
                'Click this link to confirm your details',
                $now
            );
        }

        // Cluster B: 3 enriched contexts with authority stimulus, 1 hesitation
        foreach ($clusterBObsIds as $i => $obsId) {
            self::createIocContext(
                $conn,
                $nextCtx(),
                $obsId,
                $btcIndicatorId,
                'CEO_FRAUD',
                'Payment Destination',
                'authority',
                0.45,
                3,
                $i === 0, // hesitation on first only
                false,
                'CEO approval required immediately',
                $now
            );
        }

        // Cluster C: 2 enriched contexts on phone with reciprocity, 1 language switch
        foreach ($clusterCPhoneObsIds as $i => $obsId) {
            self::createIocContext(
                $conn,
                $nextCtx(),
                $obsId,
                $phoneIndicatorId,
                'ROMANCE',
                'Contact Channel',
                'reciprocity',
                0.30,
                2,
                false,
                $i === 0, // language switch on first only
                'Please call me back I will explain everything',
                $now
            );
        }
    }

    /**
     * Clean up clustering test data.
     */
    public static function cleanup(Connection $conn): void
    {
        $conn->executeStatement("DELETE FROM threat_actor_cluster_ioc");
        $conn->executeStatement("DELETE FROM threat_actor_cluster_conversation");
        $conn->executeStatement("DELETE FROM threat_actor_cluster");
        $conn->executeStatement("DELETE FROM ioc_context WHERE id::text LIKE 'abcdef00-%'");
        $conn->executeStatement("DELETE FROM observed_ioc WHERE obs_id::text LIKE 'ffffffff-%'");
        $conn->executeStatement("DELETE FROM indicator WHERE indicator_id::text LIKE 'eeeeeeee-%'");
        $conn->executeStatement("DELETE FROM message WHERE msg_id::text LIKE 'dddddddd-%'");
        $conn->executeStatement("DELETE FROM conversation WHERE conv_id::text LIKE 'cccccccc-%'");
    }

    /**
     * @return array<string, string> Map of expected clusters: cluster_name => comma-separated conv keys
     */
    public static function expectedClusters(): array
    {
        return [
            'cluster_a' => 'a1,a2,a3,a4,a5',    // shared IBAN FR76
            'cluster_b' => 'b1,b2,b3',            // shared wallet_btc
            'cluster_c' => 'c1,c2,c3',            // transitive phone+IBAN
        ];
    }

    private static function createConversation(Connection $conn, string $convId, int|string $scamTypeId, int|string $channelId, string $accountId, int|string|null $personaId, string $now, int $dayOffset): string
    {
        $tsFirst = (new \DateTimeImmutable("-{$dayOffset} days"))->format('Y-m-d H:i:s');
        $tsLast = (new \DateTimeImmutable("-" . max(0, $dayOffset - 1) . " days"))->format('Y-m-d H:i:s');

        $conn->executeStatement(
            "INSERT INTO conversation (conv_id, primary_channel_id, scam_type_id, account_id, persona_id, status, score_risk, ts_first, ts_last, stix_id, delivery, tlp, created_at, updated_at)
             VALUES (:convId, :channelId, :scamTypeId, :accountId, :personaId, 'open', 50, :tsFirst, :tsLast, :stixId, 'email', 'AMBER', :now, :now)
             ON CONFLICT (conv_id) DO NOTHING",
            [
                'convId' => $convId,
                'channelId' => $channelId,
                'scamTypeId' => $scamTypeId,
                'accountId' => $accountId,
                'personaId' => $personaId,
                'tsFirst' => $tsFirst,
                'tsLast' => $tsLast,
                'stixId' => "stix-{$convId}",
                'now' => $now,
            ]
        );

        return $convId;
    }

    private static function createMessage(Connection $conn, string $msgId, string $convId, string $now): string
    {
        $channelId = $conn->fetchOne('SELECT channel_id FROM lkp_channel LIMIT 1');
        $direction = $conn->fetchOne('SELECT dir_id FROM lkp_direction LIMIT 1') ?? 1;

        $conn->executeStatement(
            "INSERT INTO message (msg_id, conv_id, channel_id, direction, lang_detect, body_text, headers, ts_msg, ts_ingest, composite_hash)
             VALUES (:msgId, :convId, :channelId, :direction, 'en', 'Test message for clustering', '{}', :now, :now, :hash)
             ON CONFLICT (msg_id) DO NOTHING",
            [
                'msgId' => $msgId,
                'convId' => $convId,
                'channelId' => $channelId,
                'direction' => $direction,
                'now' => $now,
                'hash' => 'clustering-test-' . $msgId,
            ]
        );

        return $msgId;
    }

    private static function createIndicator(Connection $conn, string $indicatorId, string $type, string $value, string $valueNorm, string $now): string
    {
        // Use ON CONFLICT on both PK and unique constraint to handle existing data
        $conn->executeStatement(
            "INSERT INTO indicator (indicator_id, type, value, value_norm, first_seen, last_seen, occurrences, tlp, created_at, updated_at)
             VALUES (:id, :type, :value, :valueNorm, :now, :now, 1, 'AMBER', :now, :now)
             ON CONFLICT DO NOTHING",
            ['id' => $indicatorId, 'type' => $type, 'value' => $value, 'valueNorm' => $valueNorm, 'now' => $now]
        );

        // If indicator already existed (via type+value_norm unique), get its actual ID
        $existingId = $conn->fetchOne(
            'SELECT indicator_id FROM indicator WHERE type = :type AND value_norm = :valueNorm',
            ['type' => $type, 'valueNorm' => $valueNorm]
        );

        return is_string($existingId) ? $existingId : $indicatorId;

        return $indicatorId;
    }

    private static function createObservedIoc(Connection $conn, string $obsId, string $msgId, string $indicatorId, string $type, string $value, string $now, ?string $tsObserved = null): void
    {
        // Insert via DBAL (ObservedIoc entity expects a Message object, but we're in raw SQL context)
        $conn->executeStatement(
            "INSERT INTO observed_ioc (obs_id, msg_id, indicator_id, context_observation, ts_observed)
             VALUES (:obsId, :msgId, :indicatorId, :context, :ts)
             ON CONFLICT (obs_id) DO NOTHING",
            [
                'obsId' => $obsId,
                'msgId' => $msgId,
                'indicatorId' => $indicatorId,
                'context' => json_encode(['type' => $type, 'value' => $value, 'value_norm' => $value, 'score' => ['vt' => 0, 'urlscan' => 0, 'agg' => 0]]),
                'ts' => $tsObserved ?? $now,
            ]
        );
    }

    /**
     * Create an enriched ioc_context row for behavioral profile testing.
     */
    private static function createIocContext(
        Connection $conn,
        string $id,
        string $obsId,
        string $indicatorId,
        string $scamTypeCode,
        string $semanticRole,
        string $stimulusType,
        float $urgencyScore,
        int $revelationTurn,
        bool $hesitationDetected,
        bool $languageSwitch,
        string $contextExcerpt,
        string $now,
    ): void {
        $conn->executeStatement(
            "INSERT INTO ioc_context
             (id, indicator_id, obs_id, scam_type_code, semantic_role, stimulus_type,
              urgency_score, revelation_turn, hesitation_detected, language_switch,
              context_excerpt, enrichment_status, computed_at, created_at)
             VALUES (:id, :indicatorId, :obsId, :scamType, :role, :stimulus,
                     :urgency, :revTurn, :hesitation, :langSwitch,
                     :excerpt, 'enriched', :now, :now)
             ON CONFLICT (id) DO NOTHING",
            [
                'id' => $id,
                'indicatorId' => $indicatorId,
                'obsId' => $obsId,
                'scamType' => $scamTypeCode,
                'role' => $semanticRole,
                'stimulus' => $stimulusType,
                'urgency' => $urgencyScore,
                'revTurn' => $revelationTurn,
                'hesitation' => $hesitationDetected ? 'true' : 'false',
                'langSwitch' => $languageSwitch ? 'true' : 'false',
                'excerpt' => $contextExcerpt,
                'now' => $now,
            ]
        );
    }
}
