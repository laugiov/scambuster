<?php declare(strict_types=1);

namespace App\Tests\Functional\Auth;

final class LoginFailureTest extends AbstractAuthBase
{
    public function test_it_fails_with_invalid_credentials(): void
    {
        $this->client->request(
            'POST',
            '/api/v1/auth/login',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode(['email' => 'wrong@example.com', 'password' => 'badpassword'])
        );
        $this->assertResponseStatusCodeSame(401);
        $data = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertSame('invalid credentials.', $data['message']);
    }

    public function test_it_fails_with_missing_email(): void
    {
        $this->client->request(
            'POST',
            '/api/v1/auth/login',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode(['password' => 'Un1que$trongPassword2024'])
        );
        $this->assertResponseStatusCodeSame(400);
    }

    public function test_it_fails_with_missing_password(): void
    {
        $this->client->request(
            'POST',
            '/api/v1/auth/login',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode(['email' => 'user@example.com'])
        );
        $this->assertResponseStatusCodeSame(400);
    }

    public function test_it_fails_with_invalid_json(): void
    {
        $this->client->request(
            'POST',
            '/api/v1/auth/login',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            '{"email": "user@example.com", "password": "foo" BAD'
        );
        $this->assertResponseStatusCodeSame(400);
    }
}
