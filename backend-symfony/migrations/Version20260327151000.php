<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Append language-matching clause to all 27 persona system_prompt.
 *
 * Root cause: personas describe French individuals (names, cities), causing the LLM
 * to reply in French regardless of the detected inbound language. Adding an explicit
 * language-matching instruction at the END of each persona's identity description
 * ensures the LLM follows the correspondent's language.
 */
final class Version20260327151000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Append language-matching clause to persona system_prompt to fix reply language compliance';
    }

    public function up(Schema $schema): void
    {
        // Append the clause to every persona that doesn't already have it
        $this->addSql(<<<'SQL'
            UPDATE persona
            SET system_prompt = system_prompt || ' Always replies in the same language as the person writing to them.'
            WHERE system_prompt NOT LIKE '%same language%'
            SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            UPDATE persona
            SET system_prompt = REPLACE(system_prompt, ' Always replies in the same language as the person writing to them.', '')
            SQL);
    }
}
