<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version2026080700000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Index observed_ioc(indicator_id): supports per-indicator distinct-source confidence corroboration';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE INDEX IF NOT EXISTS idx_observed_ioc_indicator ON observed_ioc (indicator_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX IF EXISTS idx_observed_ioc_indicator');
    }
}
