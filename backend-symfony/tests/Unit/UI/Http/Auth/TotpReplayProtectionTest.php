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
use Psr\Cache\CacheItemInterface;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Log\NullLogger;
use Scheb\TwoFactorBundle\Security\TwoFactor\Provider\Totp\TotpAuthenticatorInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Validator\ConstraintViolationList;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * Verifies TOTP replay protection: a code used successfully once
 * is rejected on the second attempt within the 90s window.
 */
class TotpReplayProtectionTest extends TestCase
{
    private AuthServiceInterface&MockObject $authService;
    private AuditLogger $auditLogger;
    private UserRepositoryInterface&MockObject $userRepo;
    private ValidatorInterface&MockObject $validator;
    private TotpAuthenticatorInterface&MockObject $totpAuth;
    private CacheItemPoolInterface&MockObject $cache;

    protected function setUp(): void
    {
        $this->authService = $this->createMock(AuthServiceInterface::class);
        $this->userRepo = $this->createMock(UserRepositoryInterface::class);
        $this->validator = $this->createMock(ValidatorInterface::class);
        $this->totpAuth = $this->createMock(TotpAuthenticatorInterface::class);
        $this->cache = $this->createMock(CacheItemPoolInterface::class);

        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('getConnection')->willReturn($this->createMock(\Doctrine\DBAL\Connection::class));
        $siem = $this->createMock(SiemExporterInterface::class);
        $this->auditLogger = new AuditLogger($em, new NullLogger(), new RequestStack(), $siem);

        $this->validator->method('validate')->willReturn(new ConstraintViolationList());
    }

    private function buildController(): TotpLoginController
    {
        return new TotpLoginController(
            $this->authService,
            $this->auditLogger,
            $this->userRepo,
            $this->validator,
            new TotpVerifier(),  // totpVerifier
            $this->totpAuth,
            null,           // loginIpLimiter
            $this->cache,   // totpReplayCache
        );
    }

    private function makeRequest(): Request
    {
        return Request::create('/api/v1/auth/2fa/login', 'POST', [], [], [], [], json_encode([
            'email' => 'user@test.com',
            'password' => 'correct-pass',
            'code' => '123456',
        ]));
    }

    private function setupSuccessfulAuth(): void
    {
        $this->authService->method('login')
            ->willReturn(new LoginResponseDto('jwt-token', 'refresh-token', 3600));

        $user = $this->createMock(User::class);
        $user->method('isTotpEnabled')->willReturn(true);
        $this->userRepo->method('findByEmail')->willReturn($user);

        $this->totpAuth->method('checkCode')->willReturn(true);
    }

    public function test_first_use_of_totp_code_succeeds(): void
    {
        $this->setupSuccessfulAuth();

        // Cache miss — code not yet used
        $cacheItem = $this->createMock(CacheItemInterface::class);
        $cacheItem->method('isHit')->willReturn(false);
        $cacheItem->expects($this->once())->method('set')->with(true);
        $cacheItem->expects($this->once())->method('expiresAfter')->with(90);

        $this->cache->method('getItem')->willReturn($cacheItem);
        $this->cache->expects($this->once())->method('save')->with($cacheItem);

        $controller = $this->buildController();
        $response = $controller->__invoke($this->makeRequest());

        $this->assertSame(200, $response->getStatusCode());
        $data = json_decode($response->getContent(), true);
        $this->assertArrayHasKey('access_token', $data);
    }

    public function test_replayed_totp_code_is_rejected(): void
    {
        $this->setupSuccessfulAuth();

        // Cache hit — code already used
        $cacheItem = $this->createMock(CacheItemInterface::class);
        $cacheItem->method('isHit')->willReturn(true);

        $this->cache->method('getItem')->willReturn($cacheItem);

        $controller = $this->buildController();
        $response = $controller->__invoke($this->makeRequest());

        $this->assertSame(401, $response->getStatusCode());
        $data = json_decode($response->getContent(), true);
        $this->assertSame('TOTP code already used', $data['message']);
    }

    public function test_replay_protection_disabled_when_no_cache(): void
    {
        $this->setupSuccessfulAuth();

        // No cache injected — replay protection is simply skipped
        $controller = new TotpLoginController(
            $this->authService,
            $this->auditLogger,
            $this->userRepo,
            $this->validator,
            new TotpVerifier(),  // totpVerifier
            $this->totpAuth,
            null,  // loginIpLimiter
            null,  // totpReplayCache — disabled
        );

        $response = $controller->__invoke($this->makeRequest());

        $this->assertSame(200, $response->getStatusCode());
    }
}
