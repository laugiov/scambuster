<?php

declare(strict_types=1);

namespace Tests\Integration\UI\Http\Monitoring;

use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;

final class ConversationLifecycleControllerTest extends WebTestCase
{
    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = static::createClient();
    }

    public function testConversationLifecycleRequiresAuthentication(): void
    {
        $this->client->request('GET', '/api/v1/monitoring/conversation-lifecycle');

        $this->assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);
    }

    public function testConversationLifecycleReturnsExpectedStructure(): void
    {
        $this->client->request('GET', '/api/v1/monitoring/conversation-lifecycle', [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer fake-jwt',
        ]);

        $this->assertResponseIsSuccessful();

        $data = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertIsArray($data);
        $this->assertArrayHasKey('active', $data);
        $this->assertArrayHasKey('about_to_timeout', $data);
        $this->assertArrayHasKey('completed_today', $data);
        $this->assertArrayHasKey('reopened_today', $data);
        $this->assertArrayHasKey('by_scam_type', $data);
        $this->assertArrayHasKey('about_to_timeout_list', $data);
    }

    public function testConversationLifecycleActiveCountIsInteger(): void
    {
        $this->client->request('GET', '/api/v1/monitoring/conversation-lifecycle', [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer fake-jwt',
        ]);

        $this->assertResponseIsSuccessful();

        $data = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertIsInt($data['active']);
        $this->assertIsInt($data['about_to_timeout']);
        $this->assertIsInt($data['completed_today']);
    }

    public function testConversationLifecycleReturnsJsonContentType(): void
    {
        $this->client->request('GET', '/api/v1/monitoring/conversation-lifecycle', [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer fake-jwt',
        ]);

        $this->assertResponseIsSuccessful();
        $this->assertResponseHeaderSame('content-type', 'application/json');
    }
}
