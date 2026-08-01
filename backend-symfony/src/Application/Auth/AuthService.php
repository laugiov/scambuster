<?php

declare(strict_types=1);

namespace App\Application\Auth;

use App\Application\Audit\AuditLoggerInterface;
use App\Application\Auth\Dto\LoginRequestDto;
use App\Application\Auth\Dto\LoginResponseDto;
use App\Application\Auth\Dto\RefreshRequestDto;
use App\Domain\Audit\AuditEventType;
use App\Domain\User\RefreshToken;
use App\Domain\User\User;
use Doctrine\ORM\EntityManagerInterface;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Uid\Uuid;

/**
 * Authentication + refresh-token lifecycle. The refresh tokens are hardened:
 * - refresh tokens are SHA-256 at rest (never plaintext);
 * - rotation is family-scoped, and replaying a rotated token revokes the whole family
 *   (refresh-token reuse detection);
 * - refresh success / failure / reuse / logout are all audited.
 */
class AuthService implements AuthServiceInterface
{
    private const REFRESH_TTL = '+30 days';
    private const ACCESS_TTL_SECONDS = 900;

    /** @var \Doctrine\ORM\EntityRepository<RefreshToken> */
    private readonly \Doctrine\ORM\EntityRepository $refreshTokenRepository;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly UserPasswordHasherInterface $hasher,
        private readonly JWTTokenManagerInterface $jwtManager,
        private readonly AuditLoggerInterface $auditLogger,
    ) {
        $this->refreshTokenRepository = $this->em->getRepository(RefreshToken::class);
    }

    public function login(LoginRequestDto $dto): LoginResponseDto
    {
        $user = $this->em->getRepository(User::class)->findOneBy(['email' => $dto->email]);

        if (!$user) {
            // Constant-time: run bcrypt to prevent timing-based user enumeration
            // Uses password_hash() directly to avoid framework dependency issues
            password_verify($dto->password, '$2y$10$eZB5M1cmzvHM4DiEn8B.F.PoZKDlthVpZRO94AA5sQYvW7bLDwGrC');

            throw new AuthenticationException('Invalid credentials.');
        }

        if (!$this->hasher->isPasswordValid($user, $dto->password)) {
            throw new AuthenticationException('Invalid credentials.');
        }

        // Interactive login success is audited by AuditAuthListener (Lexik event) — do not double-log here.
        return $this->issueSessionFor($user);
    }

    public function issueSessionFor(User $user): LoginResponseDto
    {
        $accessToken = $this->jwtManager->create($user);

        // Each login starts a new token family (lineage); rotations inherit it.
        $family = Uuid::v4()->toRfc4122();
        $refreshTokenString = bin2hex(random_bytes(64));
        $expiresAt = (new \DateTimeImmutable())->modify(self::REFRESH_TTL);
        $refreshToken = RefreshToken::issue($refreshTokenString, $user, $expiresAt, $family);
        $this->em->persist($refreshToken);
        $this->em->flush();

        return new LoginResponseDto($accessToken, $refreshTokenString, self::ACCESS_TTL_SECONDS);
    }

    public function refresh(RefreshRequestDto $dto): LoginResponseDto
    {
        $refreshToken = $this->refreshTokenRepository->find(RefreshToken::hash($dto->refreshToken));

        if ($refreshToken === null) {
            $this->auditLogger->log(
                eventType: AuditEventType::AUTH_FAILURE,
                actorId: 'anonymous',
                action: 'refresh',
                outcome: 'failure',
                details: ['reason' => 'unknown_token'],
            );

            throw new AuthenticationException('Invalid refresh token');
        }

        // A rotated/revoked token presented again is the canonical theft signal: someone is
        // replaying a token we already retired. Revoke the entire family so any parallel
        // (attacker) session dies with it, then deny.
        if (!$refreshToken->isValid()) {
            $this->revokeFamily($refreshToken->getFamily());

            $this->auditLogger->log(
                eventType: AuditEventType::AUTH_TOKEN_REUSE_DETECTED,
                actorId: $refreshToken->getUser()->getUserIdentifier(),
                action: 'refresh',
                outcome: 'failure',
                details: ['reason' => 'token_reuse', 'family' => $refreshToken->getFamily()],
            );

            throw new AuthenticationException('Invalid refresh token');
        }

        if ($refreshToken->isExpired()) {
            $this->auditLogger->log(
                eventType: AuditEventType::AUTH_FAILURE,
                actorId: $refreshToken->getUser()->getUserIdentifier(),
                action: 'refresh',
                outcome: 'failure',
                details: ['reason' => 'expired'],
            );

            throw new AuthenticationException('Invalid refresh token');
        }

        $user = $refreshToken->getUser();
        $family = $refreshToken->getFamily();
        $accessToken = $this->jwtManager->create($user);

        // Rotate within the same family.
        $refreshToken->invalidate();
        $newRefreshTokenString = bin2hex(random_bytes(64));
        $expiresAt = (new \DateTimeImmutable())->modify(self::REFRESH_TTL);
        $newRefreshToken = RefreshToken::issue($newRefreshTokenString, $user, $expiresAt, $family);
        $this->em->persist($newRefreshToken);
        $this->em->flush();

        $this->auditLogger->log(
            eventType: AuditEventType::AUTH_TOKEN_REFRESHED,
            actorId: $user->getUserIdentifier(),
            action: 'refresh',
            outcome: 'success',
            details: ['family' => $family],
        );

        return new LoginResponseDto($accessToken, $newRefreshTokenString, self::ACCESS_TTL_SECONDS);
    }

    public function logout(string $refreshToken): void
    {
        $token = $this->refreshTokenRepository->find(RefreshToken::hash($refreshToken));

        if ($token && $token->isValid()) {
            $token->invalidate();
            $this->em->flush();

            $this->auditLogger->log(
                eventType: AuditEventType::AUTH_LOGOUT,
                actorId: $token->getUser()->getUserIdentifier(),
                action: 'logout',
                outcome: 'success',
                details: ['family' => $token->getFamily()],
            );
        }
    }

    /**
     * Invalidate every still-valid token in a family. Only the live tip (typically one row,
     * since each rotation invalidates its predecessor) is loaded — the retired history is
     * filtered out by `valid = true`.
     */
    private function revokeFamily(string $family): void
    {
        $liveTokens = $this->refreshTokenRepository->findBy(['family' => $family, 'valid' => true]);

        foreach ($liveTokens as $token) {
            $token->invalidate();
        }

        $this->em->flush();
    }
}
