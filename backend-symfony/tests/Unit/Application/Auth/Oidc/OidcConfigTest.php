<?php

declare(strict_types=1);

namespace App\Tests\Unit\Application\Auth\Oidc;

use App\Application\Auth\Oidc\OidcConfig;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class OidcConfigTest extends TestCase
{
    /**
     * @param list<string> $allowedDomains
     */
    private function config(array $allowedDomains): OidcConfig
    {
        return new OidcConfig(
            enabled: true,
            discoveryUrl: 'https://idp.test/.well-known/openid-configuration',
            clientId: 'client',
            clientSecret: 'secret',
            redirectUri: 'https://app.test/api/v1/auth/oidc/callback',
            scopes: 'openid email profile',
            autoProvision: false,
            allowedEmailDomains: $allowedDomains,
            defaultRoles: ['ROLE_USER'],
            successRedirect: '',
        );
    }

    #[Test]
    public function an_empty_allow_list_permits_any_domain(): void
    {
        $config = $this->config([]);

        self::assertTrue($config->isEmailDomainAllowed('anyone@wherever.example'));
    }

    #[Test]
    public function a_matching_domain_is_allowed(): void
    {
        $config = $this->config(['corp.test']);

        self::assertTrue($config->isEmailDomainAllowed('alice@corp.test'));
    }

    #[Test]
    public function domain_matching_is_case_insensitive(): void
    {
        $config = $this->config(['corp.test']);

        self::assertTrue($config->isEmailDomainAllowed('Alice@CORP.test'));
    }

    #[Test]
    public function a_non_matching_domain_is_rejected(): void
    {
        $config = $this->config(['corp.test']);

        self::assertFalse($config->isEmailDomainAllowed('mallory@evil.test'));
    }

    #[Test]
    public function an_address_without_an_at_sign_is_rejected_when_a_list_is_set(): void
    {
        $config = $this->config(['corp.test']);

        self::assertFalse($config->isEmailDomainAllowed('not-an-email'));
    }
}
