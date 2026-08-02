<?php

declare(strict_types=1);

namespace App\Tests\Unit\Application\Auth;

use App\Application\Audit\AuditLoggerInterface;
use App\Application\Auth\AuthService;
use App\Application\Auth\Dto\LoginRequestDto;
use App\Application\Auth\Dto\LoginResponseDto;
use App\Application\Auth\Dto\RefreshRequestDto;
use App\Domain\Audit\AuditEventType;
use App\Domain\User\RefreshToken;
use App\Domain\User\User;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Security\Core\Exception\AuthenticationException;

/**
 * Unit tests for AuthService (hardened refresh tokens).
 *
 * All dependencies are mocked — no DB, no JWT signing. Audit events are captured
 * per-invocation so each branch can assert the exact event type it emits.
 */
class AuthServiceTest extends TestCase
{
    private EntityManagerInterface&MockObject $em;
    private UserPasswordHasherInterface&MockObject $hasher;
    private JWTTokenManagerInterface&MockObject $jwtManager;
    private EntityRepository&MockObject $userRepo;
    private EntityRepository&MockObject $refreshTokenRepo;
    private AuditLoggerInterface&MockObject $auditLogger;
    /** @var list<AuditEventType> */
    private array $auditedEvents;

    protected function setUp(): void
    {
        $this->em = $this->createMock(EntityManagerInterface::class);
        $this->hasher = $this->createMock(UserPasswordHasherInterface::class);
        $this->jwtManager = $this->createMock(JWTTokenManagerInterface::class);
        $this->userRepo = $this->createMock(EntityRepository::class);
        $this->refreshTokenRepo = $this->createMock(EntityRepository::class);
        $this->auditLogger = $this->createMock(AuditLoggerInterface::class);
        $this->auditedEvents = [];

        $this->em->method('getRepository')->willReturnCallback(function (string $class) {
            return match ($class) {
                User::class => $this->userRepo,
                RefreshToken::class => $this->refreshTokenRepo,
                default => throw new \LogicException("Unexpected repository: {$class}"),
            };
        });

        // Capture the event type of every audit call so branches can assert exactly what they logged.
        $this->auditLogger->method('log')->willReturnCallback(function (AuditEventType $eventType): void {
            $this->auditedEvents[] = $eventType;
        });
    }

    private function buildService(): AuthService
    {
        return new AuthService($this->em, $this->hasher, $this->jwtManager, $this->auditLogger);
    }

    private function createMockUser(): User&MockObject
    {
        $user = $this->createMock(User::class);
        $user->method('getUserIdentifier')->willReturn('user@test.com');
        $user->method('getRoles')->willReturn(['ROLE_USER']);

        return $user;
    }

    // ------------------------------------------------------------------ //
    //  login
    // ------------------------------------------------------------------ //

    public function testLoginWithValidCredentialsReturnsTokens(): void
    {
        $user = $this->createMockUser();
        $this->userRepo->method('findOneBy')->with(['email' => 'user@test.com'])->willReturn($user);
        $this->hasher->method('isPasswordValid')->with($user, 'correct-password')->willReturn(true);
        $this->jwtManager->method('create')->with($user)->willReturn('jwt-access-token');

        $persisted = null;
        $this->em->expects($this->once())->method('persist')
            ->willReturnCallback(function ($entity) use (&$persisted): void { $persisted = $entity; });
        $this->em->expects($this->once())->method('flush');

        $response = $this->buildService()->login(new LoginRequestDto('user@test.com', 'correct-password'));

        $this->assertInstanceOf(LoginResponseDto::class, $response);
        $this->assertSame('jwt-access-token', $response->accessToken);
        $this->assertNotEmpty($response->refreshToken);
        $this->assertSame(900, $response->expiresIn);

        // The persisted token stores the HASH, not the raw token, and carries a family.
        $this->assertInstanceOf(RefreshToken::class, $persisted);
        $this->assertSame(RefreshToken::hash($response->refreshToken), $persisted->getTokenHash());
        $this->assertNotSame($response->refreshToken, $persisted->getTokenHash());
        $this->assertNotEmpty($persisted->getFamily());

        // Login success is audited by the Lexik listener, not by AuthService.
        $this->assertSame([], $this->auditedEvents);
    }

