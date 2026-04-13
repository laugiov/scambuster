<?php

declare(strict_types=1);

namespace App\Tests\Unit\UI\Http\Auth;

use App\Domain\User\Repository\UserRepositoryInterface;
use App\Domain\User\User;
use App\UI\Http\Auth\TotpSetupController;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;

class TotpSetupControllerTest extends TestCase
{
    private UserRepositoryInterface&MockObject $userRepo;
    private TokenStorageInterface&MockObject $tokenStorage;
    private TotpSetupController $controller;

    protected function setUp(): void
    {
        $this->userRepo = $this->createMock(UserRepositoryInterface::class);
        $this->tokenStorage = $this->createMock(TokenStorageInterface::class);
        $this->controller = new TotpSetupController($this->userRepo, $this->tokenStorage);
    }

    public function test_returns_401_when_no_token(): void
    {
        $this->tokenStorage->method('getToken')->willReturn(null);

        $response = $this->controller->__invoke();
        $this->assertSame(401, $response->getStatusCode());

        $data = json_decode($response->getContent(), true);
        $this->assertSame('Not authenticated', $data['message']);
    }

    public function test_returns_404_when_user_not_found(): void
    {
        $token = $this->createMock(TokenInterface::class);
        $token->method('getUserIdentifier')->willReturn('unknown@example.com');
        $this->tokenStorage->method('getToken')->willReturn($token);
        $this->userRepo->method('findByEmail')->willReturn(null);

        $response = $this->controller->__invoke();
        $this->assertSame(404, $response->getStatusCode());

        $data = json_decode($response->getContent(), true);
        $this->assertSame('User not found', $data['message']);
    }

    public function test_returns_200_with_secret_and_qr_uri(): void
    {
        $user = $this->createMock(User::class);
        $user->method('getEmail')->willReturn('user@example.com');
        $user->expects($this->once())->method('setTotpSecret');

        $token = $this->createMock(TokenInterface::class);
        $token->method('getUserIdentifier')->willReturn('user@example.com');
        $this->tokenStorage->method('getToken')->willReturn($token);
        $this->userRepo->method('findByEmail')->willReturn($user);
        $this->userRepo->expects($this->once())->method('save');

        $response = $this->controller->__invoke();
        $this->assertSame(200, $response->getStatusCode());

        $data = json_decode($response->getContent(), true);
        $this->assertArrayHasKey('secret', $data);
        $this->assertArrayHasKey('qr_uri', $data);
        $this->assertArrayHasKey('message', $data);

        // Secret should be base32 encoded
        $this->assertMatchesRegularExpression('/^[A-Z2-7]+$/', $data['secret']);

        // QR URI should be valid otpauth format
        $this->assertStringStartsWith('otpauth://totp/ScamBuster:', $data['qr_uri']);
        $this->assertStringContainsString('secret=', $data['qr_uri']);
        $this->assertStringContainsString('issuer=ScamBuster', $data['qr_uri']);
    }
}
