<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * The ioc_analyst_feedback table.
 *
 * Records the current analyst verdict on an IOC (confirmed / false_positive) for
 * the intelligence-lifecycle feedback loop. One current verdict per indicator
 * (upsert on indicator_id); the verdict feeds back into export confidence.
 */
final class Version2026070600100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add ioc_analyst_feedback table (analyst confirmed/false-positive verdicts)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE ioc_analyst_feedback (
                indicator_id UUID NOT NULL,
                verdict VARCHAR(16) NOT NULL,
                note TEXT DEFAULT NULL,
                analyst_id VARCHAR(255) NOT NULL,
                created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                PRIMARY KEY (indicator_id)
            )
        SQL);

        $this->addSql(<<<'SQL'
            ALTER TABLE ioc_analyst_feedback
            ADD CONSTRAINT fk_ioc_analyst_feedback_indicator
            FOREIGN KEY (indicator_id) REFERENCES indicator (indicator_id) ON DELETE CASCADE
        SQL);

        $this->addSql("COMMENT ON TABLE ioc_analyst_feedback IS 'Current analyst verdict per IOC (confirmed/false_positive) for the CTI feedback loop'");
        $this->addSql("COMMENT ON COLUMN ioc_analyst_feedback.verdict IS 'confirmed | false_positive'");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE IF EXISTS ioc_analyst_feedback');
    }
}
