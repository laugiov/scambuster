<?php

declare(strict_types=1);

namespace App\Application\Auth;

use App\Application\Auth\Dto\LoginRequestDto;
use App\Application\Auth\Dto\LoginResponseDto;
use App\Application\Auth\Dto\RefreshRequestDto;
use App\Domain\User\RefreshToken;
use App\Domain\User\User;
use Doctrine\ORM\EntityManagerInterface;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Security\Core\Exception\AuthenticationException;

class AuthService implements AuthServiceInterface
{
    /** @var \Doctrine\ORM\EntityRepository<RefreshToken> */
    private readonly \Doctrine\ORM\EntityRepository $refreshTokenRepository;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly UserPasswordHasherInterface $hasher,
        private readonly JWTTokenManagerInterface $jwtManager
    ) {
        $this->refreshTokenRepository = $this->em->getRepository(RefreshToken::class);
    }

    public function login(LoginRequestDto $dto): LoginResponseDto
    {
        $user = $this->em->getRepository(User::class)->findOneBy(['email' => $dto->email]);

        if (!$user) {
            // Constant-time: run bcrypt to prevent timing-based user enumeration
            // Uses password_hash() directly to avoid framework dependency issues
            password_verify($dto->password, '$2y$13$dummySaltForTimingNormal.eDummyHashToNormalizeTimingAttacks00');

            throw new AuthenticationException('Invalid credentials.');
        }

        if (!$this->hasher->isPasswordValid($user, $dto->password)) {
            throw new AuthenticationException('Invalid credentials.');
        }

        $accessToken = $this->jwtManager->create($user);

        // Generate a robust refresh token
        $refreshTokenString = bin2hex(random_bytes(64));
        $expiresAt = (new \DateTimeImmutable())->modify('+30 days');
        $refreshToken = new RefreshToken($refreshTokenString, $user, $expiresAt);
        $this->em->persist($refreshToken);
        $this->em->flush();

        $expiresIn = 900;

        return new LoginResponseDto($accessToken, $refreshTokenString, $expiresIn);
    }

    public function refresh(RefreshRequestDto $dto): LoginResponseDto
    {
        $refreshToken = $this->refreshTokenRepository->find($dto->refreshToken);

        if (
            !$refreshToken ||
            !$refreshToken->isValid() ||
            $refreshToken->isExpired()
        ) {
            throw new AuthenticationException('Invalid refresh token');
        }

        $user = $refreshToken->getUser();
        $accessToken = $this->jwtManager->create($user);

        //token rotation
        $refreshToken->invalidate();
        $newRefreshTokenString = bin2hex(random_bytes(64));
        $expiresAt = (new \DateTimeImmutable())->modify('+30 days');
        $newRefreshToken = new RefreshToken($newRefreshTokenString, $user, $expiresAt);
        $this->em->persist($newRefreshToken);
        $this->em->flush();

        $expiresIn = 900;

        return new LoginResponseDto($accessToken, $newRefreshTokenString, $expiresIn);
    }

    public function logout(string $refreshToken): void
    {
        $token = $this->refreshTokenRepository->find($refreshToken);

        if ($token && $token->isValid()) {
            $token->invalidate();
            $this->em->flush();
        }
    }
}
