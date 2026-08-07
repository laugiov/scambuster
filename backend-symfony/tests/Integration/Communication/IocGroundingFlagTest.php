<?php

declare(strict_types=1);

namespace App\Tests\Integration\Communication;

use App\Application\Communication\IocHandler;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * The enriched-IOC ingest path (n8n) previously trusted its input verbatim —
 * IocValidator was never called and nothing checked that the value actually
 * appears in the source message. This flags (non-destructively) invalid and
 * un-grounded observations in context_observation for the analyst.
 */
final class IocGroundingFlagTest extends KernelTestCase
{
    private Connection $conn;
    private IocHandler $handler;

    /** @var list<string> */
    private array $msgIds = [];
    private string $runId = '';
    private ?\App\Domain\Communication\ObservedIoc $lastObs = null;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->conn = self::getContainer()->get(Connection::class);
        $this->handler = self::getContainer()->get(IocHandler::class);
        $this->runId = bin2hex(random_bytes(4));
    }

    protected function tearDown(): void
    {
        if ($this->msgIds !== []) {
            $this->conn->executeStatement('DELETE FROM observed_ioc WHERE msg_id IN (?)', [$this->msgIds], [ArrayParameterType::STRING]);
            $this->conn->executeStatement('DELETE FROM message WHERE msg_id IN (?)', [$this->msgIds], [ArrayParameterType::STRING]);
        }

        parent::tearDown();
    }

    public function testValidValueInBodyIsGroundedAndValid(): void
    {
        $iban = 'GB29NWBK60161331926819';
        $ctx = $this->ingest('iban', $iban, $iban, "Please wire to {$iban} today.");

        self::assertTrue($ctx['valid'], 'a mod-97-valid IBAN present in the body is valid');
        self::assertTrue($ctx['grounded'], 'value appears verbatim in the source body');
    }

    public function testValueAbsentFromSourceIsFlaggedUngrounded(): void
    {
        $iban = 'GB29NWBK60161331926819';
        $ctx = $this->ingest('iban', $iban, $iban, 'This body never mentions the account number at all.');

        self::assertTrue($ctx['valid']);
        self::assertFalse($ctx['grounded'], 'value does not appear in the source → ungrounded');
    }

    public function testInvalidChecksumIsFlaggedInvalid(): void
    {
        $bad = 'GB00NWBK60161331926819'; // wrong check digits
        $ctx = $this->ingest('iban', $bad, $bad, "Pay {$bad} now.");

        self::assertFalse($ctx['valid'], 'a bad mod-97 IBAN is flagged invalid');
        self::assertTrue($ctx['grounded'], 'it still appears in the body');
    }

    public function testGroundingIgnoresSeparators(): void
    {
        // The body carries the IBAN with spaces; the normalized value has none.
        $ctx = $this->ingest('iban', 'GB29 NWBK 6016 1331 9268 19', 'GB29NWBK60161331926819', 'IBAN: GB29 NWBK 6016 1331 9268 19');

        self::assertTrue($ctx['grounded'], 'separator differences must not break grounding');
    }

    /**
     * @return array<string, mixed>
     */
    private function ingest(string $type, string $value, string $valueNorm, string $body): array
    {
        $convId = $this->conn->fetchOne('SELECT conv_id FROM conversation LIMIT 1');
        $channelId = $this->conn->fetchOne('SELECT channel_id FROM lkp_channel LIMIT 1');
        $inDir = $this->conn->fetchOne("SELECT dir_id FROM lkp_direction WHERE code = 'in'");

        $msgId = uuid_create(UUID_TYPE_RANDOM);
        $this->msgIds[] = $msgId;
        $this->conn->executeStatement(
            "INSERT INTO message (msg_id, conv_id, channel_id, direction, lang_detect, subject, body_text,
                headers, composite_hash, ts_msg, ts_ingest, external_message_id)
             VALUES (:msgId, :convId, :channelId, :direction, 'en', 'grounding test', :body,
                '{}'::json, :hash, NOW(), NOW(), :extId)",
            [
                'msgId' => $msgId, 'convId' => $convId, 'channelId' => $channelId, 'direction' => $inDir,
                'body' => $body, 'hash' => bin2hex(random_bytes(32)),
                'extId' => 'grounding-' . $this->runId . '-' . \count($this->msgIds),
            ]
        );

        $obs = $this->handler->upsertEnrichedIoc([
            'msg_id' => $msgId,
            'ioc' => [
                'type' => $type,
                'value' => $value,
                'value_norm' => $valueNorm,
                'source' => 'body',
                'first_seen' => (new \DateTimeImmutable())->format(DATE_ATOM),
            ],
            'enrichment' => [],
        ]);

        $this->lastObs = $obs;

        $raw = $this->conn->fetchOne('SELECT context_observation FROM observed_ioc WHERE obs_id = ?', [$obs->getObsId()]);
        /** @var array<string, mixed> $ctx */
        $ctx = json_decode(\is_string($raw) ? $raw : '{}', true);

        return $ctx;
    }

    public function testUngroundedFlagSurfacesInIocDetail(): void
    {
        $iban = 'GB29NWBK60161331926819';
        $this->ingest('iban', $iban, $iban, 'A body that never names the account.');
        self::assertNotNull($this->lastObs);

        $detail = $this->handler->getIocDetail($this->lastObs->getIndicatorId());
        self::assertIsArray($detail);
        /** @var list<array<string, mixed>> $observations */
        $observations = $detail['observations'] ?? [];

        $mine = null;
        foreach ($observations as $o) {
            if (($o['obs_id'] ?? null) === $this->lastObs->getObsId()) {
                $mine = $o;
            }
        }

        self::assertNotNull($mine, 'the observation must be present in the detail');
        self::assertFalse($mine['grounded'], 'the ungrounded flag must be visible to the analyst in the IOC detail');
    }
}
