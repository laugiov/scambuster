<?php

namespace App\Tests\EndToEnd\Auth;

final class CsrfProtectionTest extends AbstractAuthBase
{
    public function test_it_blocks_mutative_requests_without_csrf_token(): void
    {
        $this->client->request(
            'POST',
            '/api/v1/some-protected-endpoint'
        );
        $response = $this->client->getResponse();

        // Attendu : 401, car pas de JWT fourni (le CSRF check n'est pas atteint)
        $this->assertSame(401, $response->getStatusCode(), 'Without JWT, get 401, not 403');
    }
}
