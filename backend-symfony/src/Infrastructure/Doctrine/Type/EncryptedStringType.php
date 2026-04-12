<?php

declare(strict_types=1);

namespace App\Infrastructure\Doctrine\Type;

use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Types\ConversionException;
use Doctrine\DBAL\Types\Type;

/**
 * Spec 065e — Doctrine custom type for transparently encrypting /
 * decrypting string values at rest using libsodium secretbox.
 *
 * Storage format: raw binary (nonce || ciphertext) stored as BYTEA
 * in PostgreSQL. The 24-byte nonce is prepended to the ciphertext on
 * write and split on read.
 *
 * The encryption key is read from the `TOTP_ENCRYPTION_KEY` env var
 * (64 hex chars = 32 bytes).
 *
 * Used by `User::$totpSecret` to encrypt TOTP secrets at rest.
 * The key is separate from the DB credentials: an attacker who
 * exfiltrates the database via SQL injection does NOT have the key
 * (which lives in `.env` on the application host).
 *
 * @see docs/runbooks/totp-key-rotation.md
 */
final class EncryptedStringType extends Type
{
    public const NAME = 'encrypted_string';

    public function getSQLDeclaration(array $column, AbstractPlatform $platform): string
    {
        return $platform->getBlobTypeDeclarationSQL($column);
    }

    public function getName(): string
    {
        return self::NAME;
    }

    public function convertToDatabaseValue(mixed $value, AbstractPlatform $platform): ?string
    {
        if ($value === null) {
            return null;
        }

        $key = $this->getKey();
        $nonce = random_bytes(\SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $cipher = sodium_crypto_secretbox((string) $value, $nonce, $key);

        return $nonce . $cipher;
    }

    public function convertToPHPValue(mixed $value, AbstractPlatform $platform): ?string
    {
        if ($value === null) {
            return null;
        }

        // PostgreSQL BYTEA comes as a PHP stream resource in some drivers
        if (is_resource($value)) {
            $value = stream_get_contents($value);
        }

        if (!is_string($value)) {
            throw ConversionException::conversionFailed($value, self::NAME);
        }

        $key = $this->getKey();
        $nonce = substr($value, 0, \SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $cipher = substr($value, \SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);

        $plain = sodium_crypto_secretbox_open($cipher, $nonce, $key);

        if ($plain === false) {
            throw ConversionException::conversionFailed(
                'ciphertext',
                self::NAME,
                null,
                new \RuntimeException('Failed to decrypt encrypted_string column — wrong TOTP_ENCRYPTION_KEY?'),
            );
        }

        return $plain;
    }

    public function requiresSQLCommentHint(AbstractPlatform $platform): bool
    {
        return true;
    }

    private function getKey(): string
    {
        $hexKey = $_ENV['TOTP_ENCRYPTION_KEY'] ?? $_SERVER['TOTP_ENCRYPTION_KEY'] ?? null;

        if (!is_string($hexKey) || strlen($hexKey) !== 64 || !ctype_xdigit($hexKey)) {
            throw new \RuntimeException(
                'TOTP_ENCRYPTION_KEY env var must be 64 hex chars (32 bytes). '
                . 'Generate with: openssl rand -hex 32',
            );
        }

        return (string) hex2bin($hexKey);
    }
}
