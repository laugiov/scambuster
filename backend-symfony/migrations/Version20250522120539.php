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
        // 1. Ajouter une colonne temporaire
        $this->addSql("ALTER TABLE message ADD COLUMN composite_hash_hex CHAR(64);");
        // 2. Copier les valeurs converties en hexadécimal
        $this->addSql("UPDATE message SET composite_hash_hex = encode(composite_hash, 'hex');");
        // 3. Supprimer l'ancienne colonne
        $this->addSql("ALTER TABLE message DROP COLUMN composite_hash;");
        // 4. Renommer la colonne temporaire
        $this->addSql("ALTER TABLE message RENAME COLUMN composite_hash_hex TO composite_hash;");
    }

    public function down(Schema $schema): void
    {
        // 1. Ajouter une colonne temporaire binaire
        $this->addSql("ALTER TABLE message ADD COLUMN composite_hash_bin BYTEA;");
        // 2. Copier les valeurs décodées
        $this->addSql("UPDATE message SET composite_hash_bin = decode(composite_hash, 'hex');");
        // 3. Supprimer la colonne texte
        $this->addSql("ALTER TABLE message DROP COLUMN composite_hash;");
        // 4. Renommer la colonne binaire
        $this->addSql("ALTER TABLE message RENAME COLUMN composite_hash_bin TO composite_hash;");
    }
}
