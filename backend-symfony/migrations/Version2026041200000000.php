<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;
use Doctrine\Migrations\Exception\IrreversibleMigration;

/**
 * Encrypt existing TOTP secrets at rest using libsodium.
 *
 * Changes the `totp_secret` column from VARCHAR(255) to BYTEA and
 * encrypts every existing non-null value in place.
 *
 * At the time of writing (2026-04-11), zero users have a non-null
 * totp_secret in the dev/test/prod databases, so the encryption loop
 * is a no-op. The schema change is still needed for the
 * EncryptedStringType to write binary ciphertext on future enrollments.
 *
 * Requires: TOTP_ENCRYPTION_KEY env var (64 hex chars = 32 bytes).
 * The migration fails loudly if the key is missing or malformed.
 *
 * Irreversible: decrypting back to plaintext would re-introduce H2.
 */
final class Version2026041200000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Change totp_secret from VARCHAR(255) to BYTEA + encrypt existing values';
    }

    public function up(Schema $schema): void
    {
        // 1. Validate the encryption key BEFORE touching any data
        $hexKey = $_ENV['TOTP_ENCRYPTION_KEY'] ?? null;

        if (!is_string($hexKey) || strlen($hexKey) !== 64 || !ctype_xdigit($hexKey)) {
            throw new \RuntimeException(
                'This migration requires TOTP_ENCRYPTION_KEY env var (64 hex chars). '
                . 'Generate with: openssl rand -hex 32',
            );
        }
        $key = (string) hex2bin($hexKey);

        // 2. Read existing non-null secrets BEFORE changing the column type
        $rows = $this->connection->fetchAllAssociative(
            'SELECT id, totp_secret FROM app_users WHERE totp_secret IS NOT NULL',
        );

        // 3. Drop the VARCHAR default before changing type (PG cannot auto-cast
        //    'NULL::character varying' to BYTEA), then change to BYTEA.
        $this->addSql('ALTER TABLE app_users ALTER COLUMN totp_secret DROP DEFAULT');
        $this->addSql('ALTER TABLE app_users ALTER COLUMN totp_secret TYPE BYTEA USING NULL');

        // 4. Encrypt each existing secret (no-op if zero rows)
        foreach ($rows as $row) {
            $plaintext = (string) $row['totp_secret'];
            $nonce = random_bytes(\SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
            $cipher = sodium_crypto_secretbox($plaintext, $nonce, $key);

            $this->connection->update(
                'app_users',
                ['totp_secret' => $nonce . $cipher],
                ['id' => $row['id']],
                ['totp_secret' => \PDO::PARAM_LOB],
            );
        }

        if (count($rows) > 0) {
            $this->write(sprintf('  <info>Encrypted %d existing TOTP secret(s)</info>', count($rows)));
        } else {
            $this->write('  <info>No existing TOTP secrets to encrypt (0 rows with non-null value)</info>');
        }
    }

    public function down(Schema $schema): void
    {
        throw new IrreversibleMigration(
            'Reverting TOTP encryption would re-introduce the H2 finding '
            . '(plaintext TOTP secrets at rest). Restore from backup if needed.',
        );
    }
}
