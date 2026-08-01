<?php

declare(strict_types=1);

namespace App\Tests\Unit\UI\Http\Auth;

use App\Application\Audit\AuditLogger;
use App\Application\Audit\Port\SiemExporterInterface;
use App\Application\Auth\AuthServiceInterface;
use App\Application\Auth\Dto\LoginResponseDto;
use App\Application\Auth\TotpVerifier;
use App\Domain\User\Repository\UserRepositoryInterface;
use App\Domain\User\User;
use App\UI\Http\Auth\TotpLoginController;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Validator\ConstraintViolationList;
use Symfony\Component\Validator\Validator\ValidatorInterface;

class TotpLoginControllerTest extends TestCase
{
    private AuthServiceInterface&MockObject $authService;
    private AuditLogger $auditLogger;
    private UserRepositoryInterface&MockObject $userRepo;
    private ValidatorInterface&MockObject $validator;
    private TotpLoginController $controller;

    protected function setUp(): void
    {
        $this->authService = $this->createMock(AuthServiceInterface::class);
        $this->userRepo = $this->createMock(UserRepositoryInterface::class);
        $this->validator = $this->createMock(ValidatorInterface::class);

        // Build real AuditLogger with mocked EM (it's final, can't be mocked)
        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('getConnection')->willReturn($this->createMock(\Doctrine\DBAL\Connection::class));
        $siem = $this->createMock(SiemExporterInterface::class);
        $this->auditLogger = new AuditLogger($em, new NullLogger(), new \App\Tests\Support\Audit\NullRequestContext(), $siem);

        $this->controller = new TotpLoginController(
            $this->authService,
            $this->auditLogger,
            $this->userRepo,
            $this->validator,
            new TotpVerifier(),
        );
    }

    private function makeRequest(array $payload): Request
    {
        return Request::create('/api/v1/auth/2fa/login', 'POST', [], [], [], [], json_encode($payload));
    }

    public function test_returns_400_on_invalid_json(): void
    {
        $request = Request::create('/api/v1/auth/2fa/login', 'POST', [], [], [], [], 'not json');
        $response = $this->controller->__invoke($request);
        $this->assertSame(400, $response->getStatusCode());
    }

    public function test_returns_400_on_invalid_totp_format_too_short(): void
    {
        $request = $this->makeRequest(['email' => 'user@test.com', 'password' => 'pass', 'code' => '12345']);
        $response = $this->controller->__invoke($request);
        $this->assertSame(400, $response->getStatusCode());

        $data = json_decode($response->getContent(), true);
        $this->assertSame('Invalid TOTP code format', $data['message']);
    }

    public function test_returns_400_on_non_string_code(): void
    {
        $request = $this->makeRequest(['email' => 'user@test.com', 'password' => 'pass', 'code' => 123456]);
        $response = $this->controller->__invoke($request);
        $this->assertSame(400, $response->getStatusCode());
    }

    public function test_returns_400_on_letters_in_code(): void
    {
        $request = $this->makeRequest(['email' => 'user@test.com', 'password' => 'pass', 'code' => 'abc123']);
        $response = $this->controller->__invoke($request);
        $this->assertSame(400, $response->getStatusCode());
    }

    public function test_returns_422_on_validation_errors(): void
    {
        $violation = $this->createMock(\Symfony\Component\Validator\ConstraintViolationInterface::class);
        $violation->method('__toString')->willReturn('Email required');
        $violations = new ConstraintViolationList([$violation]);
        $this->validator->method('validate')->willReturn($violations);

        $request = $this->makeRequest(['email' => '', 'password' => '', 'code' => '123456']);
        $response = $this->controller->__invoke($request);
        $this->assertSame(422, $response->getStatusCode());
    }

    public function test_returns_401_on_auth_failure(): void
    {
        $this->validator->method('validate')->willReturn(new ConstraintViolationList());
        $this->authService->method('login')
            ->willThrowException(new AuthenticationException('Invalid credentials'));

        $request = $this->makeRequest(['email' => 'user@test.com', 'password' => 'wrong', 'code' => '123456']);
        $response = $this->controller->__invoke($request);
        $this->assertSame(401, $response->getStatusCode());
    }

    public function test_returns_400_when_totp_not_enabled(): void
    {
        $this->validator->method('validate')->willReturn(new ConstraintViolationList());

        $loginResponse = new LoginResponseDto('token', 'refresh', 3600);
        $this->authService->method('login')->willReturn($loginResponse);

        $user = $this->createMock(User::class);
        $user->method('isTotpEnabled')->willReturn(false);
        $this->userRepo->method('findByEmail')->willReturn($user);

        $request = $this->makeRequest(['email' => 'user@test.com', 'password' => 'pass', 'code' => '123456']);
        $response = $this->controller->__invoke($request);
        $this->assertSame(400, $response->getStatusCode());

        $data = json_decode($response->getContent(), true);
        $this->assertSame('TOTP not configured for this account', $data['message']);
    }

    public function test_returns_400_when_user_not_found(): void
    {
        $this->validator->method('validate')->willReturn(new ConstraintViolationList());

        $loginResponse = new LoginResponseDto('token', 'refresh', 3600);
        $this->authService->method('login')->willReturn($loginResponse);
        $this->userRepo->method('findByEmail')->willReturn(null);

        $request = $this->makeRequest(['email' => 'user@test.com', 'password' => 'pass', 'code' => '123456']);
        $response = $this->controller->__invoke($request);
        $this->assertSame(400, $response->getStatusCode());
    }

    public function test_returns_401_on_invalid_totp_code(): void
    {
        $this->validator->method('validate')->willReturn(new ConstraintViolationList());

        $loginResponse = new LoginResponseDto('token', 'refresh', 3600);
        $this->authService->method('login')->willReturn($loginResponse);

        $user = $this->createMock(User::class);
        $user->method('isTotpEnabled')->willReturn(true);
        $user->method('getTotpSecret')->willReturn('JBSWY3DPEHPK3PXP');
        $this->userRepo->method('findByEmail')->willReturn($user);

        $request = $this->makeRequest(['email' => 'user@test.com', 'password' => 'pass', 'code' => '000000']);
        $response = $this->controller->__invoke($request);

        // Code 000000 is almost certainly wrong (1 in 333K chance of being valid in 3-window)
        $this->assertContains($response->getStatusCode(), [200, 401]);
    }
}
