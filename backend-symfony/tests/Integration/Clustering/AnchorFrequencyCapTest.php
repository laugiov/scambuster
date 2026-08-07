<?php

declare(strict_types=1);

namespace App\Tests\Integration\Clustering;

use App\Application\Clustering\IocClusteringService;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * An anchor shared across more conversations than the cap is treated as a reused
 * mule / exchange-deposit / processor account and must NOT form a clustering edge;
 * an anchor shared by only a few conversations still merges them.
 */
final class AnchorFrequencyCapTest extends KernelTestCase
{
    private Connection $conn;

    /** @var list<string> */
    private array $convIds = [];
    /** @var list<string> */
    private array $msgIds = [];
    /** @var list<string> */
    private array $indicatorIds = [];

    private int|string $channelId;
    private int|string $scamTypeId;
    private string $accountId;
    private int|string|null $personaId;
    private int|string $direction;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->conn = self::getContainer()->get(Connection::class);
    }

    protected function tearDown(): void
    {
        if ($this->msgIds !== []) {
            $this->conn->executeStatement('DELETE FROM observed_ioc WHERE msg_id IN (?)', [$this->msgIds], [ArrayParameterType::STRING]);
            $this->conn->executeStatement('DELETE FROM message WHERE msg_id IN (?)', [$this->msgIds], [ArrayParameterType::STRING]);
        }
        if ($this->indicatorIds !== []) {
            $this->conn->executeStatement('DELETE FROM indicator WHERE indicator_id IN (?)', [$this->indicatorIds], [ArrayParameterType::STRING]);
        }
        if ($this->convIds !== []) {
            $this->conn->executeStatement('DELETE FROM conversation WHERE conv_id IN (?)', [$this->convIds], [ArrayParameterType::STRING]);
        }

        parent::tearDown();
    }

    private function borrowFks(): void
    {
        /** @var array<string, mixed>|false $tpl */
        $tpl = $this->conn->fetchAssociative('SELECT primary_channel_id, scam_type_id, account_id, persona_id FROM conversation LIMIT 1');

        if ($tpl === false) {
            self::markTestSkipped('needs an existing conversation to borrow FK values from');
        }

        $this->channelId = $tpl['primary_channel_id'];
        $this->scamTypeId = $tpl['scam_type_id'];
        $this->accountId = (string) $tpl['account_id'];
        $this->personaId = $tpl['persona_id'];
        $this->direction = $this->conn->fetchOne('SELECT dir_id FROM lkp_direction LIMIT 1') ?: 1;
    }

    public function testOverSharedAnchorIsCappedWhileSpecificAnchorMerges(): void
    {
        $this->borrowFks();

        // Two distinct valid IBAN anchors.
        $over = $this->indicator('iban', 'GB82WEST12345698765432');   // shared by 4 conversations
        $under = $this->indicator('iban', 'DE89370400440532013000');  // shared by 2 conversations

        $trigger = $this->conv([$over, $under]);
        $x1 = $this->conv([$over]);
        $x2 = $this->conv([$over]);
        $x3 = $this->conv([$over]);          // over now in 4 conversations
        $y1 = $this->conv([$under]);          // under now in 2 conversations

        // Cap = 3: the over-shared anchor (4 convs) is dropped; the specific one (2) stays.
        $service = new IocClusteringService($this->conn, new NullLogger(), maxAnchorConversations: 3);
        $shared = array_column($service->findSharedConversations($trigger), 'conv_id');

        self::assertContains($y1, $shared, 'a 2-conversation anchor still merges');
        self::assertNotContains($x1, $shared, 'over-shared anchor must be capped');
        self::assertNotContains($x2, $shared, 'over-shared anchor must be capped');
        self::assertNotContains($x3, $shared, 'over-shared anchor must be capped');

        // Control: with a high cap the SAME over-shared anchor merges again —
        // proving the cap (not some unrelated filter) is what excluded it above.
        $permissive = new IocClusteringService($this->conn, new NullLogger(), maxAnchorConversations: 100);
        $sharedHigh = array_column($permissive->findSharedConversations($trigger), 'conv_id');
        self::assertContains($x1, $sharedHigh, 'with a high cap the over-shared anchor merges again');
    }

    public function testPhoneInternationalPrefixVariantsCluster(): void
    {
        $this->borrowFks();

        // Same French number, three international-prefix spellings across 2 convs.
        $p1 = $this->indicator('phone', '+33 6 98 76 54 32'); // digits: 33698765432
        $p2 = $this->indicator('phone', '0033698765432');     // 00-prefixed → 33698765432

        $convA = $this->conv([$p1]);
        $convB = $this->conv([$p2]);

        $service = new IocClusteringService($this->conn, new NullLogger());
        $shared = array_column($service->findSharedConversations($convA), 'conv_id');

        self::assertContains($convB, $shared, '+33…, 0033… and 33… are the same E.164 number and must merge');
    }

    public function testTooShortAnchorIsTreatedAsGenericAndDoesNotMerge(): void
    {
        $this->borrowFks();

        $short = $this->indicator('iban', 'GB82WEST');                 // canon length 8 < 15 → generic
        $full = $this->indicator('iban', 'GB29NWBK60161331926819');    // >= 15 → specific

        $trigger = $this->conv([$short, $full]);
        $viaShort = $this->conv([$short]);
        $viaFull = $this->conv([$full]);

        $service = new IocClusteringService($this->conn, new NullLogger());
        $shared = array_column($service->findSharedConversations($trigger), 'conv_id');

        self::assertContains($viaFull, $shared, 'a full-length IBAN is specific and merges');
        self::assertNotContains($viaShort, $shared, 'a too-short (generic) anchor must not form an edge');
    }

    public function testPhoneAnchorWeighsLessThanIbanAtEqualFrequency(): void
    {
        $this->borrowFks();

        // Unique values so seeded frequencies are isolated from any demo data.
        $phone = $this->indicator('phone', '+336' . random_int(10000000, 99999999));
        $iban = $this->indicator('iban', 'GB29NWBK' . random_int(100000000000, 999999999999) . random_int(10, 99));

        $trigger = $this->conv([$phone, $iban]);
        $p1 = $this->conv([$phone]);
        $p2 = $this->conv([$phone]);   // phone shared by 3 conversations
        $i1 = $this->conv([$iban]);
        $i2 = $this->conv([$iban]);    // iban shared by 3 conversations

        // Same frequency (3), but the weak-type (phone) cap is 2 while the financial
        // cap is 25 → the phone is dropped, the IBAN still merges.
        $service = new IocClusteringService($this->conn, new NullLogger(), maxAnchorConversations: 25, maxWeakAnchorConversations: 2);
        $shared = array_column($service->findSharedConversations($trigger), 'conv_id');

        self::assertContains($i1, $shared, 'a shared IBAN weighs enough to merge');
        self::assertContains($i2, $shared);
        self::assertNotContains($p1, $shared, 'a phone shared as widely weighs less and is capped');
        self::assertNotContains($p2, $shared);
    }

    private function indicator(string $type, string $value): string
    {
        $id = uuid_create(UUID_TYPE_RANDOM);
        $this->indicatorIds[] = $id;
        $this->conn->executeStatement(
            "INSERT INTO indicator (indicator_id, type, value, value_norm, first_seen, last_seen, occurrences, tlp, created_at, updated_at)
             VALUES (:id, :type, :v, :v, NOW(), NOW(), 1, 'AMBER', NOW(), NOW())",
            ['id' => $id, 'type' => $type, 'v' => $value]
        );

        return $id;
    }

    /**
     * @param list<string> $indicatorIds
     */
    private function conv(array $indicatorIds): string
    {
        $convId = uuid_create(UUID_TYPE_RANDOM);
        $this->convIds[] = $convId;
        $this->conn->executeStatement(
            "INSERT INTO conversation (conv_id, primary_channel_id, scam_type_id, account_id, persona_id, status, score_risk, ts_first, ts_last, stix_id, delivery, tlp, created_at, updated_at)
             VALUES (:convId, :channelId, :scamTypeId, :accountId, :personaId, 'open', 50, NOW(), NOW(), :stixId, 'email', 'AMBER', NOW(), NOW())",
            [
                'convId' => $convId, 'channelId' => $this->channelId, 'scamTypeId' => $this->scamTypeId,
                'accountId' => $this->accountId, 'personaId' => $this->personaId, 'stixId' => 'stix-' . $convId,
            ]
        );

        $msgId = uuid_create(UUID_TYPE_RANDOM);
        $this->msgIds[] = $msgId;
        $this->conn->executeStatement(
            "INSERT INTO message (msg_id, conv_id, channel_id, direction, lang_detect, body_text, headers, ts_msg, ts_ingest, composite_hash)
             VALUES (:msgId, :convId, :channelId, :direction, 'en', 'body', '{}', NOW(), NOW(), :hash)",
            ['msgId' => $msgId, 'convId' => $convId, 'channelId' => $this->channelId, 'direction' => $this->direction, 'hash' => 'freq-' . $msgId]
        );

        foreach ($indicatorIds as $indId) {
            $this->conn->executeStatement(
                "INSERT INTO observed_ioc (obs_id, msg_id, indicator_id, context_observation, ts_observed)
                 VALUES (:obsId, :msgId, :indId, '{}'::json, NOW())",
                ['obsId' => uuid_create(UUID_TYPE_RANDOM), 'msgId' => $msgId, 'indId' => $indId]
            );
        }

        return $convId;
    }
}
