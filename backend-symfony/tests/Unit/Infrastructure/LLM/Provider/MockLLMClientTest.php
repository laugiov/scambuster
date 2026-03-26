<?php

declare(strict_types=1);

namespace App\Tests\Unit\Infrastructure\LLM\Provider;

use App\Application\LLM\Port\LLMClientInterface;
use App\Infrastructure\LLM\Provider\MockLLMClient;
use PHPUnit\Framework\TestCase;

class MockLLMClientTest extends TestCase
{
    private MockLLMClient $client;

    protected function setUp(): void
    {
        $this->client = new MockLLMClient();
    }

    public function testImplementsLLMClientInterface(): void
    {
        $this->assertInstanceOf(LLMClientInterface::class, $this->client);
    }

    public function testReturnsDeterministicDefaultResponse(): void
    {
        $messages = [
            ['role' => 'user', 'content' => 'Hello, how are you?'],
        ];

        $result1 = $this->client->chat($messages);
        $result2 = $this->client->chat($messages);

        $this->assertSame($result1, $result2);
        $this->assertIsString($result1);
        $this->assertNotEmpty($result1);
    }

    public function testReturnsCampaignProfileForCampaignContent(): void
    {
        $messages = [
            ['role' => 'user', 'content' => 'Profile cette campagne de phishing'],
        ];

        $result = $this->client->chat($messages);

        $this->assertStringContainsString('campaign:', $result);
        $this->assertStringContainsString('summary:', $result);
        $this->assertStringContainsString('tactics:', $result);
    }

    public function testReturnsCampaignProfileForSuspiciousEmailContent(): void
    {
        $messages = [
            ['role' => 'user', 'content' => 'Voici des e-mails suspects a analyser'],
        ];

        $result = $this->client->chat($messages);

        $this->assertStringContainsString('campaign:', $result);
    }

    public function testReturnsCompiledRuleForDslContent(): void
    {
        $messages = [
            ['role' => 'user', 'content' => 'Compile les règles DSL suivantes'],
        ];

        $result = $this->client->chat($messages);

        $this->assertStringContainsString('RULE', $result);
        $this->assertStringContainsString('ACTION', $result);
    }

    public function testReturnsCompiledRuleForMailGuardDslContent(): void
    {
        $messages = [
            ['role' => 'user', 'content' => 'Apply MailGuard DSL rules'],
        ];

        $result = $this->client->chat($messages);

        $this->assertStringContainsString('RULE', $result);
    }

    public function testReturnsValidationJsonForValidationContent(): void
    {
        $messages = [
            ['role' => 'user', 'content' => 'Texte à valider: bonjour je suis intéressé'],
        ];

        $result = $this->client->chat($messages);
        $data = json_decode($result, true);

        $this->assertIsArray($data);
        $this->assertArrayHasKey('approved', $data);
        $this->assertTrue($data['approved']);
        $this->assertArrayHasKey('reasons', $data);
        $this->assertIsArray($data['reasons']);
    }

    public function testReturnsClassificationJsonForClassifyContent(): void
    {
        $messages = [
            ['role' => 'user', 'content' => 'classify this email as a scam_type'],
        ];

        $result = $this->client->chat($messages);
        $data = json_decode($result, true);

        $this->assertIsArray($data);
        $this->assertArrayHasKey('scam_type', $data);
        $this->assertSame('PHISHING', $data['scam_type']);
        $this->assertArrayHasKey('confidence', $data);
        $this->assertIsFloat($data['confidence']);
    }

    public function testReturnsStringForAllInputTypes(): void
    {
        // Generic message
        $result = $this->client->chat([
            ['role' => 'user', 'content' => 'random text'],
        ]);
        $this->assertIsString($result);
        $this->assertNotEmpty($result);

        // Empty messages array uses default
        $result = $this->client->chat([]);
        $this->assertIsString($result);

        // Message with missing content key
        $result = $this->client->chat([
            ['role' => 'user'],
        ]);
        $this->assertIsString($result);
    }

    public function testAcceptsOptionsParameter(): void
    {
        $result = $this->client->chat(
            [['role' => 'user', 'content' => 'test']],
            ['temperature' => 0.5, 'max_tokens' => 100, 'model' => 'gpt-4o']
        );

        $this->assertIsString($result);
        $this->assertNotEmpty($result);
    }

    public function testDefaultResponseContainsScambaitingLanguage(): void
    {
        $result = $this->client->chat([
            ['role' => 'user', 'content' => 'I have a business proposition for you'],
        ]);

        // The default response asks for more details (scambaiting tactic)
        $this->assertStringContainsString('additional details', $result);
        $this->assertStringContainsString('legitimate', $result);
    }
}
