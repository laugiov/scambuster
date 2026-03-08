<?php

declare(strict_types=1);

namespace Tests\EndToEnd\Scambaiting;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;

final class FullWorkflowE2ETest extends WebTestCase
{
    public function testAuthenticationRequired(): void
    {
        $client = static::createClient();
        $client->request('GET', '/api/v1/scambaiting/stats/PHISHING');
        $this->assertEquals(Response::HTTP_UNAUTHORIZED, $client->getResponse()->getStatusCode());
    }
}
