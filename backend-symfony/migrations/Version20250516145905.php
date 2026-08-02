<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;


final class Version20250516145905 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE RefreshToken (token VARCHAR(128) NOT NULL, user_id UUID NOT NULL, expiresAt TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, valid BOOLEAN NOT NULL, PRIMARY KEY(token))
        SQL);
        $this->addSql(<<<'SQL'
            CREATE INDEX IDX_7142379EA76ED395 ON RefreshToken (user_id)
        SQL);
        $this->addSql(<<<'SQL'
            COMMENT ON COLUMN RefreshToken.user_id IS '(DC2Type:uuid)'
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE RefreshToken ADD CONSTRAINT FK_7142379EA76ED395 FOREIGN KEY (user_id) REFERENCES app_users (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE
        SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE SCHEMA public
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE RefreshToken DROP CONSTRAINT FK_7142379EA76ED395
        SQL);
        $this->addSql(<<<'SQL'
            DROP TABLE RefreshToken
        SQL);
    }
}
