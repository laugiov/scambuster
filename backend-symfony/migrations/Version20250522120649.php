<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20250522120649 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Ajoute un index unique sur message.composite_hash';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE UNIQUE INDEX uniq_message_composite_hash ON message (composite_hash)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX uniq_message_composite_hash');
    }
} 