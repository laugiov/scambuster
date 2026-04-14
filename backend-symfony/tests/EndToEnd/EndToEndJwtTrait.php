<?php

declare(strict_types=1);

namespace App\Tests\EndToEnd;

use Symfony\Bundle\FrameworkBundle\KernelBrowser;

/**
 * Shared trait for E2E tests that need a valid JWT token.
 *
 * Authenticates against POST /api/v1/auth/login with the default
 * test fixtures credentials and returns the access_token.
 */
trait EndToEndJwtTrait
{
    private function getValidJwt(KernelBrowser $client): string
    {
        $client->request('POST', '/api/v1/auth/login', [], [], ['CONTENT_TYPE' => 'application/json'], json_encode([
            'email' => 'user@example.com',
            'password' => 'Un1que$trongPassword2024',
        ]));
        $data = json_decode($client->getResponse()->getContent(), true);

        return $data['access_token'] ?? '';
    }
}
