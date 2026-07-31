<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;


final class Version20250522120539 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // 1. Add a temporary column
        $this->addSql("ALTER TABLE message ADD COLUMN composite_hash_hex CHAR(64);");
        // 2. Copy the values converted to hexadecimal
        $this->addSql("UPDATE message SET composite_hash_hex = encode(composite_hash, 'hex');");
        // 3. Drop the old column
        $this->addSql("ALTER TABLE message DROP COLUMN composite_hash;");
        // 4. Rename the temporary column
        $this->addSql("ALTER TABLE message RENAME COLUMN composite_hash_hex TO composite_hash;");
    }

    public function down(Schema $schema): void
    {
        // 1. Add a temporary binary column
        $this->addSql("ALTER TABLE message ADD COLUMN composite_hash_bin BYTEA;");
        // 2. Copy the decoded values
        $this->addSql("UPDATE message SET composite_hash_bin = decode(composite_hash, 'hex');");
        // 3. Drop the text column
        $this->addSql("ALTER TABLE message DROP COLUMN composite_hash;");
        // 4. Rename the binary column
        $this->addSql("ALTER TABLE message RENAME COLUMN composite_hash_bin TO composite_hash;");
    }
}
