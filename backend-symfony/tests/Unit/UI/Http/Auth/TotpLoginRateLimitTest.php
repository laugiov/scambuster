<?php

declare(strict_types=1);

namespace App\Tests\Unit\UI\Http\Auth;

use App\Application\Audit\AuditLogger;
use App\Application\Audit\Port\SiemExporterInterface;
use App\Application\Auth\AuthServiceInterface;
use App\Application\Auth\TotpVerifier;
use App\Domain\User\Repository\UserRepositoryInterface;
use App\UI\Http\Auth\TotpLoginController;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\RateLimiter\Storage\InMemoryStorage;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Validator\ConstraintViolationList;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * Verifies that TotpLoginController returns 429 when the rate limiter rejects the request.
 */
class TotpLoginRateLimitTest extends TestCase
{
    private AuthServiceInterface&MockObject $authService;
    private AuditLogger $auditLogger;
    private UserRepositoryInterface&MockObject $userRepo;
    private ValidatorInterface&MockObject $validator;

    protected function setUp(): void
    {
        $this->authService = $this->createMock(AuthServiceInterface::class);
        $this->userRepo = $this->createMock(UserRepositoryInterface::class);
        $this->validator = $this->createMock(ValidatorInterface::class);

        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('getConnection')->willReturn($this->createMock(\Doctrine\DBAL\Connection::class));
        $siem = $this->createMock(SiemExporterInterface::class);
        $this->auditLogger = new AuditLogger($em, new NullLogger(), new RequestStack(), $siem);
    }

    /**
     * Build a real RateLimiterFactory with sliding_window policy and in-memory storage.
     */
    private function buildLimiterFactory(int $limit, string $interval): RateLimiterFactory
    {
        return new RateLimiterFactory(
            ['id' => 'test_login_ip', 'policy' => 'sliding_window', 'limit' => $limit, 'interval' => $interval],
            new InMemoryStorage()
        );
    }

    public function test_returns_429_when_rate_limit_exceeded(): void
    {
        // Allow only 2 requests per minute
        $factory = $this->buildLimiterFactory(2, '1 minute');

        // Auth service rejects credentials so the flow stops at 401 (not needing final DTO)
        $this->authService->method('login')
            ->willThrowException(new AuthenticationException('Invalid credentials.'));
        $this->validator->method('validate')->willReturn(new ConstraintViolationList());

        $controller = new TotpLoginController(
            $this->authService,
            $this->auditLogger,
            $this->userRepo,
            $this->validator,
            new TotpVerifier(),  // totpVerifier
            null,           // totpAuthenticator
            $factory,       // loginIpLimiter
            null,           // totpReplayCache
        );

        $makeRequest = static fn (): Request => Request::create(
            '/api/v1/auth/2fa/login',
            'POST',
            [],
            [],
            [],
            ['REMOTE_ADDR' => '10.0.0.1'],
            json_encode(['email' => 'user@test.com', 'password' => 'pass', 'code' => '123456'])
        );

        // First 2 requests pass rate limiting (hit 401 from auth failure)
        $r1 = $controller->__invoke($makeRequest());
        $this->assertSame(401, $r1->getStatusCode(), 'Request 1 should not be rate-limited');

        $r2 = $controller->__invoke($makeRequest());
        $this->assertSame(401, $r2->getStatusCode(), 'Request 2 should not be rate-limited');

        // Third request should be rate-limited (429 before auth is even attempted)
        $r3 = $controller->__invoke($makeRequest());
        $this->assertSame(429, $r3->getStatusCode(), 'Request 3 should be rate-limited');

        $data = json_decode($r3->getContent(), true);
        $this->assertArrayHasKey('retry_after', $data);
        $this->assertGreaterThanOrEqual(1, $data['retry_after']);
    }

    public function test_proceeds_normally_when_no_limiter_injected(): void
    {
        $controller = new TotpLoginController(
            $this->authService,
            $this->auditLogger,
            $this->userRepo,
            $this->validator,
            new TotpVerifier(),  // totpVerifier
            null,  // totpAuthenticator
            null,  // loginIpLimiter — not injected
            null,  // totpReplayCache
        );

        // Send invalid JSON to get a 400 (proves rate limit didn't block)
        $request = Request::create('/api/v1/auth/2fa/login', 'POST', [], [], [], [], 'not json');

        $response = $controller->__invoke($request);

        $this->assertSame(400, $response->getStatusCode());
    }
}
