<?php

declare(strict_types=1);

namespace App\Tests\Unit\Application\Auth;

use App\Application\Auth\AuthService;
use App\Application\Auth\Dto\LoginRequestDto;
use App\Application\Auth\Dto\LoginResponseDto;
use App\Application\Auth\Dto\RefreshRequestDto;
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
 * Unit tests for AuthService.
 *
 * All dependencies are mocked — no DB, no JWT signing.
 */
class AuthServiceTest extends TestCase
{
    private EntityManagerInterface&MockObject $em;
    private UserPasswordHasherInterface&MockObject $hasher;
    private JWTTokenManagerInterface&MockObject $jwtManager;
    private EntityRepository&MockObject $userRepo;
    private EntityRepository&MockObject $refreshTokenRepo;

    protected function setUp(): void
    {
        $this->em = $this->createMock(EntityManagerInterface::class);
        $this->hasher = $this->createMock(UserPasswordHasherInterface::class);
        $this->jwtManager = $this->createMock(JWTTokenManagerInterface::class);
        $this->userRepo = $this->createMock(EntityRepository::class);
        $this->refreshTokenRepo = $this->createMock(EntityRepository::class);

        // getRepository must return the correct repository per class
        $this->em->method('getRepository')->willReturnCallback(function (string $class) {
            return match ($class) {
                User::class => $this->userRepo,
                RefreshToken::class => $this->refreshTokenRepo,
                default => throw new \LogicException("Unexpected repository: {$class}"),
            };
        });
    }

    private function buildService(): AuthService
    {
        return new AuthService($this->em, $this->hasher, $this->jwtManager);
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

        $this->userRepo->method('findOneBy')
            ->with(['email' => 'user@test.com'])
            ->willReturn($user);

        $this->hasher->method('isPasswordValid')
            ->with($user, 'correct-password')
            ->willReturn(true);

        $this->jwtManager->method('create')
            ->with($user)
            ->willReturn('jwt-access-token');

        $this->em->expects($this->once())->method('persist');
        $this->em->expects($this->once())->method('flush');

        $service = $this->buildService();
        $dto = new LoginRequestDto('user@test.com', 'correct-password');
        $response = $service->login($dto);

        $this->assertInstanceOf(LoginResponseDto::class, $response);
        $this->assertSame('jwt-access-token', $response->accessToken);
        $this->assertNotEmpty($response->refreshToken);
        $this->assertSame(900, $response->expiresIn);
    }

    public function testLoginWithInvalidPasswordThrows(): void
    {
        $user = $this->createMockUser();

        $this->userRepo->method('findOneBy')->willReturn($user);
        $this->hasher->method('isPasswordValid')->willReturn(false);

        $this->expectException(AuthenticationException::class);
        $this->expectExceptionMessage('Invalid credentials.');

        $service = $this->buildService();
        $service->login(new LoginRequestDto('user@test.com', 'wrong-password'));
    }

    public function testLoginWithUnknownUserThrows(): void
    {
        $this->userRepo->method('findOneBy')->willReturn(null);

        $this->expectException(AuthenticationException::class);
        $this->expectExceptionMessage('Invalid credentials.');

        $service = $this->buildService();
        $service->login(new LoginRequestDto('unknown@test.com', 'any'));
    }

    // ------------------------------------------------------------------ //
    //  refresh
    // ------------------------------------------------------------------ //

    public function testRefreshWithValidTokenReturnsNewTokens(): void
    {
        $user = $this->createMockUser();

        $refreshToken = $this->createMock(RefreshToken::class);
        $refreshToken->method('isValid')->willReturn(true);
        $refreshToken->method('isExpired')->willReturn(false);
        $refreshToken->method('getUser')->willReturn($user);
        $refreshToken->expects($this->once())->method('invalidate');

        $this->refreshTokenRepo->method('find')
            ->with('valid-refresh-token')
            ->willReturn($refreshToken);

        $this->jwtManager->method('create')
            ->with($user)
            ->willReturn('new-jwt-access-token');

        $this->em->expects($this->once())->method('persist');
        $this->em->expects($this->once())->method('flush');

        $service = $this->buildService();
        $response = $service->refresh(new RefreshRequestDto('valid-refresh-token'));

        $this->assertInstanceOf(LoginResponseDto::class, $response);
        $this->assertSame('new-jwt-access-token', $response->accessToken);
        $this->assertNotEmpty($response->refreshToken);
    }

    public function testRefreshWithInvalidTokenThrows(): void
    {
        $refreshToken = $this->createMock(RefreshToken::class);
        $refreshToken->method('isValid')->willReturn(false);
        $refreshToken->method('isExpired')->willReturn(false);

        $this->refreshTokenRepo->method('find')->willReturn($refreshToken);

        $this->expectException(AuthenticationException::class);
        $this->expectExceptionMessage('Invalid refresh token');

        $service = $this->buildService();
        $service->refresh(new RefreshRequestDto('invalid-token'));
    }

    public function testRefreshWithExpiredTokenThrows(): void
    {
        $refreshToken = $this->createMock(RefreshToken::class);
        $refreshToken->method('isValid')->willReturn(true);
        $refreshToken->method('isExpired')->willReturn(true);

        $this->refreshTokenRepo->method('find')->willReturn($refreshToken);

        $this->expectException(AuthenticationException::class);

        $service = $this->buildService();
        $service->refresh(new RefreshRequestDto('expired-token'));
    }

    public function testRefreshWithNonexistentTokenThrows(): void
    {
        $this->refreshTokenRepo->method('find')->willReturn(null);

        $this->expectException(AuthenticationException::class);

        $service = $this->buildService();
        $service->refresh(new RefreshRequestDto('nonexistent-token'));
    }

    // ------------------------------------------------------------------ //
    //  logout
    // ------------------------------------------------------------------ //

    public function testLogoutInvalidatesToken(): void
    {
        $refreshToken = $this->createMock(RefreshToken::class);
        $refreshToken->method('isValid')->willReturn(true);
        $refreshToken->expects($this->once())->method('invalidate');

        $this->refreshTokenRepo->method('find')->willReturn($refreshToken);

        $this->em->expects($this->once())->method('flush');

        $service = $this->buildService();
        $service->logout('valid-token');
    }

    public function testLogoutWithNonexistentTokenDoesNothing(): void
    {
        $this->refreshTokenRepo->method('find')->willReturn(null);

        $this->em->expects($this->never())->method('flush');

        $service = $this->buildService();
        $service->logout('nonexistent');
    }

    public function testLogoutWithAlreadyInvalidatedTokenDoesNothing(): void
    {
        $refreshToken = $this->createMock(RefreshToken::class);
        $refreshToken->method('isValid')->willReturn(false);
        $refreshToken->expects($this->never())->method('invalidate');

        $this->refreshTokenRepo->method('find')->willReturn($refreshToken);

        $service = $this->buildService();
        $service->logout('already-invalidated');
    }
}
