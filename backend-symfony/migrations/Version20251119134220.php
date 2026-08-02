<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20251119134220 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add index on message.headers->>\'from\' for efficient sender lookup';
    }

    public function up(Schema $schema): void
    {
        // Add B-tree index on extracted text value headers->>'from' to optimize queries searching by sender email
        // This index improves performance when finding all conversations from the same sender
        // Note: headers is type 'json' (not jsonb), so we index the text extraction
        $this->addSql('CREATE INDEX idx_message_headers_from ON message ((headers->>\'from\'))');
    }

    public function down(Schema $schema): void
    {
        // Remove the GIN index
        $this->addSql('DROP INDEX IF EXISTS idx_message_headers_from');
    }
}
