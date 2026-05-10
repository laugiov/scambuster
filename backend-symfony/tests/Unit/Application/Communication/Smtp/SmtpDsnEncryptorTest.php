<?php

declare(strict_types=1);

namespace App\Tests\Unit\Application\Communication\Smtp;

use App\Application\Communication\Smtp\SmtpDsnEncryptor;
use PHPUnit\Framework\TestCase;

final class SmtpDsnEncryptorTest extends TestCase
{
    private const APP_SECRET = 'this-is-a-test-secret-key-for-encryption-32!';

    public function testEncryptDecryptRoundTrip(): void
    {
        $encryptor = new SmtpDsnEncryptor(self::APP_SECRET);
        $original = 'smtps://user:pass@smtp.gmail.com:465';

        $ciphertext = $encryptor->encrypt($original);
        $decrypted = $encryptor->decrypt($ciphertext);

        self::assertSame($original, $decrypted);
    }

    public function testEncryptProducesDifferentCiphertextEachTime(): void
    {
        $encryptor = new SmtpDsnEncryptor(self::APP_SECRET);
        $plaintext = 'smtps://user:pass@smtp.example.com:465';

        $ct1 = $encryptor->encrypt($plaintext);
        $ct2 = $encryptor->encrypt($plaintext);

        self::assertNotSame($ct1, $ct2, 'Each encryption must use a fresh nonce');
    }

    public function testCiphertextIsBase64(): void
    {
        $encryptor = new SmtpDsnEncryptor(self::APP_SECRET);
        $ciphertext = $encryptor->encrypt('smtps://user:pass@smtp.example.com:465');

        self::assertNotFalse(base64_decode($ciphertext, true), 'Ciphertext must be valid base64');
    }

    public function testCiphertextDoesNotContainPlaintext(): void
    {
        $encryptor = new SmtpDsnEncryptor(self::APP_SECRET);
        $secret = 'apppassword123';
        $ciphertext = $encryptor->encrypt('smtps://user:' . $secret . '@smtp.example.com:465');

        $decoded = base64_decode($ciphertext, true);
        self::assertNotFalse($decoded);
        self::assertStringNotContainsString($secret, $decoded);
    }

    public function testTamperDetection(): void
    {
        $encryptor = new SmtpDsnEncryptor(self::APP_SECRET);
        $ciphertext = $encryptor->encrypt('smtps://user:pass@smtp.example.com:465');

        // Flip a bit in the middle of the ciphertext
        $decoded = base64_decode($ciphertext, true);
        self::assertNotFalse($decoded);
        $tampered = substr_replace($decoded, "\x00", \intdiv(\strlen($decoded), 2), 1);
        $tamperedB64 = base64_encode($tampered);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Failed to decrypt');
        $encryptor->decrypt($tamperedB64);
    }

    public function testWrongKeyFailsToDecrypt(): void
    {
        $encryptor1 = new SmtpDsnEncryptor(self::APP_SECRET);
        $encryptor2 = new SmtpDsnEncryptor('completely-different-secret-key-32-chars!');

        $ciphertext = $encryptor1->encrypt('smtps://user:pass@smtp.example.com:465');

        $this->expectException(\RuntimeException::class);
        $encryptor2->decrypt($ciphertext);
    }

    public function testRejectsInvalidBase64(): void
    {
        $encryptor = new SmtpDsnEncryptor(self::APP_SECRET);

        $this->expectException(\RuntimeException::class);
        $encryptor->decrypt('not-valid-base64!!!@@@');
    }

    public function testRejectsTruncatedCiphertext(): void
    {
        $encryptor = new SmtpDsnEncryptor(self::APP_SECRET);
        $ciphertext = $encryptor->encrypt('smtps://user:pass@smtp.example.com:465');

        $decoded = base64_decode($ciphertext, true);
        self::assertNotFalse($decoded);
        $truncated = base64_encode(substr($decoded, 0, 10));

        $this->expectException(\RuntimeException::class);
        $encryptor->decrypt($truncated);
    }

    public function testRejectsEmptyCiphertext(): void
    {
        $encryptor = new SmtpDsnEncryptor(self::APP_SECRET);

        $this->expectException(\RuntimeException::class);
        $encryptor->decrypt('');
    }

    public function testRejectsEmptyPlaintext(): void
    {
        $encryptor = new SmtpDsnEncryptor(self::APP_SECRET);

        $this->expectException(\InvalidArgumentException::class);
        $encryptor->encrypt('');
    }

    public function testHandlesLongDsn(): void
    {
        $encryptor = new SmtpDsnEncryptor(self::APP_SECRET);
        $longDsn = 'smtps://very-long-username-with-many-chars:very-complex-password-with-special-chars!@#$%^&*()@subdomain.subdomain2.example-domain.com:465';

        $decrypted = $encryptor->decrypt($encryptor->encrypt($longDsn));
        self::assertSame($longDsn, $decrypted);
    }

    public function testRejectsShortAppSecret(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('APP_SECRET');
        new SmtpDsnEncryptor('tooshort'); // 8 chars, below 12 minimum
    }
}
