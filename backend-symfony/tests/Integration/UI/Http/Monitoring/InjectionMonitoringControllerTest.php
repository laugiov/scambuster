<?php

declare(strict_types=1);

namespace Tests\Integration\UI\Http\Monitoring;

use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;

final class InjectionMonitoringControllerTest extends WebTestCase
{
    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = static::createClient();
    }

    public function testRequiresAuth(): void
    {
        $this->client->request('GET', '/api/v1/monitoring/injection');
        $this->assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);
    }

    public function testReturnsStats(): void
    {
        $data = $this->authenticatedGet('/api/v1/monitoring/injection');
        $this->assertIsArray($data);
    }

    public function testAcceptsDaysParam(): void
    {
        $data = $this->authenticatedGet('/api/v1/monitoring/injection?days=14');
        $this->assertIsArray($data);
    }

    /**
     * @return array<string, mixed>
     */
    private function authenticatedGet(string $url): array
    {
        $this->client->request('GET', $url, [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer fake-jwt',
        ]);

        $this->assertResponseIsSuccessful();

        $content = $this->client->getResponse()->getContent();
        $this->assertIsString($content);

        return json_decode($content, true);
    }
}
