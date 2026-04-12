<?php

declare(strict_types=1);

namespace App\Tests\Unit\Infrastructure\Doctrine\Type;

use App\Infrastructure\Doctrine\Type\EncryptedStringType;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use PHPUnit\Framework\TestCase;

/**
 * Spec 065e — Phase 1 — Tests for the EncryptedStringType custom Doctrine type.
 *
 * Uses a real libsodium round-trip to verify encrypt → decrypt losslessness.
 * The env var TOTP_ENCRYPTION_KEY must be set (the test generates its own).
 */
final class EncryptedStringTypeTest extends TestCase
{
    private EncryptedStringType $type;
    private PostgreSQLPlatform $platform;

    protected function setUp(): void
    {
        // Generate a fresh key for each test run
        $_ENV['TOTP_ENCRYPTION_KEY'] = bin2hex(random_bytes(32));

        $this->type = new EncryptedStringType();
        $this->platform = new PostgreSQLPlatform();
    }

    protected function tearDown(): void
    {
        unset($_ENV['TOTP_ENCRYPTION_KEY']);
    }

    public function test_encrypt_then_decrypt_round_trip_lossless(): void
    {
        $original = 'JBSWY3DPEHPK3PXP'; // typical base32 TOTP secret
        $encrypted = $this->type->convertToDatabaseValue($original, $this->platform);
        $this->assertNotSame($original, $encrypted, 'Encrypted value must differ from plaintext');
        $this->assertIsString($encrypted);

        $decrypted = $this->type->convertToPHPValue($encrypted, $this->platform);
        $this->assertSame($original, $decrypted);
    }

    public function test_null_value_passes_through(): void
    {
        $this->assertNull($this->type->convertToDatabaseValue(null, $this->platform));
        $this->assertNull($this->type->convertToPHPValue(null, $this->platform));
    }

    public function test_decryption_with_wrong_key_throws(): void
    {
        $original = 'SENSITIVE_DATA';
        $encrypted = $this->type->convertToDatabaseValue($original, $this->platform);

        // Change the key — decryption must fail
        $_ENV['TOTP_ENCRYPTION_KEY'] = bin2hex(random_bytes(32));
        $freshType = new EncryptedStringType();

        $this->expectException(\Doctrine\DBAL\Types\ConversionException::class);
        $freshType->convertToPHPValue($encrypted, $this->platform);
    }

    public function test_invalid_key_format_throws(): void
    {
        $_ENV['TOTP_ENCRYPTION_KEY'] = 'not-a-valid-hex-key';
        $freshType = new EncryptedStringType();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('TOTP_ENCRYPTION_KEY');
        $freshType->convertToDatabaseValue('test', $this->platform);
    }

    public function test_empty_string_value_round_trip(): void
    {
        $encrypted = $this->type->convertToDatabaseValue('', $this->platform);
        $decrypted = $this->type->convertToPHPValue($encrypted, $this->platform);

        $this->assertSame('', $decrypted);
    }

    public function test_unicode_value_round_trip(): void
    {
        $original = 'Clé secrète avec accents éàü 日本語';
        $encrypted = $this->type->convertToDatabaseValue($original, $this->platform);
        $decrypted = $this->type->convertToPHPValue($encrypted, $this->platform);

        $this->assertSame($original, $decrypted);
    }
}
