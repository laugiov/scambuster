<?php

declare(strict_types=1);

namespace App\Tests\Functional\Console;

use App\Security\SecretPolicy;
use App\UI\Console\CheckSecretsCommand;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * D1.5–D1.6: the command the prod entrypoint runs must exit non-zero when a
 * secret still holds a public-default value, and zero otherwise.
 *
 * The command is exercised directly with an injected `appEnv` so the prod path is
 * covered without a prod kernel; secret values are seeded into $_SERVER and
 * restored afterwards.
 */
final class CheckSecretsCommandTest extends TestCase
{
    /** @var array<string, string|false> original $_SERVER values to restore */
    private array $saved = [];

    private const VARS = [
        'APP_SECRET', 'TOTP_ENCRYPTION_KEY', 'AUDIT_HMAC_KEY', 'JWT_PASSPHRASE',
        'N8N_ENCRYPTION_KEY', 'N8N_DEFAULT_USER_PASSWORD', 'ADMIN_PASSWORD',
    ];

    protected function setUp(): void
    {
        foreach (self::VARS as $v) {
            $this->saved[$v] = $_SERVER[$v] ?? false;
            unset($_SERVER[$v]);
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->saved as $v => $orig) {
            if ($orig === false) {
                unset($_SERVER[$v]);
            } else {
                $_SERVER[$v] = $orig;
            }
        }
    }

    private function tester(string $appEnv): CommandTester
    {
        return new CommandTester(new CheckSecretsCommand($appEnv, new SecretPolicy()));
    }

    public function testFailsInProdOnPublishedDefault(): void
    {
        $_SERVER['APP_SECRET'] = 'a1b2c3d4e5f6a1b2c3d4e5f6a1b2c3d4'; // .env.dist default
        $_SERVER['TOTP_ENCRYPTION_KEY'] = bin2hex(random_bytes(32));

        $tester = $this->tester('prod');
        $exit = $tester->execute([]);

        self::assertSame(1, $exit, 'boot must fail on a default secret');
        self::assertStringContainsString('APP_SECRET', $tester->getDisplay());
    }

    public function testFailsInProdOnRepeatedCharKey(): void
    {
        $_SERVER['AUDIT_HMAC_KEY'] = str_repeat('b', 64);

        $exit = $this->tester('prod')->execute([]);

        self::assertSame(1, $exit);
    }

    public function testPassesInProdOnStrongValues(): void
    {
        $_SERVER['APP_SECRET'] = bin2hex(random_bytes(16));
        $_SERVER['TOTP_ENCRYPTION_KEY'] = bin2hex(random_bytes(32));
        $_SERVER['AUDIT_HMAC_KEY'] = bin2hex(random_bytes(32));
        $_SERVER['JWT_PASSPHRASE'] = bin2hex(random_bytes(16));
        $_SERVER['N8N_ENCRYPTION_KEY'] = bin2hex(random_bytes(32));
        $_SERVER['N8N_DEFAULT_USER_PASSWORD'] = bin2hex(random_bytes(12));

        $exit = $this->tester('prod')->execute([]);

        self::assertSame(0, $exit, 'strong values must pass');
    }

    public function testSkipsOutsideProdEvenWithDefaults(): void
    {
        $_SERVER['APP_SECRET'] = 'a1b2c3d4e5f6a1b2c3d4e5f6a1b2c3d4';
        $_SERVER['TOTP_ENCRYPTION_KEY'] = str_repeat('a', 64);

        $exit = $this->tester('dev')->execute([]);

        self::assertSame(0, $exit, 'dev/test must keep booting on defaults');
    }
}
