<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Spec 050 — Multi-account SMTP routing
 *
 * Adds 3 nullable columns to mail_account:
 * - email_address: reply-from address (NULL = derive from inbound to: header)
 * - smtp_dsn_encrypted: encrypted SMTP DSN (NULL = use global MAILER_DSN)
 * - label: operator-friendly name for internal use only
 */
final class Version2026041700000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Spec 050 — Add email_address, smtp_dsn_encrypted, label to mail_account';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE mail_account ADD COLUMN email_address VARCHAR(255) DEFAULT NULL');
        $this->addSql('ALTER TABLE mail_account ADD COLUMN smtp_dsn_encrypted TEXT DEFAULT NULL');
        $this->addSql('ALTER TABLE mail_account ADD COLUMN label VARCHAR(64) DEFAULT NULL');
        $this->addSql('CREATE INDEX idx_mail_account_email_address ON mail_account (email_address)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX IF EXISTS idx_mail_account_email_address');
        $this->addSql('ALTER TABLE mail_account DROP COLUMN IF EXISTS label');
        $this->addSql('ALTER TABLE mail_account DROP COLUMN IF EXISTS smtp_dsn_encrypted');
        $this->addSql('ALTER TABLE mail_account DROP COLUMN IF EXISTS email_address');
    }
}
