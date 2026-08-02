<?php

declare(strict_types=1);

namespace App\Tests\Unit\Application\Auth\Oidc;

use App\Application\Auth\Oidc\OidcException;
use App\Application\Auth\Oidc\OidcStateManager;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;

final class OidcStateManagerTest extends TestCase
{
    private function manager(MockClock $clock, string $secret = 'unit-test-secret'): OidcStateManager
    {
        return new OidcStateManager($secret, $clock);
    }

    #[Test]
    public function it_issues_distinct_high_entropy_secrets_with_a_valid_pkce_challenge(): void
    {
        $manager = $this->manager(new MockClock());

        $a = $manager->issue();
        $b = $manager->issue();

        self::assertNotSame($a->state, $b->state);
        self::assertNotSame($a->nonce, $b->nonce);
        self::assertNotSame($a->codeVerifier, $b->codeVerifier);

        // The challenge must be the S256 (base64url, unpadded) of the verifier.
        $expected = rtrim(strtr(base64_encode(hash('sha256', $a->codeVerifier, true)), '+/', '-_'), '=');
        self::assertSame($expected, $a->codeChallenge);
    }

    #[Test]
    public function it_round_trips_serialize_then_parse(): void
    {
        $manager = $this->manager(new MockClock());
        $flow = $manager->issue();

        $parsed = $manager->parse($manager->serialize($flow));

        self::assertSame($flow->state, $parsed->state);
        self::assertSame($flow->nonce, $parsed->nonce);
        self::assertSame($flow->codeVerifier, $parsed->codeVerifier);
    }

    #[Test]
    public function it_rejects_a_tampered_payload(): void
    {
        $manager = $this->manager(new MockClock());
        $cookie = $manager->serialize($manager->issue());
        $tampered = ($cookie[0] === 'a' ? 'b' : 'a') . substr($cookie, 1);

        $this->expectException(OidcException::class);
        $manager->parse($tampered);
    }

    #[Test]
    public function it_rejects_a_cookie_signed_with_a_different_secret(): void
    {
        $signer = $this->manager(new MockClock(), 'secret-A');
        $verifier = $this->manager(new MockClock(), 'secret-B');
        $cookie = $signer->serialize($signer->issue());

        $this->expectException(OidcException::class);
        $verifier->parse($cookie);
    }

    #[Test]
    public function it_rejects_an_expired_state(): void
    {
        $clock = new MockClock('2026-01-01 00:00:00');
        $manager = $this->manager($clock);
        $cookie = $manager->serialize($manager->issue());

        $clock->modify('+11 minutes'); // TTL is 10 minutes

        $this->expectException(OidcException::class);
        $manager->parse($cookie);
    }

    #[Test]
    public function it_accepts_state_just_before_expiry(): void
    {
        $clock = new MockClock('2026-01-01 00:00:00');
        $manager = $this->manager($clock);
        $cookie = $manager->serialize($manager->issue());

        $clock->modify('+9 minutes');

        $parsed = $manager->parse($cookie);
        self::assertNotSame('', $parsed->state);
    }

    #[Test]
    public function it_rejects_a_malformed_cookie(): void
    {
        $manager = $this->manager(new MockClock());

        $this->expectException(OidcException::class);
        $manager->parse('not-a-valid-cookie');
    }
}
