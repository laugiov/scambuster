<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Spec 075b — Add secondary_scam_types JSONB column to conversation table
 * for multi-label scam classification support.
 */
final class Version2026041500000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Spec 075b — Add secondary_scam_types JSONB column to conversation';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE conversation ADD COLUMN secondary_scam_types JSONB DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE conversation DROP COLUMN secondary_scam_types');
    }
}
