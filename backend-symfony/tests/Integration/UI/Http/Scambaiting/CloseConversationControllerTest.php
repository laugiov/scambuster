<?php

declare(strict_types=1);

namespace Tests\Integration\UI\Http\Scambaiting;

use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;

final class CloseConversationControllerTest extends WebTestCase
{
    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = static::createClient();
    }

    public function testCloseConversationRequiresAuthentication(): void
    {
        $this->client->request('POST', '/api/v1/scambaiting/conversation/conv_test_123/close');

        $this->assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);
    }
}
