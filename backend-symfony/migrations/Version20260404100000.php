<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260404100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add totp_secret column to app_users for MFA TOTP';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE app_users ADD COLUMN totp_secret VARCHAR(255) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE app_users DROP COLUMN totp_secret');
    }
}
