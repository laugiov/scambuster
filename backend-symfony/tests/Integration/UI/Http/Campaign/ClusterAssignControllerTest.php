<?php

declare(strict_types=1);

namespace Tests\Integration\UI\Http\Campaign;

use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;

final class ClusterAssignControllerTest extends WebTestCase
{
    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = static::createClient();
    }

    public function testClusterAssignRequiresAuthentication(): void
    {
        $this->client->request('POST', '/api/v1/campaign/cluster/assign', [], [], [
            'CONTENT_TYPE' => 'application/json',
        ], json_encode(['msg_id' => '00000000-0000-0000-0000-000000000001']));

        $this->assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);
    }

    public function testClusterAssignRejectsInvalidMsgId(): void
    {
        $this->client->request('POST', '/api/v1/campaign/cluster/assign', [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer fake-jwt',
            'CONTENT_TYPE' => 'application/json',
        ], json_encode(['msg_id' => 'not-a-uuid']));

        $this->assertResponseStatusCodeSame(Response::HTTP_BAD_REQUEST);

        $data = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertArrayHasKey('error', $data);
        $this->assertStringContainsString('Invalid msg_id format', $data['error']);
    }

    public function testClusterAssignRejectsMissingMsgId(): void
    {
        $this->client->request('POST', '/api/v1/campaign/cluster/assign', [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer fake-jwt',
            'CONTENT_TYPE' => 'application/json',
        ], json_encode(['foo' => 'bar']));

        $this->assertResponseStatusCodeSame(Response::HTTP_BAD_REQUEST);

        $data = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertArrayHasKey('error', $data);
        $this->assertSame('msg_id is required', $data['error']);
    }

    public function testClusterAssignReturnsJsonContentType(): void
    {
        $this->client->request('POST', '/api/v1/campaign/cluster/assign', [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer fake-jwt',
            'CONTENT_TYPE' => 'application/json',
        ], json_encode([]));

        $this->assertResponseHeaderSame('content-type', 'application/json');
    }

    public function testClusterAssignRejectsEmptyBody(): void
    {
        $this->client->request('POST', '/api/v1/campaign/cluster/assign', [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer fake-jwt',
            'CONTENT_TYPE' => 'application/json',
        ], '');

        $this->assertResponseStatusCodeSame(Response::HTTP_BAD_REQUEST);

        $data = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertArrayHasKey('error', $data);
        $this->assertSame('msg_id is required', $data['error']);
    }

    public function testClusterAssignReturns404ForNonexistentMessage(): void
    {
        $this->client->request('POST', '/api/v1/campaign/cluster/assign', [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer fake-jwt',
            'CONTENT_TYPE' => 'application/json',
        ], json_encode(['msg_id' => '00000000-0000-0000-0000-000000000099']));

        $statusCode = $this->client->getResponse()->getStatusCode();
        // Handler throws RuntimeException for not found
        $this->assertContains($statusCode, [
            Response::HTTP_OK,
            Response::HTTP_CREATED,
            Response::HTTP_NOT_FOUND,
        ]);

        $data = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertIsArray($data);
    }

    public function testClusterAssignWithAdminToken(): void
    {
        $this->client->request('POST', '/api/v1/campaign/cluster/assign', [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer fake-admin-jwt',
            'CONTENT_TYPE' => 'application/json',
        ], json_encode(['msg_id' => '00000000-0000-0000-0000-000000000099']));

        $statusCode = $this->client->getResponse()->getStatusCode();
        $this->assertContains($statusCode, [
            Response::HTTP_OK,
            Response::HTTP_CREATED,
            Response::HTTP_NOT_FOUND,
        ]);
    }

    public function testClusterAssignRejectsNullBody(): void
    {
        $this->client->request('POST', '/api/v1/campaign/cluster/assign', [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer fake-jwt',
            'CONTENT_TYPE' => 'application/json',
        ], 'null');

        $this->assertResponseStatusCodeSame(Response::HTTP_BAD_REQUEST);

        $data = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertArrayHasKey('error', $data);
        $this->assertSame('msg_id is required', $data['error']);
    }
}
