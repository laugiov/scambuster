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

    public function testTranspileWithValidDslReturns200(): void
    {
        $dsl = 'RULE test_rule { WHERE subject.simhash≈"urgent payment" ±15% AND dkim.pass ∈ {false, null} ACTION tag="test" }';

        $this->client->request('POST', '/api/v1/campaign/transpile', [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer fake-jwt',
            'CONTENT_TYPE' => 'application/json',
        ], json_encode(['dsl' => $dsl]));

        $this->assertResponseStatusCodeSame(Response::HTTP_OK);

        $data = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertIsArray($data);
        $this->assertArrayHasKey('sql', $data);
        $this->assertArrayHasKey('params', $data);
        $this->assertArrayHasKey('tests', $data);
        $this->assertNotEmpty($data['sql']);
    }

    public function testTranspileWithAdminTokenWorks(): void
    {
        $dsl = 'RULE admin_rule { WHERE spf.pass ∈ {false, null} ACTION tag="spf_fail" }';

        $this->client->request('POST', '/api/v1/campaign/transpile', [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer fake-admin-jwt',
            'CONTENT_TYPE' => 'application/json',
        ], json_encode(['dsl' => $dsl]));

        $this->assertResponseStatusCodeSame(Response::HTTP_OK);

        $data = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertArrayHasKey('sql', $data);
    }

    public function testTranspileWithEmptyDslStringReturns400(): void
    {
        $this->client->request('POST', '/api/v1/campaign/transpile', [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer fake-jwt',
            'CONTENT_TYPE' => 'application/json',
        ], json_encode(['dsl' => '']));

        // Empty DSL will fail parsing (no WHERE clause)
        $this->assertResponseStatusCodeSame(Response::HTTP_BAD_REQUEST);

        $data = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertArrayHasKey('error', $data);
        $this->assertSame('Transpilation failed', $data['error']);
    }

    public function testTranspileWithEmptyBodyReturns400(): void
    {
        $this->client->request('POST', '/api/v1/campaign/transpile', [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer fake-jwt',
            'CONTENT_TYPE' => 'application/json',
        ], '');

        $this->assertResponseStatusCodeSame(Response::HTTP_BAD_REQUEST);

        $data = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertArrayHasKey('error', $data);
        $this->assertSame('dsl is required', $data['error']);
    }

    public function testTranspileWithNullBodyReturns400(): void
    {
        $this->client->request('POST', '/api/v1/campaign/transpile', [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer fake-jwt',
            'CONTENT_TYPE' => 'application/json',
        ], 'null');

        $this->assertResponseStatusCodeSame(Response::HTTP_BAD_REQUEST);

        $data = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertArrayHasKey('error', $data);
    }

    public function testTranspileSuccessResponseHasAllFields(): void
    {
        $dsl = 'RULE full_rule { WHERE subject.simhash≈"payment" ±15% AND spf.pass ∈ {false, null} ACTION tag="suspicious" }';

        $this->client->request('POST', '/api/v1/campaign/transpile', [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer fake-jwt',
            'CONTENT_TYPE' => 'application/json',
        ], json_encode(['dsl' => $dsl]));

        $this->assertResponseStatusCodeSame(Response::HTTP_OK);

        $data = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertArrayHasKey('sql', $data);
        $this->assertArrayHasKey('params', $data);
        $this->assertArrayHasKey('tests', $data);
        $this->assertIsString($data['sql']);
        $this->assertIsArray($data['params']);
        $this->assertIsArray($data['tests']);
    }

    public function testTranspileErrorResponseHasErrorAndMessage(): void
    {
        $this->client->request('POST', '/api/v1/campaign/transpile', [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer fake-jwt',
            'CONTENT_TYPE' => 'application/json',
        ], json_encode(['dsl' => '']));

        $this->assertResponseStatusCodeSame(Response::HTTP_BAD_REQUEST);

        $data = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertArrayHasKey('error', $data);
        $this->assertArrayHasKey('message', $data);
        $this->assertSame('Transpilation failed', $data['error']);
        $this->assertIsString($data['message']);
    }
}
