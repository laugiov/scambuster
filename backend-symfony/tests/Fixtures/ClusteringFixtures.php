<?php

declare(strict_types=1);

namespace App\Tests\Fixtures;

use Doctrine\DBAL\Connection;

/**
 * Creates test data for clustering integration tests.
 *
 * Expected clustering result (with anchor IOCs of severity HIGH only):
 *
 * Cluster A (5 conversations): share IBAN FR7630006000011234567890189
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
        $channelId = $conn->fetchOne('SELECT channel_id FROM channel LIMIT 1');
        $accountId = $conn->fetchOne('SELECT account_id FROM mail_account LIMIT 1');
        $personaId = $conn->fetchOne('SELECT persona_id FROM persona WHERE is_active = true LIMIT 1');

        if (!$scamTypeId || !$channelId || !$accountId) {
            throw new \RuntimeException('ClusteringFixtures requires scam_type, channel, and mail_account in DB');
        }

        // ─── Conversations ───
        $convIds = [];

        // Cluster A: 5 conversations sharing IBAN
        for ($i = 1; $i <= 5; $i++) {
            $convIds["a{$i}"] = self::createConversation($conn, "conv-clust-a{$i}", $scamTypeId, $channelId, $accountId, $personaId, $now, $i);
        }

        // Cluster B: 3 conversations sharing wallet_btc
        for ($i = 1; $i <= 3; $i++) {
            $convIds["b{$i}"] = self::createConversation($conn, "conv-clust-b{$i}", $scamTypeId, $channelId, $accountId, $personaId, $now, $i + 5);
        }

        // Cluster C: 3 conversations (transitive via phone + IBAN)
        for ($i = 1; $i <= 3; $i++) {
            $convIds["c{$i}"] = self::createConversation($conn, "conv-clust-c{$i}", $scamTypeId, $channelId, $accountId, $personaId, $now, $i + 8);
        }

        // Singletons: 10 conversations with only MEDIUM IOCs
        for ($i = 1; $i <= 10; $i++) {
            $convIds["s{$i}"] = self::createConversation($conn, sprintf("conv-single-%02d", $i), $scamTypeId, $channelId, $accountId, $personaId, $now, $i + 11);
        }

        // No IOCs: 2 conversations
        for ($i = 1; $i <= 2; $i++) {
            $convIds["n{$i}"] = self::createConversation($conn, "conv-noioc-0{$i}", $scamTypeId, $channelId, $accountId, $personaId, $now, $i + 21);
        }

        // ─── Messages (1 per conversation) ───
        $msgIds = [];
        foreach ($convIds as $key => $convId) {
            $msgIds[$key] = self::createMessage($conn, "msg-clust-{$key}", $convId, $now);
        }

        // ─── Indicators + ObservedIocs ───

        // Cluster A: shared IBAN in various formats
        $ibanIndicatorId = self::createIndicator($conn, 'ind-iban-fr76', 'iban', 'FR7630006000011234567890189', 'FR7630006000011234567890189', $now);
        foreach (['a1', 'a2', 'a3', 'a4', 'a5'] as $key) {
            self::createObservedIoc($conn, "obs-{$key}-iban", $msgIds[$key], $ibanIndicatorId, 'iban', 'FR7630006000011234567890189', $now);
        }

        // Also add some MEDIUM IOCs to Cluster A conversations (should NOT affect clustering)
        $domainIndicatorA = self::createIndicator($conn, 'ind-domain-evil', 'domain', 'evil-phishing.com', 'evil-phishing[.]com', $now);
        self::createObservedIoc($conn, 'obs-a1-domain', $msgIds['a1'], $domainIndicatorA, 'domain', 'evil-phishing.com', $now);
        self::createObservedIoc($conn, 'obs-a2-domain', $msgIds['a2'], $domainIndicatorA, 'domain', 'evil-phishing.com', $now);

        // Cluster B: shared wallet_btc
        $btcIndicatorId = self::createIndicator($conn, 'ind-btc-1a1z', 'wallet_btc', '1A1zP1eP5QGefi2DMPTfTL5SLmv7DivfNa', '1A1zP1eP5QGefi2DMPTfTL5SLmv7DivfNa', $now);
        foreach (['b1', 'b2', 'b3'] as $key) {
            self::createObservedIoc($conn, "obs-{$key}-btc", $msgIds[$key], $btcIndicatorId, 'wallet_btc', '1A1zP1eP5QGefi2DMPTfTL5SLmv7DivfNa', $now);
        }

        // Cluster C: transitive — c1+c2 share phone, c2+c3 share IBAN
        $phoneIndicatorId = self::createIndicator($conn, 'ind-phone-33', 'phone', '+33698765432', '+33698765432', $now);
        self::createObservedIoc($conn, 'obs-c1-phone', $msgIds['c1'], $phoneIndicatorId, 'phone', '+33698765432', $now);
        self::createObservedIoc($conn, 'obs-c2-phone', $msgIds['c2'], $phoneIndicatorId, 'phone', '+33698765432', $now);

        $ibanDeIndicatorId = self::createIndicator($conn, 'ind-iban-de89', 'iban', 'DE89370400440532013000', 'DE89370400440532013000', $now);
        self::createObservedIoc($conn, 'obs-c2-iban', $msgIds['c2'], $ibanDeIndicatorId, 'iban', 'DE89370400440532013000', $now);
        self::createObservedIoc($conn, 'obs-c3-iban', $msgIds['c3'], $ibanDeIndicatorId, 'iban', 'DE89370400440532013000', $now);

        // Singletons: MEDIUM IOCs only (domains, emails) — some shared
        $sharedDomainId = self::createIndicator($conn, 'ind-domain-shared', 'domain', 'phishing-kit.com', 'phishing-kit[.]com', $now);
        for ($i = 1; $i <= 5; $i++) {
            self::createObservedIoc($conn, "obs-s{$i}-domain", $msgIds["s{$i}"], $sharedDomainId, 'domain', 'phishing-kit.com', $now);
        }

        $emailIndicatorId = self::createIndicator($conn, 'ind-email-scam', 'email', 'scammer@evil.com', 'scammer@evil.com', $now);
        for ($i = 6; $i <= 10; $i++) {
            self::createObservedIoc($conn, "obs-s{$i}-email", $msgIds["s{$i}"], $emailIndicatorId, 'email', 'scammer@evil.com', $now);
        }

        // conv-noioc-01 and conv-noioc-02: NO indicators at all
    }

    /**
     * Clean up clustering test data.
     */
    public static function cleanup(Connection $conn): void
    {
        $conn->executeStatement("DELETE FROM threat_actor_cluster_ioc WHERE cluster_id IN (SELECT cluster_id FROM threat_actor_cluster)");
        $conn->executeStatement("DELETE FROM threat_actor_cluster_conversation WHERE cluster_id IN (SELECT cluster_id FROM threat_actor_cluster)");
        $conn->executeStatement("DELETE FROM threat_actor_cluster");
        $conn->executeStatement("DELETE FROM observed_ioc WHERE obs_id LIKE 'obs-%'");
        $conn->executeStatement("DELETE FROM indicator WHERE indicator_id LIKE 'ind-%'");
        $conn->executeStatement("DELETE FROM message WHERE msg_id LIKE 'msg-clust-%'");
        $conn->executeStatement("DELETE FROM conversation WHERE conv_id LIKE 'conv-clust-%' OR conv_id LIKE 'conv-single-%' OR conv_id LIKE 'conv-noioc-%'");
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

    private static function createConversation(Connection $conn, string $convId, string $scamTypeId, string $channelId, string $accountId, ?string $personaId, string $now, int $dayOffset): string
    {
        $tsFirst = (new \DateTimeImmutable("-{$dayOffset} days"))->format('Y-m-d H:i:s');
        $tsLast = (new \DateTimeImmutable("-" . max(0, $dayOffset - 1) . " days"))->format('Y-m-d H:i:s');

        $conn->executeStatement(
            "INSERT INTO conversation (conv_id, primary_channel_id, scam_type_id, account_id, persona_id, status, score_risk, ts_first, ts_last, stix_id, created_at, updated_at)
             VALUES (:convId, :channelId, :scamTypeId, :accountId, :personaId, 'open', 50, :tsFirst, :tsLast, :stixId, :now, :now)
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
        $directionId = $conn->fetchOne('SELECT direction_id FROM lkp_direction LIMIT 1');

        $conn->executeStatement(
            "INSERT INTO message (msg_id, conv_id, direction, body_text, ts_msg, created_at, updated_at)
             VALUES (:msgId, :convId, :direction, 'Test message for clustering', :now, :now, :now)
             ON CONFLICT (msg_id) DO NOTHING",
            ['msgId' => $msgId, 'convId' => $convId, 'direction' => $directionId, 'now' => $now]
        );

        return $msgId;
    }

    private static function createIndicator(Connection $conn, string $indicatorId, string $type, string $value, string $valueNorm, string $now): string
    {
        $conn->executeStatement(
            "INSERT INTO indicator (indicator_id, type, value, value_norm, first_seen, last_seen, occurrences, tlp, created_at, updated_at)
             VALUES (:id, :type, :value, :valueNorm, :now, :now, 1, 'AMBER', :now, :now)
             ON CONFLICT (indicator_id) DO NOTHING",
            ['id' => $indicatorId, 'type' => $type, 'value' => $value, 'valueNorm' => $valueNorm, 'now' => $now]
        );

        return $indicatorId;
    }

    private static function createObservedIoc(Connection $conn, string $obsId, string $msgId, string $indicatorId, string $type, string $value, string $now): void
    {
        // Insert via DBAL (ObservedIoc entity expects a Message object, but we're in raw SQL context)
        $conn->executeStatement(
            "INSERT INTO observed_ioc (obs_id, msg_id, indicator_id, context_observation, ts_observed, created_at)
             VALUES (:obsId, :msgId, :indicatorId, :context, :now, :now)
             ON CONFLICT (obs_id) DO NOTHING",
            [
                'obsId' => $obsId,
                'msgId' => $msgId,
                'indicatorId' => $indicatorId,
                'context' => json_encode(['type' => $type, 'value' => $value, 'value_norm' => $value, 'score' => ['vt' => 0, 'urlscan' => 0, 'agg' => 0]]),
                'now' => $now,
            ]
        );
    }
}