    public function testLoginWithInvalidPasswordThrows(): void
    {
        $user = $this->createMockUser();
        $this->userRepo->method('findOneBy')->willReturn($user);
        $this->hasher->method('isPasswordValid')->willReturn(false);

        $this->expectException(AuthenticationException::class);
        $this->expectExceptionMessage('Invalid credentials.');

        $this->buildService()->login(new LoginRequestDto('user@test.com', 'wrong-password'));
    }

    public function testLoginWithUnknownUserThrows(): void
    {
        $this->userRepo->method('findOneBy')->willReturn(null);

        $this->expectException(AuthenticationException::class);
        $this->expectExceptionMessage('Invalid credentials.');

        $this->buildService()->login(new LoginRequestDto('unknown@test.com', 'any'));
    }

    // ------------------------------------------------------------------ //
    //  refresh
    // ------------------------------------------------------------------ //

    public function testRefreshWithValidTokenRotatesWithinFamilyAndAudits(): void
    {
        $user = $this->createMockUser();

        $old = $this->createMock(RefreshToken::class);
        $old->method('isValid')->willReturn(true);
        $old->method('isExpired')->willReturn(false);
        $old->method('getUser')->willReturn($user);
        $old->method('getFamily')->willReturn('fam-live');
        $old->expects($this->once())->method('invalidate');

        $this->refreshTokenRepo->method('find')->willReturn($old);
        $this->jwtManager->method('create')->with($user)->willReturn('new-jwt-access-token');

        $persisted = null;
        $this->em->expects($this->once())->method('persist')
            ->willReturnCallback(function ($entity) use (&$persisted): void { $persisted = $entity; });
        $this->em->expects($this->once())->method('flush');

        $response = $this->buildService()->refresh(new RefreshRequestDto('valid-refresh-token'));

        $this->assertSame('new-jwt-access-token', $response->accessToken);
        $this->assertNotEmpty($response->refreshToken);

        // Rotated token inherits the family and is stored hashed.
        $this->assertInstanceOf(RefreshToken::class, $persisted);
        $this->assertSame('fam-live', $persisted->getFamily());
        $this->assertSame(RefreshToken::hash($response->refreshToken), $persisted->getTokenHash());

        $this->assertSame([AuditEventType::AUTH_TOKEN_REFRESHED], $this->auditedEvents);
    }

    public function testRefreshLooksUpTokenByHashNotRawValue(): void
    {
        $user = $this->createMockUser();
        $old = $this->createMock(RefreshToken::class);
        $old->method('isValid')->willReturn(true);
        $old->method('isExpired')->willReturn(false);
        $old->method('getUser')->willReturn($user);
        $old->method('getFamily')->willReturn('fam');
        $this->jwtManager->method('create')->willReturn('jwt');

        // The service must look the token up by its SHA-256, never by the raw value.
        $this->refreshTokenRepo->expects($this->once())->method('find')
            ->with(RefreshToken::hash('raw-secret'))
            ->willReturn($old);

        $this->buildService()->refresh(new RefreshRequestDto('raw-secret'));
    }

