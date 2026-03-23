<?php

declare(strict_types=1);

namespace App\Tests\Integration\Security;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

class PayloadSizeLimitListenerTest extends WebTestCase
{
    public function testNormalPayloadIsAccepted(): void
    {
        $client = static::createClient();

        $client->request('POST', '/api/v1/auth/login', [], [], [
            'CONTENT_TYPE' => 'application/json',
        ], json_encode(['email' => 'user@example.com', 'password' => 'test']));

        // May fail auth but should NOT be 413
        $this->assertNotSame(413, $client->getResponse()->getStatusCode());
    }

    public function testOversizedPayloadIsRejected(): void
    {
        $client = static::createClient();

        $client->request('POST', '/api/v1/auth/login', [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_CONTENT_LENGTH' => '2000000', // 2MB > 1MB limit
        ], '{}');

        $this->assertSame(413, $client->getResponse()->getStatusCode());
        $data = json_decode($client->getResponse()->getContent(), true);
        $this->assertSame('Payload too large', $data['error']);
    }
}
