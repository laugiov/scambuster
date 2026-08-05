<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Create the ttp_observation table: one row per (message, TTP) pair observed in an
 * inbound message, carrying the verbatim evidence quote, the extraction confidence
 * and the taxonomy/model/prompt provenance stamps.
 *
 * Purely additive: one new empty table plus its indexes — it touches no existing
 * table and removes no data, so it is safe on a populated production DB. The
 * UNIQUE (msg_id, ttp_id) constraint is the anchor for idempotent
 * ON CONFLICT DO NOTHING writes, so re-extraction never duplicates observations.
 */
final class Version2026073000100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create ttp_observation table for per-message TTP observations';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(
            'CREATE TABLE ttp_observation ('
            . 'obs_id UUID NOT NULL DEFAULT gen_random_uuid(), '
            . 'msg_id UUID NOT NULL, '
            . 'conv_id UUID NOT NULL, '
            . 'ttp_id INT NOT NULL, '
            . 'confidence NUMERIC(4, 3) NOT NULL, '
            . 'evidence TEXT NOT NULL, '
            . 'evidence_start INT DEFAULT NULL, '
            . 'evidence_end INT DEFAULT NULL, '
            . 'status VARCHAR(16) NOT NULL, '
            . 'taxonomy_version VARCHAR(16) NOT NULL, '
            . 'extraction_model VARCHAR(64) NOT NULL, '
            . 'prompt_version VARCHAR(16) NOT NULL, '
            . 'created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL DEFAULT NOW(), '
            . 'PRIMARY KEY(obs_id), '
            . 'CONSTRAINT uniq_ttp_observation_msg_ttp UNIQUE (msg_id, ttp_id), '
            . 'CONSTRAINT chk_ttp_observation_confidence CHECK (confidence >= 0 AND confidence <= 1), '
            . "CONSTRAINT chk_ttp_observation_status CHECK (status IN ('confirmed', 'review')), "
            . 'CONSTRAINT fk_ttp_observation_msg FOREIGN KEY (msg_id) REFERENCES message (msg_id) ON DELETE CASCADE, '
            . 'CONSTRAINT fk_ttp_observation_conv FOREIGN KEY (conv_id) REFERENCES conversation (conv_id) ON DELETE CASCADE, '
            . 'CONSTRAINT fk_ttp_observation_ttp FOREIGN KEY (ttp_id) REFERENCES lkp_ttp (ttp_id)'
            . ')'
        );
        $this->addSql('CREATE INDEX idx_ttp_observation_conv ON ttp_observation (conv_id)');
        $this->addSql('CREATE INDEX idx_ttp_observation_ttp ON ttp_observation (ttp_id)');
        $this->addSql('CREATE INDEX idx_ttp_observation_status ON ttp_observation (status)');
        $this->addSql("COMMENT ON COLUMN ttp_observation.created_at IS '(DC2Type:datetime_immutable)'");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE ttp_observation');
    }
}
