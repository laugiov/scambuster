<?php

declare(strict_types=1);

namespace App\Tests\Unit\Security;

use App\Domain\User\Permission;
use App\Domain\User\User;
use App\Security\PermissionVoter;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\Authorization\Voter\VoterInterface;

class PermissionVoterTest extends TestCase
{
    private PermissionVoter $voter;

    protected function setUp(): void
    {
        $this->voter = new PermissionVoter();
    }

    public function testAdminHasAllPermissions(): void
    {
        $user = new User();
        $user->setRoles(['ROLE_ADMIN']);
        $user->setPermissions([]);

        $token = new UsernamePasswordToken($user, 'main', $user->getRoles());

        $this->assertSame(
            VoterInterface::ACCESS_GRANTED,
            $this->voter->vote($token, null, [Permission::CONVERSATION_READ->value])
        );

        $this->assertSame(
            VoterInterface::ACCESS_GRANTED,
            $this->voter->vote($token, null, [Permission::CONFIG_WRITE->value])
        );
    }

    public function testUserWithPermissionIsGranted(): void
    {
        $user = new User();
        $user->setRoles(['ROLE_USER']);
        $user->setPermissions(['conversation:read', 'ioc:read']);

        $token = new UsernamePasswordToken($user, 'main', $user->getRoles());

        $this->assertSame(
            VoterInterface::ACCESS_GRANTED,
            $this->voter->vote($token, null, [Permission::CONVERSATION_READ->value])
        );
    }

    public function testUserWithoutPermissionIsDenied(): void
    {
        $user = new User();
        $user->setRoles(['ROLE_USER']);
        $user->setPermissions(['conversation:read']);

        $token = new UsernamePasswordToken($user, 'main', $user->getRoles());

        $this->assertSame(
            VoterInterface::ACCESS_DENIED,
            $this->voter->vote($token, null, [Permission::IOC_EXPORT->value])
        );
    }

    public function testAbstainsOnUnknownAttribute(): void
    {
        $user = new User();
        $user->setRoles(['ROLE_USER']);

        $token = new UsernamePasswordToken($user, 'main', $user->getRoles());

        $this->assertSame(
            VoterInterface::ACCESS_ABSTAIN,
            $this->voter->vote($token, null, ['unknown:permission'])
        );
    }

    public function testAllPermissionEnumValuesExist(): void
    {
        $cases = Permission::cases();
        $this->assertGreaterThanOrEqual(12, count($cases));

        foreach ($cases as $case) {
            $this->assertStringContainsString(':', $case->value);
        }
    }
}
