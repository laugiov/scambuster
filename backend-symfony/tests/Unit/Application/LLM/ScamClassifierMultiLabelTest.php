<?php

declare(strict_types=1);

namespace App\Tests\Unit\Application\LLM;

use App\Application\Communication\ClassificationResult;
use App\Application\Communication\PersonaManager;
use App\Application\Communication\ScamTypeManager;
use App\Application\LLM\JsonValidator;
use App\Application\LLM\Port\LLMClientInterface;
use App\Application\LLM\ScamClassifier;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Unit tests for ScamClassifier multi-label classification support.
 */
final class ScamClassifierMultiLabelTest extends TestCase
{
    private LLMClientInterface $llmClient;
    private ScamTypeManager $scamTypeManager;
    private PersonaManager $personaManager;
    private JsonValidator $jsonValidator;
    private LoggerInterface $logger;
    private ScamClassifier $classifier;

    protected function setUp(): void
    {
        $this->llmClient = $this->createMock(LLMClientInterface::class);
        $this->scamTypeManager = $this->createMock(ScamTypeManager::class);
        $this->personaManager = $this->createMock(PersonaManager::class);
        $this->jsonValidator = $this->createMock(JsonValidator::class);
        $this->logger = $this->createMock(LoggerInterface::class);

        $this->personaManager->method('getAllActive')->willReturn([]);

        $this->classifier = new ScamClassifier(
            $this->llmClient,
            $this->scamTypeManager,
            $this->personaManager,
            $this->jsonValidator,
            $this->logger
        );
    }

    public function testPromptContainsSecondaryTypesInstruction(): void
    {
        $messages = [
            ['msg_id' => 'msg1', 'direction' => 'in', 'subject' => 'Test', 'body_text' => 'Test', 'ts_msg' => '2025-10-27T10:00:00+00:00'],
        ];

        $this->scamTypeManager->method('getAllCodes')->willReturn(['phishing']);

        $capturedMessages = null;
        $this->llmClient->expects($this->once())
            ->method('chat')
            ->willReturnCallback(function (array $msgs) use (&$capturedMessages): string {
                $capturedMessages = $msgs;

                return '{}';
            });

        $this->jsonValidator->method('parseAndValidate')->willReturn([
            'success' => true,
            'data' => ['scam_type_code' => 'phishing', 'confidence' => 0.9, 'is_new_type' => false, 'reasoning' => 'test'],
            'errors' => [],
        ]);

        $this->classifier->classify($messages);

        $this->assertNotNull($capturedMessages);
        $systemPrompt = $capturedMessages[0]['content'];
        $this->assertStringContainsString('secondary_types', $systemPrompt);
    }

    public function testPromptMentionsDecreasingConfidence(): void
    {
        $messages = [
            ['msg_id' => 'msg1', 'direction' => 'in', 'subject' => 'Test', 'body_text' => 'Test', 'ts_msg' => '2025-10-27T10:00:00+00:00'],
        ];

        $this->scamTypeManager->method('getAllCodes')->willReturn(['phishing']);

        $capturedMessages = null;
        $this->llmClient->expects($this->once())
            ->method('chat')
            ->willReturnCallback(function (array $msgs) use (&$capturedMessages): string {
                $capturedMessages = $msgs;

                return '{}';
            });

        $this->jsonValidator->method('parseAndValidate')->willReturn([
            'success' => true,
            'data' => ['scam_type_code' => 'phishing', 'confidence' => 0.9, 'is_new_type' => false, 'reasoning' => 'test'],
            'errors' => [],
        ]);

        $this->classifier->classify($messages);

        $this->assertNotNull($capturedMessages);
        $systemPrompt = $capturedMessages[0]['content'];
        $this->assertStringContainsString('decreasing confidence', $systemPrompt);
    }

    public function testClassifyParsesSecondaryTypes(): void
    {
        $messages = [
            ['msg_id' => 'msg1', 'direction' => 'in', 'subject' => 'Romance + Invoice', 'body_text' => 'Hybrid scam message', 'ts_msg' => '2025-10-27T10:00:00+00:00'],
        ];

        $this->scamTypeManager->method('getAllCodes')->willReturn(['romance', 'invoice_fraud']);

        $this->llmClient->method('chat')->willReturn('{}');

        $this->jsonValidator->method('parseAndValidate')->willReturn([
            'success' => true,
            'data' => [
                'scam_type_code' => 'romance',
                'confidence' => 0.88,
                'is_new_type' => false,
                'reasoning' => 'Hybrid scam',
                'secondary_types' => [
                    ['code' => 'INVOICE_FRAUD', 'confidence' => 0.6],
                    ['code' => 'CHARITY', 'confidence' => 0.3],
                ],
            ],
            'errors' => [],
        ]);

        $result = $this->classifier->classify($messages);

        $this->assertInstanceOf(ClassificationResult::class, $result);
        $this->assertSame('romance', $result->scamTypeCode);
        $this->assertNotNull($result->secondaryTypes);
        $this->assertCount(2, $result->secondaryTypes);
        $this->assertSame('INVOICE_FRAUD', $result->secondaryTypes[0]['code']);
        $this->assertSame(0.6, $result->secondaryTypes[0]['confidence']);
    }

    public function testClassifyHandlesNullSecondaryTypes(): void
    {
        $messages = [
            ['msg_id' => 'msg1', 'direction' => 'in', 'subject' => 'Phishing', 'body_text' => 'Simple phishing', 'ts_msg' => '2025-10-27T10:00:00+00:00'],
        ];

        $this->scamTypeManager->method('getAllCodes')->willReturn(['phishing']);

        $this->llmClient->method('chat')->willReturn('{}');

        $this->jsonValidator->method('parseAndValidate')->willReturn([
            'success' => true,
            'data' => [
                'scam_type_code' => 'phishing',
                'confidence' => 0.95,
                'is_new_type' => false,
                'reasoning' => 'Simple phishing',
            ],
            'errors' => [],
        ]);

        $result = $this->classifier->classify($messages);

        $this->assertInstanceOf(ClassificationResult::class, $result);
        $this->assertNull($result->secondaryTypes);
    }

    public function testClassifyHandlesEmptySecondaryTypesArray(): void
    {
        $messages = [
            ['msg_id' => 'msg1', 'direction' => 'in', 'subject' => 'Test', 'body_text' => 'Test', 'ts_msg' => '2025-10-27T10:00:00+00:00'],
        ];

        $this->scamTypeManager->method('getAllCodes')->willReturn(['phishing']);

        $this->llmClient->method('chat')->willReturn('{}');

        $this->jsonValidator->method('parseAndValidate')->willReturn([
            'success' => true,
            'data' => [
                'scam_type_code' => 'phishing',
                'confidence' => 0.90,
                'is_new_type' => false,
                'reasoning' => 'test',
                'secondary_types' => [],
            ],
            'errors' => [],
        ]);

        $result = $this->classifier->classify($messages);

        $this->assertInstanceOf(ClassificationResult::class, $result);
        // Empty array should be treated as null (no secondary types)
        $this->assertNull($result->secondaryTypes);
    }
}
