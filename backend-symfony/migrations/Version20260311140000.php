<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Add injection_analysis column to message table for prompt injection detection results.
 */
final class Version20260311140000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add injection_analysis JSON column to message table for prompt injection forensic analysis';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            ALTER TABLE message ADD COLUMN injection_analysis JSON DEFAULT NULL
        SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            ALTER TABLE message DROP COLUMN injection_analysis
        SQL);
    }
}
