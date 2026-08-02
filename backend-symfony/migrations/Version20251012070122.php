<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20251012070122 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add url_analysis JSON column to message table for storing URL analysis reports from URLScan and VirusTotal';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            ALTER TABLE message ADD url_analysis JSON DEFAULT NULL
        SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            ALTER TABLE message DROP url_analysis
        SQL);
    }
}
