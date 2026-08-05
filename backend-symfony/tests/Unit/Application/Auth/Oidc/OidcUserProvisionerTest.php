<?php

declare(strict_types=1);

namespace App\Tests\Unit\Application\Auth\Oidc;

use App\Application\Auth\Oidc\OidcConfig;
use App\Application\Auth\Oidc\OidcException;
use App\Application\Auth\Oidc\OidcIdentity;
use App\Application\Auth\Oidc\OidcUserProvisioner;
use App\Domain\User\User;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class OidcUserProvisionerTest extends TestCase
{
    private function config(bool $autoProvision, array $defaultRoles = ['ROLE_USER']): OidcConfig
    {
        return new OidcConfig(
            enabled: true,
            discoveryUrl: 'https://idp.test/.well-known/openid-configuration',
            clientId: 'client',
            clientSecret: 'secret',
            redirectUri: 'https://app.test/api/v1/auth/oidc/callback',
            scopes: 'openid email',
            autoProvision: $autoProvision,
            allowedEmailDomains: [],
            defaultRoles: $defaultRoles,
            successRedirect: '',
        );
    }

    /**
     * @param EntityRepository<User>|null $repo
     */
    private function entityManager(?EntityRepository $repo, bool $expectPersist): EntityManagerInterface
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('getRepository')->willReturn($repo);
        $em->expects($expectPersist ? self::once() : self::never())->method('persist');
        $em->expects($expectPersist ? self::once() : self::never())->method('flush');

        return $em;
    }

    #[Test]
    public function it_returns_an_existing_user_without_persisting(): void
    {
        $existing = (new User())->setEmail('alice@corp.test');
        $repo = $this->createMock(EntityRepository::class);
        $repo->method('findOneBy')->with(['email' => 'alice@corp.test'])->willReturn($existing);

        $provisioner = new OidcUserProvisioner(
            $this->entityManager($repo, expectPersist: false),
            $this->createMock(UserPasswordHasherInterface::class),
            $this->config(autoProvision: false),
        );

        $resolved = $provisioner->resolve(new OidcIdentity('sub-1', 'alice@corp.test'));

        self::assertSame($existing, $resolved);
    }

    #[Test]
    public function it_refuses_an_unknown_identity_when_auto_provisioning_is_off(): void
    {
        $repo = $this->createMock(EntityRepository::class);
        $repo->method('findOneBy')->willReturn(null);

        $provisioner = new OidcUserProvisioner(
            $this->entityManager($repo, expectPersist: false),
            $this->createMock(UserPasswordHasherInterface::class),
            $this->config(autoProvision: false),
        );

        $this->expectException(OidcException::class);
        $provisioner->resolve(new OidcIdentity('sub-1', 'newcomer@corp.test'));
    }

    #[Test]
    public function it_provisions_a_new_user_with_default_roles_when_enabled(): void
    {
        $repo = $this->createMock(EntityRepository::class);
        $repo->method('findOneBy')->willReturn(null);

        $hasher = $this->createMock(UserPasswordHasherInterface::class);
        $hasher->method('hashPassword')->willReturn('hashed-random');

        $provisioner = new OidcUserProvisioner(
            $this->entityManager($repo, expectPersist: true),
            $hasher,
            $this->config(autoProvision: true, defaultRoles: ['ROLE_USER', 'ROLE_ANALYST']),
        );

        $user = $provisioner->resolve(new OidcIdentity('sub-1', 'newcomer@corp.test'));

        self::assertSame('newcomer@corp.test', $user->getEmail());
        self::assertSame('hashed-random', $user->getPassword());
        self::assertContains('ROLE_ANALYST', $user->getRoles());
    }
}
