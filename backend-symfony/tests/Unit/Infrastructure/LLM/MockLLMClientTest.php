<?php

declare(strict_types=1);

namespace App\Tests\Unit\Infrastructure\LLM;

use App\Infrastructure\LLM\Provider\MockLLMClient;
use PHPUnit\Framework\TestCase;

class MockLLMClientTest extends TestCase
{
    private MockLLMClient $client;

    protected function setUp(): void
    {
        $this->client = new MockLLMClient();
    }

    public function test_returns_default_reply_for_generic_input(): void
    {
        $result = $this->client->chat([['role' => 'user', 'content' => 'Hello there']]);
        $this->assertIsString($result);
        $this->assertNotEmpty($result);
        $this->assertStringContainsString('Merci', $result);
    }

    public function test_returns_campaign_profile_for_profile_request(): void
    {
        $result = $this->client->chat([['role' => 'user', 'content' => 'Profile this campaign of phishing emails']]);
        $this->assertStringContainsString('campaign:', $result);
        $this->assertStringContainsString('tactics:', $result);
    }

    public function test_returns_compiled_rule_for_dsl_request(): void
    {
        $result = $this->client->chat([['role' => 'user', 'content' => 'Compile DSL rules for this campaign']]);
        $this->assertStringContainsString('RULE', $result);
        $this->assertStringContainsString('WHERE', $result);
    }

    public function test_returns_validator_json_for_evaluation_request(): void
    {
        $result = $this->client->chat([['role' => 'user', 'content' => 'Texte à valider: Bonjour']]);
        $data = json_decode($result, true);
        $this->assertIsArray($data);
        $this->assertArrayHasKey('naturalness', $data);
        $this->assertArrayHasKey('persona_fit', $data);
        $this->assertArrayHasKey('security_pass', $data);
    }

    public function test_returns_validator_json_for_english_evaluation(): void
    {
        $result = $this->client->chat([['role' => 'user', 'content' => 'Score each dimension of this reply']]);
        $data = json_decode($result, true);
        $this->assertIsArray($data);
        $this->assertSame(4, $data['naturalness']);
    }

    public function test_returns_contextual_enrichment_for_ioc_request(): void
    {
        $result = $this->client->chat([['role' => 'user', 'content' => 'Analyze ioc_roles for this message']]);
        $data = json_decode($result, true);
        $this->assertIsArray($data);
        $this->assertArrayHasKey('stimulus_type', $data);
        $this->assertArrayHasKey('ioc_roles', $data);
        $this->assertSame('DIRECT_REQUEST', $data['stimulus_type']);
    }

    public function test_returns_classification_for_classify_request(): void
    {
        $result = $this->client->chat([['role' => 'user', 'content' => 'Please classify this conversation as a scam_type']]);
        $data = json_decode($result, true);
        $this->assertIsArray($data);
        $this->assertSame('PHISHING', $data['scam_type']);
        $this->assertSame(0.85, $data['confidence']);
    }

    public function test_handles_empty_messages(): void
    {
        $result = $this->client->chat([]);
        $this->assertIsString($result);
    }
}
