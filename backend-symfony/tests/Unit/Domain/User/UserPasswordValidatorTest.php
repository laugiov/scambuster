<?php declare(strict_types=1);

namespace App\Tests\Unit\Domain\User;

use App\Application\User\UserPasswordValidator;
use PHPUnit\Framework\TestCase;

final class UserPasswordValidatorTest extends TestCase
{
    public function test_it_accepts_strong_password(): void
    {
        UserPasswordValidator::validate('V3ry$afeAndUncommon!');
        $this->assertTrue(true); // No exception thrown
    }

    public function test_it_rejects_short_password(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        UserPasswordValidator::validate('short');
    }

    public function test_it_rejects_blacklisted_password(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        UserPasswordValidator::validate('password123456');
    }
} 