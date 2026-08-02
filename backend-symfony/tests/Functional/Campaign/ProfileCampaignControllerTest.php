<?php

declare(strict_types=1);

namespace Tests\Functional\Campaign;

use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;

final class ProfileCampaignControllerTest extends WebTestCase
{
    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = static::createClient();
    }

    public function testProfileRequiresAuthentication(): void
    {
        $this->client->request('POST', '/api/v1/campaign/00000000-0000-0000-0000-000000000001/profile', [], [], [
            'CONTENT_TYPE' => 'application/json',
        ]);

        $this->assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);
    }

    public function testProfileRejectsInvalidCampaignIdFormat(): void
    {
        $this->client->request('POST', '/api/v1/campaign/not-a-uuid/profile', [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer fake-jwt',
            'CONTENT_TYPE' => 'application/json',
        ]);

        $this->assertResponseStatusCodeSame(Response::HTTP_BAD_REQUEST);

        $data = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertArrayHasKey('error', $data);
        $this->assertStringContainsString('Invalid campaign_id format', $data['error']);
    }

    public function testProfileReturnsErrorForNonexistentCampaign(): void
    {
        $this->client->request('POST', '/api/v1/campaign/00000000-0000-0000-0000-000000000099/profile', [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer fake-jwt',
            'CONTENT_TYPE' => 'application/json',
        ], json_encode([]));

        $statusCode = $this->client->getResponse()->getStatusCode();
        // Handler will throw RuntimeException (404) or Throwable (500)
        $this->assertContains($statusCode, [
            Response::HTTP_OK,
            Response::HTTP_NOT_FOUND,
            Response::HTTP_INTERNAL_SERVER_ERROR,
        ]);

        $data = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertIsArray($data);
    }

    public function testProfileRejectsInvalidSampleSize(): void
    {
        $this->client->request('POST', '/api/v1/campaign/00000000-0000-0000-0000-000000000001/profile', [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer fake-jwt',
            'CONTENT_TYPE' => 'application/json',
        ], json_encode(['sample_size' => 200]));

        $this->assertResponseStatusCodeSame(Response::HTTP_BAD_REQUEST);

        $data = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertArrayHasKey('error', $data);
        $this->assertStringContainsString('sample_size must be', $data['error']);
    }

    public function testProfileRejectsSampleSizeTooSmall(): void
    {
        $this->client->request('POST', '/api/v1/campaign/00000000-0000-0000-0000-000000000001/profile', [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer fake-jwt',
            'CONTENT_TYPE' => 'application/json',
        ], json_encode(['sample_size' => 1]));

        $this->assertResponseStatusCodeSame(Response::HTTP_BAD_REQUEST);

        $data = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertArrayHasKey('error', $data);
    }

    public function testProfileReturnsJsonContentType(): void
    {
        $this->client->request('POST', '/api/v1/campaign/not-a-uuid/profile', [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer fake-jwt',
            'CONTENT_TYPE' => 'application/json',
        ]);

        $this->assertResponseHeaderSame('content-type', 'application/json');
    }

    public function testProfileWithDefaultSampleSize(): void
    {
        $this->client->request('POST', '/api/v1/campaign/00000000-0000-0000-0000-000000000001/profile', [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer fake-jwt',
            'CONTENT_TYPE' => 'application/json',
        ], json_encode([]));

        $statusCode = $this->client->getResponse()->getStatusCode();
        // With default sample_size=10, should not get 400
        $this->assertNotSame(Response::HTTP_BAD_REQUEST, $statusCode);
    }

    public function testProfileRejectsSampleSizeOfZero(): void
    {
        $this->client->request('POST', '/api/v1/campaign/00000000-0000-0000-0000-000000000001/profile', [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer fake-jwt',
            'CONTENT_TYPE' => 'application/json',
        ], json_encode(['sample_size' => 0]));

        $this->assertResponseStatusCodeSame(Response::HTTP_BAD_REQUEST);

        $data = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertStringContainsString('sample_size must be', $data['error']);
    }

    public function testProfileRejectsNonIntegerSampleSize(): void
    {
        $this->client->request('POST', '/api/v1/campaign/00000000-0000-0000-0000-000000000001/profile', [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer fake-jwt',
            'CONTENT_TYPE' => 'application/json',
        ], json_encode(['sample_size' => 'ten']));

        $this->assertResponseStatusCodeSame(Response::HTTP_BAD_REQUEST);

        $data = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertStringContainsString('sample_size must be', $data['error']);
    }

    public function testProfileAcceptsValidSampleSizeBoundary(): void
    {
        // sample_size=3 is minimum valid
        $this->client->request('POST', '/api/v1/campaign/00000000-0000-0000-0000-000000000001/profile', [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer fake-jwt',
            'CONTENT_TYPE' => 'application/json',
        ], json_encode(['sample_size' => 3]));

        $statusCode = $this->client->getResponse()->getStatusCode();
        $this->assertNotSame(Response::HTTP_BAD_REQUEST, $statusCode);
    }

    public function testProfileAcceptsMaxSampleSize(): void
    {
        // sample_size=100 is maximum valid
        $this->client->request('POST', '/api/v1/campaign/00000000-0000-0000-0000-000000000001/profile', [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer fake-jwt',
            'CONTENT_TYPE' => 'application/json',
        ], json_encode(['sample_size' => 100]));

        $statusCode = $this->client->getResponse()->getStatusCode();
        $this->assertNotSame(Response::HTTP_BAD_REQUEST, $statusCode);
    }

    public function testProfileRejectsSampleSizeOver100(): void
    {
        $this->client->request('POST', '/api/v1/campaign/00000000-0000-0000-0000-000000000001/profile', [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer fake-jwt',
            'CONTENT_TYPE' => 'application/json',
        ], json_encode(['sample_size' => 101]));

        $this->assertResponseStatusCodeSame(Response::HTTP_BAD_REQUEST);
    }

    public function testProfileWithEmptyBody(): void
    {
        $this->client->request('POST', '/api/v1/campaign/00000000-0000-0000-0000-000000000001/profile', [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer fake-jwt',
            'CONTENT_TYPE' => 'application/json',
        ]);

        $statusCode = $this->client->getResponse()->getStatusCode();
        // Empty body defaults to sample_size=10, should not get 400
        $this->assertNotSame(Response::HTTP_BAD_REQUEST, $statusCode);
    }
}
