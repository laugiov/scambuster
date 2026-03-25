<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * MT-10: Add confidence_score column to observed_ioc table.
 * Default 0.80 for existing data (reasonable baseline).
 */
final class Version20260325111759 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add confidence_score to observed_ioc (MT-10: IOC confidence scoring)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE observed_ioc ADD confidence_score NUMERIC(4, 3) DEFAULT NULL');
        $this->addSql('UPDATE observed_ioc SET confidence_score = 0.800 WHERE confidence_score IS NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE observed_ioc DROP COLUMN confidence_score');
    }
}
