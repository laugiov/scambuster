<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;


final class Version20250525120811 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            ALTER TABLE mail_account ADD port INT DEFAULT NULL
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE mail_account ADD secure BOOLEAN DEFAULT NULL
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE message ALTER composite_hash TYPE VARCHAR(64)
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE message ALTER composite_hash SET NOT NULL
        SQL);
        $this->addSql(<<<'SQL'
            ALTER INDEX uniq_message_composite_hash RENAME TO UNIQ_B6BD307F6C9C7759
        SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE SCHEMA public
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE mail_account DROP port
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE mail_account DROP secure
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE message ALTER composite_hash TYPE CHAR(64)
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE message ALTER composite_hash DROP NOT NULL
        SQL);
        $this->addSql(<<<'SQL'
            ALTER INDEX uniq_b6bd307f6c9c7759 RENAME TO uniq_message_composite_hash
        SQL);
    }
}
