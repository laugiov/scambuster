<?php

declare(strict_types=1);

namespace Tests\Integration\UI\Http\Communication;

use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;

final class ExportConversationStixControllerTest extends WebTestCase
{
    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = static::createClient();
    }

    public function testRequiresAuth(): void
    {
        $this->client->request('GET', '/api/v1/conversations/00000000-0000-0000-0000-000000000001/export/stix');
        $this->assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);
    }

    public function testExportsStixBundle(): void
    {
        $this->client->request('GET', '/api/v1/conversations/00000000-0000-0000-0000-000000000001/export/stix', [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer fake-jwt',
        ]);

        $this->assertResponseIsSuccessful();

        $content = $this->client->getResponse()->getContent();
        $this->assertIsString($content);
        $data = json_decode($content, true);
        $this->assertSame('bundle', $data['type']);
    }

    public function testReturns404ForNonExistentConversation(): void
    {
        $this->client->request('GET', '/api/v1/conversations/00000000-0000-0000-0000-999999999999/export/stix', [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer fake-jwt',
        ]);

        $this->assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
    }
}
