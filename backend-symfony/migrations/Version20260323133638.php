<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Add permissions JSON column to app_users for fine-grained RBAC.
 */
final class Version20260323133638 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add permissions column to app_users for RBAC';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE app_users ADD permissions JSON DEFAULT '[]' NOT NULL");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE app_users DROP permissions');
    }
}
