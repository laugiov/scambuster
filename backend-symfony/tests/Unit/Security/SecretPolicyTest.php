<?php

declare(strict_types=1);

namespace App\Tests\Unit\Security;

use App\Security\SecretPolicy;
use PHPUnit\Framework\TestCase;

/**
 * D1: production must refuse to boot on known-default or obviously-weak secrets.
 *
 * The policy is the single source of truth (the entrypoint delegates to it via a
 * console command). It only enforces in a production context; dev/test/e2e keep
 * booting on the documented .env.dist defaults.
 */
final class SecretPolicyTest extends TestCase
{
    private SecretPolicy $policy;

    protected function setUp(): void
    {
        $this->policy = new SecretPolicy();
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function envDistDefaults(): array
    {
        return [
            'APP_SECRET'                => ['APP_SECRET', 'a1b2c3d4e5f6a1b2c3d4e5f6a1b2c3d4'],
            'TOTP_ENCRYPTION_KEY'       => ['TOTP_ENCRYPTION_KEY', str_repeat('a', 64)],
            'AUDIT_HMAC_KEY'            => ['AUDIT_HMAC_KEY', str_repeat('b', 64)],
            'N8N_ENCRYPTION_KEY'        => ['N8N_ENCRYPTION_KEY', 'dev-only-change-in-production-openssl-rand-hex-32'],
            'N8N_DEFAULT_USER_PASSWORD' => ['N8N_DEFAULT_USER_PASSWORD', 'Scambuster2026!'],
            'ADMIN_PASSWORD'            => ['ADMIN_PASSWORD', 'Un1que$trongPassword2024'],
        ];
    }

    /**
     * D1.1: every published .env.dist default (and the documented admin password)
     * is a violation in prod.
     *
     * @dataProvider envDistDefaults
     */
    public function testRejectsEachPublishedDefaultInProd(string $name, string $value): void
    {
        $violations = $this->policy->validate([$name => $value], isProd: true);

        self::assertArrayHasKey($name, $violations, sprintf('%s default must be rejected in prod', $name));
        self::assertNotSame('', $violations[$name], 'the violation must carry a human message');
    }

    /**
     * D1.2: generic weak values are flagged even if not on the exact blocklist.
     *
     * @dataProvider weakValues
     */
    public function testFlagsGenericWeakValuesInProd(string $value): void
    {
        $violations = $this->policy->validate(['APP_SECRET' => $value], isProd: true);

        self::assertArrayHasKey('APP_SECRET', $violations);
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function weakValues(): array
    {
        return [
            'single char repeated' => [str_repeat('a', 64)],
            'dev-only-change'      => ['my-dev-only-change-me-later'],
            'change-in-production' => ['please-change-in-production'],
            'changeme'            => ['changeme'],
            'your- placeholder'    => ['your-secret-here'],
            'empty'               => [''],
        ];
    }

    /**
     * D1.3: strong distinct random values pass.
     */
    public function testStrongValuesProduceNoViolationInProd(): void
    {
        $secrets = [
            'APP_SECRET'          => bin2hex(random_bytes(16)),
            'TOTP_ENCRYPTION_KEY' => bin2hex(random_bytes(32)),
            'AUDIT_HMAC_KEY'      => bin2hex(random_bytes(32)),
            'N8N_ENCRYPTION_KEY'  => bin2hex(random_bytes(32)),
        ];

        self::assertSame([], $this->policy->validate($secrets, isProd: true));
    }

    /**
     * D1.4: outside prod the policy is a no-op — dev/test/e2e keep booting on defaults.
     */
    public function testIsNoOpOutsideProd(): void
    {
        $secrets = [
            'APP_SECRET'          => 'a1b2c3d4e5f6a1b2c3d4e5f6a1b2c3d4',
            'TOTP_ENCRYPTION_KEY' => str_repeat('a', 64),
            'AUDIT_HMAC_KEY'      => str_repeat('b', 64),
        ];

        self::assertSame([], $this->policy->validate($secrets, isProd: false));
    }

    /**
     * A variable that is absent (null) is not the policy's concern — presence is
     * enforced elsewhere (the entrypoint's `:?` guards). The policy only judges
     * values it is given.
     */
    public function testIgnoresNullValues(): void
    {
        self::assertSame([], $this->policy->validate(['APP_SECRET' => null], isProd: true));
    }
}
