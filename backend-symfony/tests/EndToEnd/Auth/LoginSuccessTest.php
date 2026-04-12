<?php declare(strict_types=1);

namespace App\Tests\EndToEnd\Auth;

final class LoginSuccessTest extends AbstractAuthBase
{
    public function test_it_returns_jwt_on_successful_login(): void
    {
        $this->client->request(
            'POST',
            '/api/v1/auth/login',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode($this->getValidLoginPayload())
        );

        $this->assertResponseIsSuccessful();
        $data = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertArrayHasKey('access_token', $data);
        $this->assertNotEmpty($data['access_token']);
    }
}
