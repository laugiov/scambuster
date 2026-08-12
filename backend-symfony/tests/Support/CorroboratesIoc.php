<?php

declare(strict_types=1);

namespace App\Tests\Support;

use Doctrine\DBAL\Connection;

/**
 * Test helper: make a non-financial indicator exportable under IocExportPolicy
 * by corroborating it — recording observations in two distinct conversations —
 * or by pinning an analyst verdict.
 *
 * IocExportPolicy holds a non-financial IOC seen in a single conversation
 * (possible innocent third party); the export tests below only care that the
 * pipeline emits the IOC, so they seed the corroboration a real feed would have.
 */
trait CorroboratesIoc
{
    /**
     * Corroborate the indicator carrying $valueNorm (no-op if absent).
     */
    private function corroborateByValueNorm(Connection $conn, string $valueNorm): void
    {
        $id = $conn->fetchOne('SELECT indicator_id FROM indicator WHERE value_norm = :v LIMIT 1', ['v' => $valueNorm]);

        if (\is_string($id) && $id !== '') {
            $this->corroborateIndicator($conn, $id);
        }
    }

    /**
     * Record observations of $indicatorId in two distinct fixture conversations
     * so its distinct-conversation count reaches the export threshold.
     */
    private function corroborateIndicator(Connection $conn, string $indicatorId): void
    {
        /** @var list<array{msg_id: string, conv_id: string}> $msgs */
        $msgs = $conn->fetchAllAssociative(
            'SELECT m.msg_id, m.conv_id FROM message m
             WHERE m.deleted_at IS NULL
             ORDER BY m.conv_id
             LIMIT 50',
        );

        $seenConvs = [];
        $picked = [];

        foreach ($msgs as $m) {
            $conv = (string) $m['conv_id'];

            if (isset($seenConvs[$conv])) {
                continue;
            }
            $seenConvs[$conv] = true;
            $picked[] = (string) $m['msg_id'];

            if (\count($picked) === 2) {
                break;
            }
        }

        if (\count($picked) < 2) {
            throw new \RuntimeException('fixtures must provide messages in at least two conversations to corroborate an IOC');
        }

        foreach ($picked as $i => $msgId) {
            // Deterministic, collision-safe UUID derived from (indicator, slot).
            $h = md5($indicatorId . ':corrob:' . $i);
            $obsId = sprintf('%s-%s-4%s-8%s-%s', substr($h, 0, 8), substr($h, 8, 4), substr($h, 13, 3), substr($h, 17, 3), substr($h, 20, 12));

            $conn->executeStatement(
                "INSERT INTO observed_ioc (obs_id, msg_id, indicator_id, confidence_score, context_observation, ts_observed)
                 VALUES (:obs, :msg, :ind, 0.8, '{}', NOW())
                 ON CONFLICT DO NOTHING",
                ['obs' => $obsId, 'msg' => $msgId, 'ind' => $indicatorId],
            );
        }
    }
}
