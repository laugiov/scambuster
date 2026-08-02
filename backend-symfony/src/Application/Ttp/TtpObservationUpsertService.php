<?php

declare(strict_types=1);

namespace App\Application\Ttp;

use Doctrine\DBAL\Connection;
use Psr\Log\LoggerInterface;

/**
 * Idempotent raw-DBAL writer for ttp_observation rows.
 *
 * The UNIQUE (msg_id, ttp_id) constraint plus ON CONFLICT DO NOTHING makes
 * re-extraction of the same message a no-op: the first observation of a
 * (message, TTP) pair wins and duplicates are silently skipped. Per-item
 * exception handling is the caller's responsibility.
 */
final readonly class TtpObservationUpsertService
{
    public function __construct(
        private Connection $connection,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * Insert one observation row; returns true when a row was actually
     * inserted, false when the (msg_id, ttp_id) pair already existed.
     *
     * @param array{msg_id: string, conv_id: string, ttp_id: int, confidence: float, evidence: string, evidence_start: int|null, evidence_end: int|null, status: string, taxonomy_version: string, extraction_model: string, prompt_version: string} $row
     */
    public function upsert(array $row): bool
    {
        $affected = (int) $this->connection->executeStatement(
            'INSERT INTO ttp_observation (msg_id, conv_id, ttp_id, confidence, evidence, evidence_start, evidence_end, status, taxonomy_version, extraction_model, prompt_version)
             VALUES (:msg_id, :conv_id, :ttp_id, :confidence, :evidence, :evidence_start, :evidence_end, :status, :taxonomy_version, :extraction_model, :prompt_version)
             ON CONFLICT (msg_id, ttp_id) DO NOTHING',
            $row
        );

        $inserted = $affected === 1;

        if (!$inserted) {
            $this->logger->debug('TTP observation already recorded, insert skipped', [
                'msg_id' => $row['msg_id'],
                'ttp_id' => $row['ttp_id'],
            ]);
        }

        return $inserted;
    }
}
