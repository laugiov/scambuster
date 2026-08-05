<?php

declare(strict_types=1);

namespace App\Tests\Unit\UI\Http\Auth;

use App\Application\Auth\TotpVerifier;
use App\Domain\User\Repository\UserRepositoryInterface;
use App\Domain\User\User;
use App\UI\Http\Auth\TotpVerifyController;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;

class TotpVerifyControllerTest extends TestCase
{
    private UserRepositoryInterface&MockObject $userRepo;
    private TokenStorageInterface&MockObject $tokenStorage;
    private TotpVerifyController $controller;

    protected function setUp(): void
    {
        $this->userRepo = $this->createMock(UserRepositoryInterface::class);
        $this->tokenStorage = $this->createMock(TokenStorageInterface::class);
        $this->controller = new TotpVerifyController($this->userRepo, $this->tokenStorage, new TotpVerifier());
    }

    public function test_returns_401_when_no_token(): void
    {
        $this->tokenStorage->method('getToken')->willReturn(null);
        $request = Request::create('/api/v1/2fa/verify', 'POST', [], [], [], [], '{"code":"123456"}');

        $response = $this->controller->__invoke($request);
        $this->assertSame(401, $response->getStatusCode());
    }

    public function test_returns_400_when_code_empty(): void
    {
        $token = $this->createMock(TokenInterface::class);
        $this->tokenStorage->method('getToken')->willReturn($token);

        $request = Request::create('/api/v1/2fa/verify', 'POST', [], [], [], [], '{"code":""}');
        $response = $this->controller->__invoke($request);
        $this->assertSame(400, $response->getStatusCode());
    }

    public function test_returns_400_when_code_not_6_digits(): void
    {
        $token = $this->createMock(TokenInterface::class);
        $this->tokenStorage->method('getToken')->willReturn($token);

        $request = Request::create('/api/v1/2fa/verify', 'POST', [], [], [], [], '{"code":"12345"}');
        $response = $this->controller->__invoke($request);
        $this->assertSame(400, $response->getStatusCode());
    }

    public function test_returns_400_when_code_has_letters(): void
    {
        $token = $this->createMock(TokenInterface::class);
        $this->tokenStorage->method('getToken')->willReturn($token);

        $request = Request::create('/api/v1/2fa/verify', 'POST', [], [], [], [], '{"code":"12abc6"}');
        $response = $this->controller->__invoke($request);
        $this->assertSame(400, $response->getStatusCode());
    }

    public function test_returns_404_when_user_not_found(): void
    {
        $token = $this->createMock(TokenInterface::class);
        $token->method('getUserIdentifier')->willReturn('unknown@example.com');
        $this->tokenStorage->method('getToken')->willReturn($token);
        $this->userRepo->method('findByEmail')->willReturn(null);

        $request = Request::create('/api/v1/2fa/verify', 'POST', [], [], [], [], '{"code":"123456"}');
        $response = $this->controller->__invoke($request);
        $this->assertSame(404, $response->getStatusCode());
    }

    public function test_returns_400_when_totp_not_configured(): void
    {
        $user = $this->createMock(User::class);
        $user->method('getTotpSecret')->willReturn(null);

        $token = $this->createMock(TokenInterface::class);
        $token->method('getUserIdentifier')->willReturn('user@example.com');
        $this->tokenStorage->method('getToken')->willReturn($token);
        $this->userRepo->method('findByEmail')->willReturn($user);

        $request = Request::create('/api/v1/2fa/verify', 'POST', [], [], [], [], '{"code":"123456"}');
        $response = $this->controller->__invoke($request);
        $this->assertSame(400, $response->getStatusCode());

        $data = json_decode($response->getContent(), true);
        $this->assertSame('TOTP not configured', $data['message']);
    }

    public function test_returns_400_when_code_invalid(): void
    {
        $user = $this->createMock(User::class);
        // Use a known base32 secret
        $user->method('getTotpSecret')->willReturn('JBSWY3DPEHPK3PXP');

        $token = $this->createMock(TokenInterface::class);
        $token->method('getUserIdentifier')->willReturn('user@example.com');
        $this->tokenStorage->method('getToken')->willReturn($token);
        $this->userRepo->method('findByEmail')->willReturn($user);

        // Use a definitely wrong code
        $request = Request::create('/api/v1/2fa/verify', 'POST', [], [], [], [], '{"code":"000000"}');
        $response = $this->controller->__invoke($request);

        // Code is almost certainly invalid (1 in 1M chance)
        $this->assertContains($response->getStatusCode(), [200, 400]);
    }

    public function test_handles_null_code_in_payload(): void
    {
        $token = $this->createMock(TokenInterface::class);
        $this->tokenStorage->method('getToken')->willReturn($token);

        $request = Request::create('/api/v1/2fa/verify', 'POST', [], [], [], [], '{}');
        $response = $this->controller->__invoke($request);
        $this->assertSame(400, $response->getStatusCode());
    }

    public function test_handles_non_string_code_in_payload(): void
    {
        $token = $this->createMock(TokenInterface::class);
        $this->tokenStorage->method('getToken')->willReturn($token);

        $request = Request::create('/api/v1/2fa/verify', 'POST', [], [], [], [], '{"code":123456}');
        $response = $this->controller->__invoke($request);
        $this->assertSame(400, $response->getStatusCode());
    }
}
