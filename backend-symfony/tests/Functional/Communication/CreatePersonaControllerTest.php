<?php

declare(strict_types=1);

namespace Tests\Functional\Communication;

use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;

final class CreatePersonaControllerTest extends WebTestCase
{
    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = static::createClient();
    }

    /** @param array<string, mixed> $body */
    private function post(array $body, bool $auth = true): void
    {
        $headers = ['CONTENT_TYPE' => 'application/json'];

        if ($auth) {
            $headers['HTTP_AUTHORIZATION'] = 'Bearer fake-jwt';
        }

        $this->client->request('POST', '/api/v1/personas', [], [], $headers, json_encode($body));
    }

    private function validPrompt(): string
    {
        return trim(str_repeat('You are a test persona created for the CRUD test suite. ', 4));
    }

    public function testRequiresAuthentication(): void
    {
        $this->post(['persona_code' => 'crud_test_persona'], auth: false);
        $this->assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);
    }

    public function testRejects422OnInvalidCode(): void
    {
        $this->post([
            'persona_code' => 'BAD-CODE!!',
            'persona_label' => 'Label',
            'persona_tone' => 'Tone',
            'system_prompt' => $this->validPrompt(),
        ]);
        $this->assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
        $data = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertFalse($data['success']);
        $this->assertStringContainsString('persona_code', $data['error']);
    }

    public function testRejects422OnShortPrompt(): void
    {
        $this->post([
            'persona_code' => 'crud_short_prompt',
            'persona_label' => 'Label',
            'persona_tone' => 'Tone',
            'system_prompt' => 'too short',
        ]);
        $this->assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
    }

    public function testRejects422OnMissingLabel(): void
    {
        $this->post([
            'persona_code' => 'crud_no_label',
            'persona_tone' => 'Tone',
            'system_prompt' => $this->validPrompt(),
        ]);
        $this->assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
    }

    public function testRejects422OnUnknownFields(): void
    {
        $this->post([
            'persona_code' => 'crud_unknown_field',
            'persona_label' => 'Label',
            'persona_tone' => 'Tone',
            'system_prompt' => $this->validPrompt(),
            'malicious' => 'x',
        ]);
        $this->assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
    }

    public function testRejects422WhenIsActiveProvided(): void
    {
        // `is_active` is not part of the create contract (personas are always created
        // active; use the toggle endpoint to deactivate). It must be rejected, not
        // silently ignored.
        $this->post([
            'persona_code' => 'crud_is_active_field',
            'persona_label' => 'Label',
            'persona_tone' => 'Tone',
            'system_prompt' => $this->validPrompt(),
            'is_active' => false,
        ]);
        $this->assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
    }

    public function testCreatesPersonaSuccessfully(): void
    {
        $this->post([
            'persona_code' => 'crud_test_persona',
            'persona_label' => 'CRUD test persona',
            'persona_tone' => 'Neutral, testing',
            'system_prompt' => $this->validPrompt(),
        ]);

        $this->assertResponseStatusCodeSame(Response::HTTP_CREATED);
        $data = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertTrue($data['success']);
        $this->assertSame('crud_test_persona', $data['data']['persona_code']);
        $this->assertSame('operator', $data['data']['created_by']);
        $this->assertTrue($data['data']['is_active']);
    }

    public function testRejects409OnDuplicateCode(): void
    {
        $body = [
            'persona_code' => 'crud_dup_persona',
            'persona_label' => 'Dup persona',
            'persona_tone' => 'Neutral',
            'system_prompt' => $this->validPrompt(),
        ];

        $this->post($body);
        $this->assertResponseStatusCodeSame(Response::HTTP_CREATED);

        // Second create with the same code → 409.
        $this->post($body);
        $this->assertResponseStatusCodeSame(Response::HTTP_CONFLICT);
    }

    public function testRejects400OnUnknownScamType(): void
    {
        $this->post([
            'persona_code' => 'crud_bad_scam',
            'persona_label' => 'Label',
            'persona_tone' => 'Tone',
            'system_prompt' => $this->validPrompt(),
            'scam_type_codes' => ['NONEXISTENT_SCAM'],
        ]);
        $this->assertResponseStatusCodeSame(Response::HTTP_BAD_REQUEST);
    }

    public function testCreatesWithScamTypeLinks(): void
    {
        $this->post([
            'persona_code' => 'crud_linked_persona',
            'persona_label' => 'Linked persona',
            'persona_tone' => 'Neutral',
            'system_prompt' => $this->validPrompt(),
            'scam_type_codes' => ['PHISHING'],
        ]);
        $this->assertResponseStatusCodeSame(Response::HTTP_CREATED);
    }
}
