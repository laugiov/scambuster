<?php

declare(strict_types=1);

namespace App\Tests\Unit\Security;

use App\Security\TestCsrfTokenManager;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Security\Csrf\CsrfToken;

class TestCsrfTokenManagerTest extends TestCase
{
    private TestCsrfTokenManager $manager;

    protected function setUp(): void
    {
        $this->manager = new TestCsrfTokenManager();
    }

    public function testGetTokenReturnsValidCsrfToken(): void
    {
        $token = $this->manager->getToken('my_token_id');

        $this->assertInstanceOf(CsrfToken::class, $token);
        $this->assertSame('my_token_id', $token->getId());
        $this->assertSame('valid_csrf_token', $token->getValue());
    }

    public function testRefreshTokenReturnsValidCsrfToken(): void
    {
        $token = $this->manager->refreshToken('refresh_id');

        $this->assertInstanceOf(CsrfToken::class, $token);
        $this->assertSame('refresh_id', $token->getId());
        $this->assertSame('valid_csrf_token', $token->getValue());
    }

    public function testIsTokenValidReturnsTrueForValidValue(): void
    {
        $token = new CsrfToken('test', 'valid_csrf_token');

        $this->assertTrue($this->manager->isTokenValid($token));
    }

    public function testIsTokenValidReturnsFalseForInvalidValue(): void
    {
        $token = new CsrfToken('test', 'wrong_value');

        $this->assertFalse($this->manager->isTokenValid($token));
    }

    public function testRemoveTokenReturnsNull(): void
    {
        $this->assertNull($this->manager->removeToken('any_id'));
    }

    public function testGetTokenWithDifferentIdsReturnsDifferentTokenIds(): void
    {
        $token1 = $this->manager->getToken('id_one');
        $token2 = $this->manager->getToken('id_two');

        $this->assertSame('id_one', $token1->getId());
        $this->assertSame('id_two', $token2->getId());
        // Both share the same value
        $this->assertSame($token1->getValue(), $token2->getValue());
    }
}
