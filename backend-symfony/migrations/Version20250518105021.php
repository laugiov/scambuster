<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;


final class Version20250518105021 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE message_vector (vector_id UUID NOT NULL, embedding JSON NOT NULL, model_name VARCHAR(64) NOT NULL, dim INT NOT NULL, ts_created TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY(vector_id))
        SQL);
        $this->addSql(<<<'SQL'
            COMMENT ON COLUMN message_vector.vector_id IS '(DC2Type:uuid)'
        SQL);
        $this->addSql(<<<'SQL'
            COMMENT ON COLUMN message_vector.ts_created IS '(DC2Type:datetime_immutable)'
        SQL);
        $this->addSql(<<<'SQL'
            CREATE TABLE observed_ioc (obs_id UUID NOT NULL, msg_id UUID NOT NULL, ioc_id UUID NOT NULL, context JSON NOT NULL, ts_observed TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY(obs_id))
        SQL);
        $this->addSql(<<<'SQL'
            CREATE INDEX IDX_95D174D6842BF4A0 ON observed_ioc (msg_id)
        SQL);
        $this->addSql(<<<'SQL'
            COMMENT ON COLUMN observed_ioc.obs_id IS '(DC2Type:uuid)'
        SQL);
        $this->addSql(<<<'SQL'
            COMMENT ON COLUMN observed_ioc.msg_id IS '(DC2Type:uuid)'
        SQL);
        $this->addSql(<<<'SQL'
            COMMENT ON COLUMN observed_ioc.ioc_id IS '(DC2Type:uuid)'
        SQL);
        $this->addSql(<<<'SQL'
            COMMENT ON COLUMN observed_ioc.ts_observed IS '(DC2Type:datetime_immutable)'
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE observed_ioc ADD CONSTRAINT FK_95D174D6842BF4A0 FOREIGN KEY (msg_id) REFERENCES message (msg_id) NOT DEFERRABLE INITIALLY IMMEDIATE
        SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE SCHEMA public
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE observed_ioc DROP CONSTRAINT FK_95D174D6842BF4A0
        SQL);
        $this->addSql(<<<'SQL'
            DROP TABLE message_vector
        SQL);
        $this->addSql(<<<'SQL'
            DROP TABLE observed_ioc
        SQL);
    }
}
