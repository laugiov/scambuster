<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20250519100204 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            ALTER TABLE attachment DROP CONSTRAINT FK_795FD9BB842BF4A0
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE attachment ADD CONSTRAINT FK_795FD9BB842BF4A0 FOREIGN KEY (msg_id) REFERENCES message (msg_id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE conversation_channel DROP CONSTRAINT FK_39AF804D2FC61EC7
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE conversation_channel ADD CONSTRAINT FK_39AF804D2FC61EC7 FOREIGN KEY (conv_id) REFERENCES conversation (conv_id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE message DROP CONSTRAINT FK_B6BD307F2FC61EC7
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE message ADD CONSTRAINT FK_B6BD307F2FC61EC7 FOREIGN KEY (conv_id) REFERENCES conversation (conv_id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE observed_ioc DROP CONSTRAINT FK_95D174D6842BF4A0
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE observed_ioc ADD CONSTRAINT FK_95D174D6842BF4A0 FOREIGN KEY (msg_id) REFERENCES message (msg_id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE
        SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE SCHEMA public
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE observed_ioc DROP CONSTRAINT fk_95d174d6842bf4a0
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE observed_ioc ADD CONSTRAINT fk_95d174d6842bf4a0 FOREIGN KEY (msg_id) REFERENCES message (msg_id) NOT DEFERRABLE INITIALLY IMMEDIATE
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE attachment DROP CONSTRAINT fk_795fd9bb842bf4a0
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE attachment ADD CONSTRAINT fk_795fd9bb842bf4a0 FOREIGN KEY (msg_id) REFERENCES message (msg_id) NOT DEFERRABLE INITIALLY IMMEDIATE
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE conversation_channel DROP CONSTRAINT fk_39af804d2fc61ec7
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE conversation_channel ADD CONSTRAINT fk_39af804d2fc61ec7 FOREIGN KEY (conv_id) REFERENCES conversation (conv_id) NOT DEFERRABLE INITIALLY IMMEDIATE
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE message DROP CONSTRAINT fk_b6bd307f2fc61ec7
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE message ADD CONSTRAINT fk_b6bd307f2fc61ec7 FOREIGN KEY (conv_id) REFERENCES conversation (conv_id) NOT DEFERRABLE INITIALLY IMMEDIATE
        SQL);
    }
}
