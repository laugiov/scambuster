<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Drop campaign.centroid_simhash — the legacy simhash campaign-clustering path
 * (FeatureExtractor + Campaign\ClusteringService + ClusterAssign*) has been
 * retired. Nothing reads or writes this column any more.
 */
final class Version2026070600200000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Drop campaign.centroid_simhash (retired simhash clustering path)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE campaign DROP COLUMN IF EXISTS centroid_simhash');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE campaign ADD COLUMN centroid_simhash VARCHAR(32) DEFAULT NULL');
    }
}
