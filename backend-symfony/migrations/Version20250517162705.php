<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20250517162705 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE SEQUENCE lkp_channel_channel_id_seq INCREMENT BY 1 MINVALUE 1 START 1
        SQL);
        $this->addSql(<<<'SQL'
            CREATE SEQUENCE lkp_direction_dir_id_seq INCREMENT BY 1 MINVALUE 1 START 1
        SQL);
        $this->addSql(<<<'SQL'
            CREATE SEQUENCE lkp_scam_type_scam_type_id_seq INCREMENT BY 1 MINVALUE 1 START 1
        SQL);
        $this->addSql(<<<'SQL'
            CREATE TABLE attachment (attachment_id UUID NOT NULL, msg_id UUID NOT NULL, filename VARCHAR(255) NOT NULL, mime_type VARCHAR(128) NOT NULL, size_bytes INT NOT NULL, content_hash BYTEA NOT NULL, vector_id UUID DEFAULT NULL, ts_ingest TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, deleted_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, PRIMARY KEY(attachment_id))
        SQL);
        $this->addSql(<<<'SQL'
            CREATE UNIQUE INDEX UNIQ_795FD9BB1CDA8F7D ON attachment (content_hash)
        SQL);
        $this->addSql(<<<'SQL'
            CREATE INDEX IDX_795FD9BB842BF4A0 ON attachment (msg_id)
        SQL);
        $this->addSql(<<<'SQL'
            COMMENT ON COLUMN attachment.attachment_id IS '(DC2Type:uuid)'
        SQL);
        $this->addSql(<<<'SQL'
            COMMENT ON COLUMN attachment.msg_id IS '(DC2Type:uuid)'
        SQL);
        $this->addSql(<<<'SQL'
            COMMENT ON COLUMN attachment.vector_id IS '(DC2Type:uuid)'
        SQL);
        $this->addSql(<<<'SQL'
            COMMENT ON COLUMN attachment.ts_ingest IS '(DC2Type:datetime_immutable)'
        SQL);
        $this->addSql(<<<'SQL'
            COMMENT ON COLUMN attachment.deleted_at IS '(DC2Type:datetime_immutable)'
        SQL);
        $this->addSql(<<<'SQL'
            CREATE TABLE conversation (conv_id UUID NOT NULL, primary_channel_id INT NOT NULL, scam_type_id INT NOT NULL, account_id UUID NOT NULL, status VARCHAR(255) NOT NULL, score_risk INT NOT NULL, ts_first TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, ts_last TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, stix_id VARCHAR(128) NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY(conv_id))
        SQL);
        $this->addSql(<<<'SQL'
            CREATE UNIQUE INDEX UNIQ_8A8E26E973F6DB72 ON conversation (stix_id)
        SQL);
        $this->addSql(<<<'SQL'
            CREATE INDEX IDX_8A8E26E94C514DCC ON conversation (primary_channel_id)
        SQL);
        $this->addSql(<<<'SQL'
            CREATE INDEX IDX_8A8E26E9A618DE68 ON conversation (scam_type_id)
        SQL);
        $this->addSql(<<<'SQL'
            CREATE INDEX IDX_8A8E26E99B6B5FBA ON conversation (account_id)
        SQL);
        $this->addSql(<<<'SQL'
            COMMENT ON COLUMN conversation.conv_id IS '(DC2Type:uuid)'
        SQL);
        $this->addSql(<<<'SQL'
            COMMENT ON COLUMN conversation.account_id IS '(DC2Type:uuid)'
        SQL);
        $this->addSql(<<<'SQL'
            COMMENT ON COLUMN conversation.ts_first IS '(DC2Type:datetime_immutable)'
        SQL);
        $this->addSql(<<<'SQL'
            COMMENT ON COLUMN conversation.ts_last IS '(DC2Type:datetime_immutable)'
        SQL);
        $this->addSql(<<<'SQL'
            COMMENT ON COLUMN conversation.created_at IS '(DC2Type:datetime_immutable)'
        SQL);
        $this->addSql(<<<'SQL'
            COMMENT ON COLUMN conversation.updated_at IS '(DC2Type:datetime_immutable)'
        SQL);
        $this->addSql(<<<'SQL'
            CREATE TABLE conversation_channel (conv_id UUID NOT NULL, channel_id INT NOT NULL, ts_first_channel TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY(conv_id, channel_id))
        SQL);
        $this->addSql(<<<'SQL'
            CREATE INDEX IDX_39AF804D2FC61EC7 ON conversation_channel (conv_id)
        SQL);
        $this->addSql(<<<'SQL'
            CREATE INDEX IDX_39AF804D72F5A1AA ON conversation_channel (channel_id)
        SQL);
        $this->addSql(<<<'SQL'
            COMMENT ON COLUMN conversation_channel.conv_id IS '(DC2Type:uuid)'
        SQL);
        $this->addSql(<<<'SQL'
            COMMENT ON COLUMN conversation_channel.ts_first_channel IS '(DC2Type:datetime_immutable)'
        SQL);
        $this->addSql(<<<'SQL'
            CREATE TABLE lkp_channel (channel_id INT NOT NULL, code VARCHAR(32) NOT NULL, label_en VARCHAR(64) NOT NULL, label_fr VARCHAR(64) NOT NULL, PRIMARY KEY(channel_id))
        SQL);
        $this->addSql(<<<'SQL'
            CREATE UNIQUE INDEX UNIQ_5B7E391277153098 ON lkp_channel (code)
        SQL);
        $this->addSql(<<<'SQL'
            CREATE TABLE lkp_direction (dir_id INT NOT NULL, code VARCHAR(16) NOT NULL, label_en VARCHAR(32) NOT NULL, label_fr VARCHAR(32) NOT NULL, PRIMARY KEY(dir_id))
        SQL);
        $this->addSql(<<<'SQL'
            CREATE UNIQUE INDEX UNIQ_AC83A70A77153098 ON lkp_direction (code)
        SQL);
        $this->addSql(<<<'SQL'
            CREATE TABLE lkp_scam_type (scam_type_id INT NOT NULL, code VARCHAR(32) NOT NULL, label_en VARCHAR(64) NOT NULL, label_fr VARCHAR(64) NOT NULL, attack_id VARCHAR(32) DEFAULT NULL, PRIMARY KEY(scam_type_id))
        SQL);
        $this->addSql(<<<'SQL'
            CREATE UNIQUE INDEX UNIQ_6DF8B1A577153098 ON lkp_scam_type (code)
        SQL);
        $this->addSql(<<<'SQL'
            CREATE TABLE mail_account (account_id UUID NOT NULL, owner_id UUID NOT NULL, protocol VARCHAR(32) NOT NULL, endpoint VARCHAR(255) NOT NULL, login_hash VARCHAR(255) NOT NULL, oauth_scopes JSON NOT NULL, is_active BOOLEAN DEFAULT true NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY(account_id))
        SQL);
        $this->addSql(<<<'SQL'
            COMMENT ON COLUMN mail_account.account_id IS '(DC2Type:uuid)'
        SQL);
        $this->addSql(<<<'SQL'
            COMMENT ON COLUMN mail_account.owner_id IS '(DC2Type:uuid)'
        SQL);
        $this->addSql(<<<'SQL'
            COMMENT ON COLUMN mail_account.created_at IS '(DC2Type:datetime_immutable)'
        SQL);
        $this->addSql(<<<'SQL'
            COMMENT ON COLUMN mail_account.updated_at IS '(DC2Type:datetime_immutable)'
        SQL);
        $this->addSql(<<<'SQL'
            CREATE TABLE message (msg_id UUID NOT NULL, conv_id UUID NOT NULL, channel_id INT NOT NULL, direction INT NOT NULL, reply_to_msg_id UUID DEFAULT NULL, lang_detect VARCHAR(2) NOT NULL, subject VARCHAR(255) DEFAULT NULL, body_text TEXT NOT NULL, body_html TEXT DEFAULT NULL, headers JSON NOT NULL, composite_hash BYTEA NOT NULL, vector_id UUID DEFAULT NULL, ts_msg TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, ts_ingest TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, deleted_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, PRIMARY KEY(msg_id))
        SQL);
        $this->addSql(<<<'SQL'
            CREATE UNIQUE INDEX UNIQ_B6BD307F6C9C7759 ON message (composite_hash)
        SQL);
        $this->addSql(<<<'SQL'
            CREATE INDEX IDX_B6BD307F2FC61EC7 ON message (conv_id)
        SQL);
        $this->addSql(<<<'SQL'
            CREATE INDEX IDX_B6BD307F72F5A1AA ON message (channel_id)
        SQL);
        $this->addSql(<<<'SQL'
            CREATE INDEX IDX_B6BD307F3E4AD1B3 ON message (direction)
        SQL);
        $this->addSql(<<<'SQL'
            CREATE INDEX IDX_B6BD307F6F5B89B3 ON message (reply_to_msg_id)
        SQL);
        $this->addSql(<<<'SQL'
            COMMENT ON COLUMN message.msg_id IS '(DC2Type:uuid)'
        SQL);
        $this->addSql(<<<'SQL'
            COMMENT ON COLUMN message.conv_id IS '(DC2Type:uuid)'
        SQL);
        $this->addSql(<<<'SQL'
            COMMENT ON COLUMN message.reply_to_msg_id IS '(DC2Type:uuid)'
        SQL);
        $this->addSql(<<<'SQL'
            COMMENT ON COLUMN message.vector_id IS '(DC2Type:uuid)'
        SQL);
        $this->addSql(<<<'SQL'
            COMMENT ON COLUMN message.ts_msg IS '(DC2Type:datetime_immutable)'
        SQL);
        $this->addSql(<<<'SQL'
            COMMENT ON COLUMN message.ts_ingest IS '(DC2Type:datetime_immutable)'
        SQL);
        $this->addSql(<<<'SQL'
            COMMENT ON COLUMN message.deleted_at IS '(DC2Type:datetime_immutable)'
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE attachment ADD CONSTRAINT FK_795FD9BB842BF4A0 FOREIGN KEY (msg_id) REFERENCES message (msg_id) NOT DEFERRABLE INITIALLY IMMEDIATE
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE conversation ADD CONSTRAINT FK_8A8E26E94C514DCC FOREIGN KEY (primary_channel_id) REFERENCES lkp_channel (channel_id) NOT DEFERRABLE INITIALLY IMMEDIATE
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE conversation ADD CONSTRAINT FK_8A8E26E9A618DE68 FOREIGN KEY (scam_type_id) REFERENCES lkp_scam_type (scam_type_id) NOT DEFERRABLE INITIALLY IMMEDIATE
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE conversation ADD CONSTRAINT FK_8A8E26E99B6B5FBA FOREIGN KEY (account_id) REFERENCES mail_account (account_id) NOT DEFERRABLE INITIALLY IMMEDIATE
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE conversation_channel ADD CONSTRAINT FK_39AF804D2FC61EC7 FOREIGN KEY (conv_id) REFERENCES conversation (conv_id) NOT DEFERRABLE INITIALLY IMMEDIATE
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE conversation_channel ADD CONSTRAINT FK_39AF804D72F5A1AA FOREIGN KEY (channel_id) REFERENCES lkp_channel (channel_id) NOT DEFERRABLE INITIALLY IMMEDIATE
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE message ADD CONSTRAINT FK_B6BD307F2FC61EC7 FOREIGN KEY (conv_id) REFERENCES conversation (conv_id) NOT DEFERRABLE INITIALLY IMMEDIATE
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE message ADD CONSTRAINT FK_B6BD307F72F5A1AA FOREIGN KEY (channel_id) REFERENCES lkp_channel (channel_id) NOT DEFERRABLE INITIALLY IMMEDIATE
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE message ADD CONSTRAINT FK_B6BD307F3E4AD1B3 FOREIGN KEY (direction) REFERENCES lkp_direction (dir_id) NOT DEFERRABLE INITIALLY IMMEDIATE
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE message ADD CONSTRAINT FK_B6BD307F6F5B89B3 FOREIGN KEY (reply_to_msg_id) REFERENCES message (msg_id) NOT DEFERRABLE INITIALLY IMMEDIATE
        SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE SCHEMA public
        SQL);
        $this->addSql(<<<'SQL'
            DROP SEQUENCE lkp_channel_channel_id_seq CASCADE
        SQL);
        $this->addSql(<<<'SQL'
            DROP SEQUENCE lkp_direction_dir_id_seq CASCADE
        SQL);
        $this->addSql(<<<'SQL'
            DROP SEQUENCE lkp_scam_type_scam_type_id_seq CASCADE
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE attachment DROP CONSTRAINT FK_795FD9BB842BF4A0
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE conversation DROP CONSTRAINT FK_8A8E26E94C514DCC
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE conversation DROP CONSTRAINT FK_8A8E26E9A618DE68
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE conversation DROP CONSTRAINT FK_8A8E26E99B6B5FBA
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE conversation_channel DROP CONSTRAINT FK_39AF804D2FC61EC7
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE conversation_channel DROP CONSTRAINT FK_39AF804D72F5A1AA
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE message DROP CONSTRAINT FK_B6BD307F2FC61EC7
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE message DROP CONSTRAINT FK_B6BD307F72F5A1AA
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE message DROP CONSTRAINT FK_B6BD307F3E4AD1B3
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE message DROP CONSTRAINT FK_B6BD307F6F5B89B3
        SQL);
        $this->addSql(<<<'SQL'
            DROP TABLE attachment
        SQL);
        $this->addSql(<<<'SQL'
            DROP TABLE conversation
        SQL);
        $this->addSql(<<<'SQL'
            DROP TABLE conversation_channel
        SQL);
        $this->addSql(<<<'SQL'
            DROP TABLE lkp_channel
        SQL);
        $this->addSql(<<<'SQL'
            DROP TABLE lkp_direction
        SQL);
        $this->addSql(<<<'SQL'
            DROP TABLE lkp_scam_type
        SQL);
        $this->addSql(<<<'SQL'
            DROP TABLE mail_account
        SQL);
        $this->addSql(<<<'SQL'
            DROP TABLE message
        SQL);
    }
}
