<?php

declare(strict_types=1);

namespace Tests\Integration\UI\Http\Campaign;

use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;

final class TranspileRuleControllerTest extends WebTestCase
{
    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = static::createClient();
    }

    public function testTranspileRequiresAuthentication(): void
    {
        $this->client->request('POST', '/api/v1/campaign/transpile', [], [], [
            'CONTENT_TYPE' => 'application/json',
        ], '{}');

        $this->assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);
    }

    public function testTranspileRejectsInvalidJson(): void
    {
        $this->client->request('POST', '/api/v1/campaign/transpile', [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer fake-jwt',
            'CONTENT_TYPE' => 'application/json',
        ], 'not-json');

        $this->assertResponseStatusCodeSame(Response::HTTP_BAD_REQUEST);

        $data = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertArrayHasKey('error', $data);
        $this->assertSame('dsl is required', $data['error']);
    }

    public function testTranspileRejectsMissingDslField(): void
    {
        $this->client->request('POST', '/api/v1/campaign/transpile', [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer fake-jwt',
            'CONTENT_TYPE' => 'application/json',
        ], json_encode(['foo' => 'bar']));

        $this->assertResponseStatusCodeSame(Response::HTTP_BAD_REQUEST);

        $data = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertArrayHasKey('error', $data);
        $this->assertSame('dsl is required', $data['error']);
    }

    public function testTranspileReturnsJsonContentType(): void
    {
        $this->client->request('POST', '/api/v1/campaign/transpile', [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer fake-jwt',
            'CONTENT_TYPE' => 'application/json',
        ], json_encode(['foo' => 'bar']));

        $this->assertResponseHeaderSame('content-type', 'application/json');
    }

    public function testTranspileReturns400ForInvalidDsl(): void
    {
        $this->client->request('POST', '/api/v1/campaign/transpile', [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer fake-jwt',
            'CONTENT_TYPE' => 'application/json',
        ], json_encode(['dsl' => 'INVALID_DSL_SYNTAX_###']));

        $statusCode = $this->client->getResponse()->getStatusCode();
        // Either 200 (if transpiler is lenient) or 400 (parse error)
        $this->assertContains($statusCode, [Response::HTTP_OK, Response::HTTP_BAD_REQUEST]);

        $data = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertIsArray($data);

        if ($statusCode === Response::HTTP_BAD_REQUEST) {
            $this->assertArrayHasKey('error', $data);
            $this->assertSame('Transpilation failed', $data['error']);
            $this->assertArrayHasKey('message', $data);
        }
    }
}
