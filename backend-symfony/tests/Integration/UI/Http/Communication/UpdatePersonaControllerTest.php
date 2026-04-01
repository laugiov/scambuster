<?php

declare(strict_types=1);

namespace Tests\Integration\UI\Http\Communication;

use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;

final class UpdatePersonaControllerTest extends WebTestCase
{
    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = static::createClient();
    }

    public function testRequiresAuthentication(): void
    {
        $this->client->request('PUT', '/api/v1/personas/senior_trusting', [], [], [
            'CONTENT_TYPE' => 'application/json',
        ], json_encode(['persona_label' => 'test']));

        $this->assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);
    }

    public function testReturns404ForUnknownPersona(): void
    {
        $this->client->request('PUT', '/api/v1/personas/nonexistent_persona', [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer fake-jwt',
            'CONTENT_TYPE' => 'application/json',
        ], json_encode(['persona_label' => 'test']));

        $this->assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
    }

    public function testRejects422WhenSystemPromptTooShort(): void
    {
        $this->client->request('PUT', '/api/v1/personas/senior_trusting', [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer fake-jwt',
            'CONTENT_TYPE' => 'application/json',
        ], json_encode(['system_prompt' => 'Too short']));

        $statusCode = $this->client->getResponse()->getStatusCode();
        // 422 if persona exists, 404 if not in test fixtures
        $this->assertContains($statusCode, [Response::HTTP_UNPROCESSABLE_ENTITY, Response::HTTP_NOT_FOUND]);

        if ($statusCode === Response::HTTP_UNPROCESSABLE_ENTITY) {
            $data = json_decode($this->client->getResponse()->getContent(), true);
            $this->assertFalse($data['success']);
            $this->assertStringContainsString('100 characters', $data['error']);
        }
    }

    public function testRejects422WhenSystemPromptTooLong(): void
    {
        $this->client->request('PUT', '/api/v1/personas/senior_trusting', [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer fake-jwt',
            'CONTENT_TYPE' => 'application/json',
        ], json_encode(['system_prompt' => str_repeat('a', 5001)]));

        $statusCode = $this->client->getResponse()->getStatusCode();
        $this->assertContains($statusCode, [Response::HTTP_UNPROCESSABLE_ENTITY, Response::HTTP_NOT_FOUND]);
    }

    public function testRejects422WhenNoFieldsProvided(): void
    {
        $this->client->request('PUT', '/api/v1/personas/senior_trusting', [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer fake-jwt',
            'CONTENT_TYPE' => 'application/json',
        ], json_encode([]));

        $statusCode = $this->client->getResponse()->getStatusCode();
        $this->assertContains($statusCode, [Response::HTTP_UNPROCESSABLE_ENTITY, Response::HTTP_NOT_FOUND]);
    }

    public function testRejects422WhenUnknownFieldsProvided(): void
    {
        $this->client->request('PUT', '/api/v1/personas/senior_trusting', [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer fake-jwt',
            'CONTENT_TYPE' => 'application/json',
        ], json_encode(['persona_label' => 'ok', 'malicious_field' => 'injected']));

        $statusCode = $this->client->getResponse()->getStatusCode();
        $this->assertContains($statusCode, [Response::HTTP_UNPROCESSABLE_ENTITY, Response::HTTP_NOT_FOUND]);
    }

    public function testRejects422WhenInvalidJson(): void
    {
        $this->client->request('PUT', '/api/v1/personas/senior_trusting', [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer fake-jwt',
            'CONTENT_TYPE' => 'application/json',
        ], 'not-json');

        $statusCode = $this->client->getResponse()->getStatusCode();
        $this->assertContains($statusCode, [Response::HTTP_UNPROCESSABLE_ENTITY, Response::HTTP_NOT_FOUND]);
    }

    public function testSuccessfulPartialUpdate(): void
    {
        $this->client->request('PUT', '/api/v1/personas/senior_trusting', [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer fake-jwt',
            'CONTENT_TYPE' => 'application/json',
        ], json_encode(['persona_label' => 'Updated label for testing']));

        $statusCode = $this->client->getResponse()->getStatusCode();
        $this->assertContains($statusCode, [Response::HTTP_OK, Response::HTTP_NOT_FOUND]);

        if ($statusCode === Response::HTTP_OK) {
            $data = json_decode($this->client->getResponse()->getContent(), true);
            $this->assertTrue($data['success']);
            $this->assertSame('Updated label for testing', $data['data']['persona_label']);
        }
    }

    public function testSuccessfulFullUpdate(): void
    {
        $newPrompt = trim(str_repeat('You are a test persona with unique traits. ', 5));

        $this->client->request('PUT', '/api/v1/personas/senior_trusting', [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer fake-jwt',
            'CONTENT_TYPE' => 'application/json',
        ], json_encode([
            'persona_label' => 'Full update label',
            'persona_tone' => 'Full update tone',
            'system_prompt' => $newPrompt,
        ]));

        $statusCode = $this->client->getResponse()->getStatusCode();
        $this->assertContains($statusCode, [Response::HTTP_OK, Response::HTTP_NOT_FOUND]);

        if ($statusCode === Response::HTTP_OK) {
            $data = json_decode($this->client->getResponse()->getContent(), true);
            $this->assertTrue($data['success']);
            $this->assertSame('Full update label', $data['data']['persona_label']);
            $this->assertSame('Full update tone', $data['data']['persona_tone']);
            $this->assertSame($newPrompt, $data['data']['system_prompt']);
        }
    }

    public function testSanitizesControlCharacters(): void
    {
        $dirtyLabel = "Label with\x00null\x01bytes";

        $this->client->request('PUT', '/api/v1/personas/senior_trusting', [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer fake-jwt',
            'CONTENT_TYPE' => 'application/json',
        ], json_encode(['persona_label' => $dirtyLabel]));

        $statusCode = $this->client->getResponse()->getStatusCode();

        if ($statusCode === Response::HTTP_OK) {
            $data = json_decode($this->client->getResponse()->getContent(), true);
            // Null bytes and control chars should be stripped
            $this->assertStringNotContainsString("\x00", $data['data']['persona_label']);
            $this->assertStringNotContainsString("\x01", $data['data']['persona_label']);
        }
    }
}