    public function testRefreshWithReusedTokenRevokesFamilyAndAudits(): void
    {
        $user = $this->createMockUser();

        // Presented token is already invalid → replay of a rotated token → theft signal.
        $reused = $this->createMock(RefreshToken::class);
        $reused->method('isValid')->willReturn(false);
        $reused->method('getUser')->willReturn($user);
        $reused->method('getFamily')->willReturn('fam-compromised');

        // The still-live tip of the family must be invalidated by the revoke.
        $liveTip = $this->createMock(RefreshToken::class);
        $liveTip->expects($this->once())->method('invalidate');

        $this->refreshTokenRepo->method('find')->willReturn($reused);
        $this->refreshTokenRepo->method('findBy')
            ->with(['family' => 'fam-compromised', 'valid' => true])
            ->willReturn([$liveTip]);

        // No new token is issued on the denial path.
        $this->em->expects($this->never())->method('persist');
        $this->em->expects($this->once())->method('flush');

        try {
            $this->buildService()->refresh(new RefreshRequestDto('stolen-token'));
            $this->fail('Expected AuthenticationException');
        } catch (AuthenticationException $e) {
            $this->assertSame('Invalid refresh token', $e->getMessage());
        }

        $this->assertSame([AuditEventType::AUTH_TOKEN_REUSE_DETECTED], $this->auditedEvents);
    }

    public function testRefreshWithExpiredTokenAuditsFailure(): void
    {
        $user = $this->createMockUser();
        $expired = $this->createMock(RefreshToken::class);
        $expired->method('isValid')->willReturn(true);
        $expired->method('isExpired')->willReturn(true);
        $expired->method('getUser')->willReturn($user);
        $this->refreshTokenRepo->method('find')->willReturn($expired);

        try {
            $this->buildService()->refresh(new RefreshRequestDto('expired-token'));
            $this->fail('Expected AuthenticationException');
        } catch (AuthenticationException) {
        }

        $this->assertSame([AuditEventType::AUTH_FAILURE], $this->auditedEvents);
    }

    public function testRefreshWithNonexistentTokenAuditsFailure(): void
    {
        $this->refreshTokenRepo->method('find')->willReturn(null);

        try {
            $this->buildService()->refresh(new RefreshRequestDto('nonexistent-token'));
            $this->fail('Expected AuthenticationException');
        } catch (AuthenticationException) {
        }

        $this->assertSame([AuditEventType::AUTH_FAILURE], $this->auditedEvents);
    }

    // ------------------------------------------------------------------ //
    //  logout
    // ------------------------------------------------------------------ //

    public function testLogoutInvalidatesTokenAndAudits(): void
    {
        $user = $this->createMockUser();
        $refreshToken = $this->createMock(RefreshToken::class);
        $refreshToken->method('isValid')->willReturn(true);
        $refreshToken->method('getUser')->willReturn($user);
        $refreshToken->method('getFamily')->willReturn('fam');
        $refreshToken->expects($this->once())->method('invalidate');

        $this->refreshTokenRepo->method('find')->willReturn($refreshToken);
        $this->em->expects($this->once())->method('flush');

        $this->buildService()->logout('valid-token');

        $this->assertSame([AuditEventType::AUTH_LOGOUT], $this->auditedEvents);
    }

    public function testLogoutLooksUpTokenByHash(): void
    {
        $this->refreshTokenRepo->expects($this->once())->method('find')
            ->with(RefreshToken::hash('raw-logout'))
            ->willReturn(null);

        $this->buildService()->logout('raw-logout');
    }

    public function testLogoutWithNonexistentTokenDoesNothing(): void
    {
        $this->refreshTokenRepo->method('find')->willReturn(null);
        $this->em->expects($this->never())->method('flush');

        $this->buildService()->logout('nonexistent');

        $this->assertSame([], $this->auditedEvents);
    }

    public function testLogoutWithAlreadyInvalidatedTokenDoesNothing(): void
    {
        $refreshToken = $this->createMock(RefreshToken::class);
        $refreshToken->method('isValid')->willReturn(false);
        $refreshToken->expects($this->never())->method('invalidate');

        $this->refreshTokenRepo->method('find')->willReturn($refreshToken);
        $this->em->expects($this->never())->method('flush');

        $this->buildService()->logout('already-invalidated');

        $this->assertSame([], $this->auditedEvents);
    }
}
