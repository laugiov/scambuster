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
            ['role' => 'user', 'content' => 'Profile this campaign of phishing emails'],
        ];

        $result = $this->client->chat($messages);

        $this->assertStringContainsString('campaign:', $result);
        $this->assertStringContainsString('summary:', $result);
        $this->assertStringContainsString('tactics:', $result);
    }

    public function testReturnsCampaignProfileForSuspiciousEmailContent(): void
    {
        $messages = [
            ['role' => 'user', 'content' => 'Here are suspicious emails to analyze'],
        ];

        $result = $this->client->chat($messages);

        $this->assertStringContainsString('campaign:', $result);
    }

    public function testReturnsCompiledRuleForDslContent(): void
    {
        $messages = [
            ['role' => 'user', 'content' => 'Compile the following DSL rules'],
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
        $this->assertArrayHasKey('naturalness', $data);
        $this->assertArrayHasKey('persona_fit', $data);
        $this->assertArrayHasKey('ti_value', $data);
        $this->assertArrayHasKey('security_pass', $data);
        $this->assertTrue($data['security_pass']);
    }

    public function testReturnsClassificationJsonForClassificationPurpose(): void
    {
        // ScamClassifier sends purpose=classification and reads scam_type_code
        // (falling back to "unknown" if that key is absent). The mock must return
        // that key with a real taxonomy code, mirroring the test FakeLLMClient.
        $result = $this->client->chat(
            [['role' => 'user', 'content' => 'Classify this conversation.']],
            ['purpose' => 'classification'],
        );
        $data = json_decode($result, true, 512, JSON_THROW_ON_ERROR);

        $this->assertIsArray($data);
        $this->assertSame('ADVANCE_FEE_419', $data['scam_type_code'] ?? null);
        $this->assertFalse($data['is_new_type']);
        $this->assertArrayHasKey('confidence', $data);
        $this->assertIsFloat($data['confidence']);
    }

    public function testReturnsQualityAuditJsonForQualityAuditPurpose(): void
    {
        // ConversationQualityAuditor sends purpose=quality_audit and reads a
        // verdict per dimension; the mock must return that shape (mirroring the
        // test FakeLLMClient) so the audit is parseable instead of null.
        $result = $this->client->chat(
            [['role' => 'user', 'content' => 'Audit this conversation.']],
            ['purpose' => 'quality_audit'],
        );
        $data = json_decode($result, true, 512, JSON_THROW_ON_ERROR);

        $this->assertIsArray($data);
        foreach (['classification', 'ioc_completeness', 'urgency', 'semantic_roles', 'risk_score'] as $dimension) {
            $this->assertArrayHasKey($dimension, $data, "missing audit dimension: {$dimension}");
            $this->assertArrayHasKey('verdict', $data[$dimension]);
        }
    }

    public function testReturnsTtpObservationsForTtpExtractionPurpose(): void
    {
        // The mock provider must answer the ttp_extraction purpose with the same
        // shape the real extractor parses (a raw array of {ttp_id, confidence,
        // evidence}), so the keyless demo and the mock-provider CI path both
        // persist observations. Values mirror the test FakeLLMClient: one tactic
        // above and one below the confidence threshold (confirmed vs review).
        $result = $this->client->chat(
            [['role' => 'user', 'content' => 'Extract the scammer tactics from this message.']],
            ['purpose' => 'ttp_extraction'],
        );

        $data = json_decode($result, true, 512, JSON_THROW_ON_ERROR);

        $this->assertIsArray($data);
        $this->assertCount(2, $data);

        foreach ($data as $item) {
            $this->assertArrayHasKey('ttp_id', $item);
            $this->assertArrayHasKey('confidence', $item);
            $this->assertArrayHasKey('evidence', $item);
            $this->assertNotSame('', trim((string) $item['evidence']));
        }

        $byCode = array_column($data, 'confidence', 'ttp_id');
        $this->assertSame(0.92, $byCode['SB-T017'] ?? null, 'A high-confidence tactic must be present (confirmed path)');
        $this->assertSame(0.4, $byCode['SB-T022'] ?? null, 'A low-confidence tactic must be present (review path)');
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

        // The default response asks for more details (scambaiting tactic, EN or FR)
        $this->assertTrue(
            str_contains($result, 'additional details') || str_contains($result, 'informations complémentaires'),
            'Response should ask for more details'
        );
        $this->assertTrue(
            str_contains($result, 'legitimate') || str_contains($result, 'en ordre'),
            'Response should mention legitimacy'
        );
    }
}
