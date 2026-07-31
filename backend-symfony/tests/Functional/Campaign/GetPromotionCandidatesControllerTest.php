<?php

declare(strict_types=1);

namespace Tests\Functional\Campaign;

use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;

final class GetPromotionCandidatesControllerTest extends WebTestCase
{
    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = static::createClient();
    }

    public function testPromotionCandidatesRequiresAuthentication(): void
    {
        $this->client->request('GET', '/api/v1/campaign/candidates');

        $this->assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);
    }

    public function testPromotionCandidatesReturnsArrayStructure(): void
    {
        $this->client->request('GET', '/api/v1/campaign/candidates', [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer fake-jwt',
        ]);

        $this->assertResponseIsSuccessful();

        $data = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertIsArray($data);
        $this->assertArrayHasKey('candidates', $data);
        $this->assertArrayHasKey('thresholds', $data);
        $this->assertIsArray($data['candidates']);
    }

    public function testPromotionCandidatesThresholdsStructure(): void
    {
        $this->client->request('GET', '/api/v1/campaign/candidates', [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer fake-jwt',
        ]);

        $this->assertResponseIsSuccessful();

        $data = json_decode($this->client->getResponse()->getContent(), true);
        $thresholds = $data['thresholds'];
        $this->assertIsArray($thresholds);
        $this->assertArrayHasKey('ppv_threshold', $thresholds);
        $this->assertArrayHasKey('min_hits', $thresholds);
        $this->assertArrayHasKey('min_lead_time_sec', $thresholds);
    }

    public function testPromotionCandidatesReturnsJsonContentType(): void
    {
        $this->client->request('GET', '/api/v1/campaign/candidates', [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer fake-jwt',
        ]);

        $this->assertResponseIsSuccessful();
        $this->assertResponseHeaderSame('content-type', 'application/json');
    }
}
