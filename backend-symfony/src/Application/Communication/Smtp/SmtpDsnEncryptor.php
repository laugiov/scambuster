<?php

declare(strict_types=1);

namespace App\Application\Communication\Smtp;

/**
 * Encrypts and decrypts SMTP DSN strings using libsodium authenticated encryption.
 *
 * Algorithm: XSalsa20-Poly1305 via sodium_crypto_secretbox.
 *
 * Storage format: base64(nonce || ciphertext) where nonce is 24 random bytes.
 *
 * Key derivation: SHA-256 (via sodium_crypto_generichash) of APP_SECRET
 * truncated to SODIUM_CRYPTO_SECRETBOX_KEYBYTES (32 bytes).
 *
 * Security properties:
 * - Authenticated: tampering with ciphertext throws RuntimeException
 * - Random nonce per encryption (no reuse possible)
 * - No silent fallback on decryption failure (prevents leaking to global SMTP)
 *
 * Rotation impact: changing APP_SECRET makes all existing encrypted DSNs unreadable.
 * Future spec will provide a key rotation procedure.
 */
final readonly class SmtpDsnEncryptor
{
    // Sodium derives a 32-byte key via generichash regardless of input length.
    // We enforce a sane minimum to catch obviously-weak configurations.
    private const MIN_APP_SECRET_LENGTH = 12;

    private string $key;

    public function __construct(string $appSecret)
    {
        if (\strlen($appSecret) < self::MIN_APP_SECRET_LENGTH) {
            throw new \InvalidArgumentException(sprintf(
                'APP_SECRET must be at least %d characters (got %d)',
                self::MIN_APP_SECRET_LENGTH,
                \strlen($appSecret),
            ));
        }

        $this->key = sodium_crypto_generichash($appSecret, '', SODIUM_CRYPTO_SECRETBOX_KEYBYTES);
    }

    /**
     * Encrypts a plaintext DSN. Returns base64-encoded (nonce || ciphertext).
     *
     * @throws \InvalidArgumentException if plaintext is empty
     */
    public function encrypt(string $plaintext): string
    {
        if ($plaintext === '') {
            throw new \InvalidArgumentException('Cannot encrypt empty plaintext');
        }

        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $ciphertext = sodium_crypto_secretbox($plaintext, $nonce, $this->key);

        return base64_encode($nonce . $ciphertext);
    }

    /**
     * Decrypts a base64-encoded ciphertext. Returns the plaintext DSN.
     *
     * Security: NEVER returns empty string or fallback on failure.
     * Always throws RuntimeException to prevent silent leak to global SMTP.
     *
     * @throws \RuntimeException on tamper detection, wrong key, or malformed input
     */
    public function decrypt(string $ciphertext): string
    {
        if ($ciphertext === '') {
            throw new \RuntimeException('Cannot decrypt empty ciphertext');
        }

        $decoded = base64_decode($ciphertext, true);

        if ($decoded === false) {
            throw new \RuntimeException('Failed to decrypt: invalid base64');
        }

        if (\strlen($decoded) < SODIUM_CRYPTO_SECRETBOX_NONCEBYTES + SODIUM_CRYPTO_SECRETBOX_MACBYTES) {
            throw new \RuntimeException('Failed to decrypt: ciphertext too short');
        }

        $nonce = substr($decoded, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $payload = substr($decoded, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);

        $plaintext = sodium_crypto_secretbox_open($payload, $nonce, $this->key);

        if ($plaintext === false) {
            throw new \RuntimeException('Failed to decrypt: authentication failed (tampered or wrong key)');
        }

        return $plaintext;
    }
}
