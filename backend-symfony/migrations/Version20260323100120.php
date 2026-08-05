<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Create audit_log table for security event tracking.
 */
final class Version20260323100120 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create audit_log table for structured security audit trail';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE SEQUENCE audit_log_id_seq INCREMENT BY 1 MINVALUE 1 START 1');
        $this->addSql('CREATE TABLE audit_log (
            id INT NOT NULL DEFAULT nextval(\'audit_log_id_seq\'),
            event_type VARCHAR(50) NOT NULL,
            actor_type VARCHAR(30) NOT NULL,
            actor_id VARCHAR(255) NOT NULL,
            resource_type VARCHAR(50) DEFAULT NULL,
            resource_id VARCHAR(255) DEFAULT NULL,
            action VARCHAR(20) NOT NULL,
            outcome VARCHAR(20) NOT NULL,
            details JSON NOT NULL,
            ip_address VARCHAR(45) DEFAULT NULL,
            trace_id VARCHAR(64) DEFAULT NULL,
            created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
            PRIMARY KEY(id)
        )');
        $this->addSql('CREATE INDEX idx_audit_event_type ON audit_log (event_type)');
        $this->addSql('CREATE INDEX idx_audit_created_at ON audit_log (created_at)');
        $this->addSql('CREATE INDEX idx_audit_actor_id ON audit_log (actor_id)');
        $this->addSql("COMMENT ON COLUMN audit_log.created_at IS '(DC2Type:datetime_immutable)'");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP SEQUENCE audit_log_id_seq CASCADE');
        $this->addSql('DROP TABLE audit_log');
    }
}
