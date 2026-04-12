<?php

namespace App\Tests\Functional\Auth;

final class ProtectedEndpointTest extends AbstractAuthBase
{
    public function test_access_without_csrf_token_returns_401(): void
    {
        $this->client->request('POST', '/api/v1/some-protected-endpoint');
        $this->assertSame(401, $this->client->getResponse()->getStatusCode(), 'Sans JWT, doit être 401');
    }

    public function test_access_with_invalid_csrf_token_returns_401(): void
    {
        $this->client->request(
            'POST',
            '/api/v1/some-protected-endpoint',
            [],
            [],
            [
                'HTTP_X_CSRF_TOKEN' => 'invalid_token'
            ]
        );
        $this->assertSame(401, $this->client->getResponse()->getStatusCode(), 'Sans JWT valide, toujours 401');
    }
}
