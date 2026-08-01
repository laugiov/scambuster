<?php

declare(strict_types=1);

namespace App\Tests\Unit\Domain\User;

use App\Domain\User\RefreshToken;
use App\Domain\User\User;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the hardened RefreshToken entity.
 *
 * Guarantees the two security-relevant invariants of the domain model:
 * the raw token is NEVER stored (only its SHA-256), and every token carries
 * a family (lineage) id used for reuse-driven revocation.
 */
final class RefreshTokenTest extends TestCase
{
    public function testHashIsDeterministicSha256Hex(): void
    {
        $raw = 'a1b2c3';
        $this->assertSame(hash('sha256', $raw), RefreshToken::hash($raw));
        // 64 hex chars, stable across calls.
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', RefreshToken::hash($raw));
        $this->assertSame(RefreshToken::hash($raw), RefreshToken::hash($raw));
    }

    public function testDifferentRawTokensHashDifferently(): void
    {
        $this->assertNotSame(RefreshToken::hash('token-a'), RefreshToken::hash('token-b'));
    }

    public function testIssueStoresTheHashNotTheRawToken(): void
    {
        $raw = bin2hex(random_bytes(64));
        $expiresAt = new \DateTimeImmutable('+30 days');
        $token = RefreshToken::issue($raw, $this->makeUser(), $expiresAt, 'fam-1');

        // The raw token must never be recoverable from the entity.
        $this->assertNotSame($raw, $token->getTokenHash());
        $this->assertSame(hash('sha256', $raw), $token->getTokenHash());
        $this->assertStringNotContainsString($raw, $token->getTokenHash());
    }

    public function testIssuePersistsFamilyUserExpiryAndIsValidByDefault(): void
    {
        $expiresAt = new \DateTimeImmutable('+30 days');
        $user = $this->makeUser();
        $token = RefreshToken::issue('raw', $user, $expiresAt, 'fam-42');

        $this->assertSame('fam-42', $token->getFamily());
        $this->assertSame($user, $token->getUser());
        $this->assertSame($expiresAt, $token->getExpiresAt());
        $this->assertTrue($token->isValid());
    }

    public function testInvalidateFlipsValidity(): void
    {
        $token = RefreshToken::issue('raw', $this->makeUser(), new \DateTimeImmutable('+1 day'), 'fam');
        $token->invalidate();
        $this->assertFalse($token->isValid());
    }

    public function testIsExpiredReflectsExpiry(): void
    {
        $expired = RefreshToken::issue('raw', $this->makeUser(), new \DateTimeImmutable('-1 second'), 'fam');
        $live = RefreshToken::issue('raw', $this->makeUser(), new \DateTimeImmutable('+1 day'), 'fam');
        $this->assertTrue($expired->isExpired());
        $this->assertFalse($live->isExpired());
    }

    private function makeUser(): User
    {
        return $this->createMock(User::class);
    }
}
