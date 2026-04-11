<?php

declare(strict_types=1);

namespace App\Tests\EndToEnd\Admin;

use App\Application\Communication\ReplyCadenceService;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Spec 065b — Phase 7 — End-to-end tests for the admin kill switch
 * endpoint.
 */
final class AdminLlmKillSwitchControllerTest extends WebTestCase
{
    private function getAdminJwt(KernelBrowser $client): string
    {
        $client->request('POST', '/api/v1/auth/login', [], [], ['CONTENT_TYPE' => 'application/json'], json_encode([
            'email' => 'admin@example.com',
            'password' => 'Un1que$trongPassword2024',
        ]));
        $data = json_decode($client->getResponse()->getContent(), true);

        return $data['access_token'] ?? '';
    }

    private function getUserJwt(KernelBrowser $client): string
    {
        $client->request('POST', '/api/v1/auth/login', [], [], ['CONTENT_TYPE' => 'application/json'], json_encode([
            'email' => 'user@example.com',
            'password' => 'Un1que$trongPassword2024',
        ]));
        $data = json_decode($client->getResponse()->getContent(), true);

        return $data['access_token'] ?? '';
    }

    private function clearKillSwitch(KernelBrowser $client): void
    {
        /** @var CacheItemPoolInterface $cache */
        $cache = $client->getContainer()->get('cache.app');
        $cache->deleteItem(ReplyCadenceService::KILL_SWITCH_CACHE_KEY);
    }

    public function test_get_returns_inactive_by_default(): void
    {
        $client = static::createClient();
        $this->clearKillSwitch($client);
        $jwt = $this->getAdminJwt($client);

        $client->request('GET', '/api/v1/admin/llm/killswitch', [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer ' . $jwt,
        ]);

        $this->assertSame(200, $client->getResponse()->getStatusCode());
        $body = json_decode((string) $client->getResponse()->getContent(), true);
        $this->assertFalse($body['active']);
    }

    public function test_post_active_true_persists_state(): void
    {
        $client = static::createClient();
        $this->clearKillSwitch($client);
        $jwt = $this->getAdminJwt($client);

        $client->request(
            'POST',
            '/api/v1/admin/llm/killswitch',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json', 'HTTP_AUTHORIZATION' => 'Bearer ' . $jwt],
            json_encode(['active' => true]),
        );

        $this->assertSame(200, $client->getResponse()->getStatusCode());
        $body = json_decode((string) $client->getResponse()->getContent(), true);
        $this->assertTrue($body['active']);

        // Verify persistence via a follow-up GET
        $client->request('GET', '/api/v1/admin/llm/killswitch', [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer ' . $jwt,
        ]);
        $body = json_decode((string) $client->getResponse()->getContent(), true);
        $this->assertTrue($body['active']);

        // Cleanup
        $this->clearKillSwitch($client);
    }

    public function test_post_active_false_clears_state(): void
    {
        $client = static::createClient();
        $jwt = $this->getAdminJwt($client);

        // First, activate
        $client->request(
            'POST',
            '/api/v1/admin/llm/killswitch',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json', 'HTTP_AUTHORIZATION' => 'Bearer ' . $jwt],
            json_encode(['active' => true]),
        );

        // Then deactivate
        $client->request(
            'POST',
            '/api/v1/admin/llm/killswitch',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json', 'HTTP_AUTHORIZATION' => 'Bearer ' . $jwt],
            json_encode(['active' => false]),
        );

        $this->assertSame(200, $client->getResponse()->getStatusCode());
        $body = json_decode((string) $client->getResponse()->getContent(), true);
        $this->assertFalse($body['active']);

        // Verify GET returns false
        $client->request('GET', '/api/v1/admin/llm/killswitch', [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer ' . $jwt,
        ]);
        $body = json_decode((string) $client->getResponse()->getContent(), true);
        $this->assertFalse($body['active']);
    }

    public function test_post_rejects_non_admin_user(): void
    {
        $client = static::createClient();
        $jwt = $this->getUserJwt($client);

        $client->request(
            'POST',
            '/api/v1/admin/llm/killswitch',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json', 'HTTP_AUTHORIZATION' => 'Bearer ' . $jwt],
            json_encode(['active' => true]),
        );

        $statusCode = $client->getResponse()->getStatusCode();
        $this->assertContains($statusCode, [401, 403], 'Non-admin must not access kill switch');
    }

    public function test_post_rejects_invalid_body(): void
    {
        $client = static::createClient();
        $jwt = $this->getAdminJwt($client);

        $client->request(
            'POST',
            '/api/v1/admin/llm/killswitch',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json', 'HTTP_AUTHORIZATION' => 'Bearer ' . $jwt],
            json_encode(['something_else' => true]),
        );

        $this->assertSame(400, $client->getResponse()->getStatusCode());
    }
}
