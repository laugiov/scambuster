<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20250526142500 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add new fields to attachment table for metadata and analysis results';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE attachment ADD s3_key VARCHAR(255) DEFAULT NULL');
        $this->addSql('ALTER TABLE attachment ADD enc_key_id VARCHAR(64) DEFAULT NULL');
        $this->addSql('ALTER TABLE attachment ADD av_status VARCHAR(16) NOT NULL DEFAULT \'pending\'');
        $this->addSql('ALTER TABLE attachment ADD ocr_text TEXT DEFAULT NULL');
        $this->addSql('ALTER TABLE attachment ADD metadata JSON DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE attachment DROP s3_key');
        $this->addSql('ALTER TABLE attachment DROP enc_key_id');
        $this->addSql('ALTER TABLE attachment DROP av_status');
        $this->addSql('ALTER TABLE attachment DROP ocr_text');
        $this->addSql('ALTER TABLE attachment DROP metadata');
    }
} 