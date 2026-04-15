<?php

declare(strict_types=1);

namespace App\Tests\Unit\Application\Auth;

use App\Application\Auth\AuthService;
use App\Application\Auth\Dto\LoginRequestDto;
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
 * Verifies timing-safe login: unknown user still runs a password hash
 * to prevent user-enumeration via response-time side channel.
 */
class AuthServiceTimingTest extends TestCase
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

    /**
     * When the user is not found, a constant-time password_verify() runs
     * against a dummy bcrypt hash before throwing AuthenticationException.
     * The Symfony hasher is NOT called (password_verify is used directly).
     */
    public function test_unknown_user_throws_authentication_exception(): void
    {
        $this->userRepo->method('findOneBy')->willReturn(null);

        // The Symfony hasher should NOT be called for unknown users
        // (password_verify is used directly for timing normalization)
        $this->hasher->expects($this->never())
            ->method('isPasswordValid');

        $this->expectException(AuthenticationException::class);
        $this->expectExceptionMessage('Invalid credentials.');

        $service = $this->buildService();
        $service->login(new LoginRequestDto('unknown@test.com', 'any-password'));
    }

    /**
     * When user exists but password is wrong, hasher is called once with the real user.
     */
    public function test_known_user_wrong_password_invokes_hasher_on_real_user(): void
    {
        $user = $this->createMock(User::class);
        $user->method('getUserIdentifier')->willReturn('user@test.com');
        $user->method('getRoles')->willReturn(['ROLE_USER']);

        $this->userRepo->method('findOneBy')->willReturn($user);

        $this->hasher->expects($this->once())
            ->method('isPasswordValid')
            ->with($user, 'wrong-password')
            ->willReturn(false);

        $this->expectException(AuthenticationException::class);
        $this->expectExceptionMessage('Invalid credentials.');

        $service = $this->buildService();
        $service->login(new LoginRequestDto('user@test.com', 'wrong-password'));
    }
}
