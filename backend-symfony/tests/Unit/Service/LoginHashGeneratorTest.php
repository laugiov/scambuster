<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Service\LoginHashGenerator;
use PHPUnit\Framework\TestCase;

final class LoginHashGeneratorTest extends TestCase
{
    protected function setUp(): void
    {
        $_ENV['LOGIN_HASH_SALT'] = 'test-salt-for-unit-tests';
    }

    public function testGenerateReturnsSha256Hash(): void
    {
        $generator = new LoginHashGenerator();
        $hash = $generator->generate('admin@example.com');

        $this->assertSame(64, strlen($hash), 'SHA256 hash should be 64 hex characters');
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $hash);
    }

    public function testGenerateIsDeterministic(): void
    {
        $generator = new LoginHashGenerator();

        $hash1 = $generator->generate('user@test.com');
        $hash2 = $generator->generate('user@test.com');

        $this->assertSame($hash1, $hash2);
    }

    public function testGenerateProducesDifferentHashesForDifferentInputs(): void
    {
        $generator = new LoginHashGenerator();

        $hash1 = $generator->generate('user1@test.com');
        $hash2 = $generator->generate('user2@test.com');

        $this->assertNotSame($hash1, $hash2);
    }

    public function testGenerateIncludesSaltInHash(): void
    {
        $_ENV['LOGIN_HASH_SALT'] = 'salt-a';
        $generatorA = new LoginHashGenerator();
        $hashA = $generatorA->generate('same-input');

        $_ENV['LOGIN_HASH_SALT'] = 'salt-b';
        $generatorB = new LoginHashGenerator();
        $hashB = $generatorB->generate('same-input');

        $this->assertNotSame($hashA, $hashB, 'Different salts should produce different hashes');
    }
}
