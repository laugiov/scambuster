<?php

declare(strict_types=1);

namespace App\Tests\Unit\Domain\User;

use App\Domain\User\Permission;
use App\Domain\User\User;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

/**
 * Unit tests for the User domain entity.
 *
 * Covers getters/setters, TOTP enable/disable, role management,
 * and fine-grained permission checks.
 */
final class UserTest extends TestCase
{
    public function testConstructorGeneratesUuid(): void
    {
        $user = new User();
        $this->assertInstanceOf(Uuid::class, $user->getId());
    }

    public function testSetAndGetEmail(): void
    {
        $user = new User();
        $result = $user->setEmail('test@example.com');

        $this->assertSame('test@example.com', $user->getEmail());
        $this->assertSame($user, $result, 'setEmail should return $this for fluent API');
    }

    public function testSetAndGetPassword(): void
    {
        $user = new User();
        $result = $user->setPassword('$2y$13$hashed');

        $this->assertSame('$2y$13$hashed', $user->getPassword());
        $this->assertSame($user, $result);
    }

    public function testGetRolesDefaultsToRoleUser(): void
    {
        $user = new User();
        $this->assertSame(['ROLE_USER'], $user->getRoles());
    }

    public function testSetAndGetRoles(): void
    {
        $user = new User();
        $result = $user->setRoles(['ROLE_ADMIN', 'ROLE_USER']);

        $this->assertSame(['ROLE_ADMIN', 'ROLE_USER'], $user->getRoles());
        $this->assertSame($user, $result);
    }

    public function testGetRolesReturnsDefaultWhenSetToEmpty(): void
    {
        $user = new User();
        $user->setRoles([]);
        // Empty array is falsy, so getRoles() returns ['ROLE_USER']
        $this->assertSame(['ROLE_USER'], $user->getRoles());
    }

    public function testSetAndGetPermissions(): void
    {
        $user = new User();
        $result = $user->setPermissions(['conversation:read', 'ioc:read']);

        $this->assertSame(['conversation:read', 'ioc:read'], $user->getPermissions());
        $this->assertSame($user, $result);
    }

    public function testGetPermissionsDefaultsToEmpty(): void
    {
        $user = new User();
        $this->assertSame([], $user->getPermissions());
    }

    // ------------------------------------------------------------------ //
    //  hasPermission
    // ------------------------------------------------------------------ //

    public function testHasPermissionReturnsTrueForAdmin(): void
    {
        $user = new User();
        $user->setRoles(['ROLE_ADMIN']);

        // Admin should have ALL permissions implicitly
        $this->assertTrue($user->hasPermission(Permission::CONVERSATION_READ));
        $this->assertTrue($user->hasPermission(Permission::IOC_EXPORT));
        $this->assertTrue($user->hasPermission(Permission::CONFIG_WRITE));
    }

    public function testHasPermissionReturnsTrueWhenGranted(): void
    {
        $user = new User();
        $user->setRoles(['ROLE_USER']);
        $user->setPermissions(['conversation:read', 'ioc:read']);

        $this->assertTrue($user->hasPermission(Permission::CONVERSATION_READ));
        $this->assertTrue($user->hasPermission(Permission::IOC_READ));
    }

    public function testHasPermissionReturnsFalseWhenNotGranted(): void
    {
        $user = new User();
        $user->setRoles(['ROLE_USER']);
        $user->setPermissions(['conversation:read']);

        $this->assertFalse($user->hasPermission(Permission::CONFIG_WRITE));
        $this->assertFalse($user->hasPermission(Permission::CAMPAIGN_PROMOTE));
    }

    // ------------------------------------------------------------------ //
    //  TOTP
    // ------------------------------------------------------------------ //

    public function testTotpSecretDefaultsToNull(): void
    {
        $user = new User();
        $this->assertNull($user->getTotpSecret());
    }

    public function testSetAndGetTotpSecret(): void
    {
        $user = new User();
        $result = $user->setTotpSecret('BASE32SECRETHERE');

        $this->assertSame('BASE32SECRETHERE', $user->getTotpSecret());
        $this->assertSame($user, $result);
    }

    public function testIsTotpEnabledReturnsFalseByDefault(): void
    {
        $user = new User();
        $this->assertFalse($user->isTotpEnabled());
    }

    public function testIsTotpEnabledReturnsTrueWhenSecretSet(): void
    {
        $user = new User();
        $user->setTotpSecret('BASE32SECRET');
        $this->assertTrue($user->isTotpEnabled());
    }

    public function testIsTotpEnabledReturnsFalseAfterClearingSecret(): void
    {
        $user = new User();
        $user->setTotpSecret('BASE32SECRET');
        $user->setTotpSecret(null);
        $this->assertFalse($user->isTotpEnabled());
    }

    // ------------------------------------------------------------------ //
    //  Scheb TwoFactorInterface
    // ------------------------------------------------------------------ //

    public function testIsTotpAuthenticationEnabledMatchesIsTotpEnabled(): void
    {
        $user = new User();
        $this->assertFalse($user->isTotpAuthenticationEnabled());

        $user->setTotpSecret('BASE32SECRET');
        $this->assertTrue($user->isTotpAuthenticationEnabled());
    }

    public function testGetTotpAuthenticationUsernameReturnsEmail(): void
    {
        $user = new User();
        $user->setEmail('alice@example.com');
        $this->assertSame('alice@example.com', $user->getTotpAuthenticationUsername());
    }

    public function testGetTotpAuthenticationConfigurationReturnsNullWithoutSecret(): void
    {
        $user = new User();
        $this->assertNull($user->getTotpAuthenticationConfiguration());
    }

    public function testGetTotpAuthenticationConfigurationReturnsConfigWithSecret(): void
    {
        $user = new User();
        $user->setTotpSecret('JBSWY3DPEHPK3PXP');

        $config = $user->getTotpAuthenticationConfiguration();
        $this->assertNotNull($config);
        $this->assertSame(30, $config->getPeriod());
        $this->assertSame(6, $config->getDigits());
    }

    // ------------------------------------------------------------------ //
    //  UserInterface
    // ------------------------------------------------------------------ //

    public function testGetUserIdentifierReturnsEmail(): void
    {
        $user = new User();
        $user->setEmail('bob@example.com');
        $this->assertSame('bob@example.com', $user->getUserIdentifier());
    }

    public function testGetUserIdentifierReturnsUnknownWhenEmailEmpty(): void
    {
        $user = new User();
        $this->assertSame('unknown', $user->getUserIdentifier());
    }

    public function testEraseCredentialsDoesNotThrow(): void
    {
        $user = new User();
        $user->eraseCredentials();
        $this->assertTrue(true); // No exception
    }
}
