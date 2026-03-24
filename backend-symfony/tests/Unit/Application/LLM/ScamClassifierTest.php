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

final class ScamClassifierTest extends TestCase
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

        // Mock getAllActive for all tests
        $this->personaManager->method('getAllActive')->willReturn([]);

        $this->classifier = new ScamClassifier(
            $this->llmClient,
            $this->scamTypeManager,
            $this->personaManager,
            $this->jsonValidator,
            $this->logger
        );
    }

    public function testItReturnsNullForEmptyMessages(): void
    {
        $result = $this->classifier->classify([]);

        $this->assertNull($result);
    }

    public function testItClassifiesExistingScamType(): void
    {
        $messages = [['msg_id' => 'msg1', 'direction' => 'in', 'subject' => 'Urgent payment', 'body_text' => 'Please send money immediately', 'ts_msg' => '2025-10-27T10:00:00+00:00']];

        $this->scamTypeManager->expects($this->once())->method('getAllCodes')->willReturn(['phishing', 'invoice_scam', 'unknown']);

        $llmResponse = json_encode(['scam_type_code' => 'phishing', 'confidence' => 0.95, 'is_new_type' => false, 'label_en' => 'Phishing', 'label_fr' => 'Hameçonnage', 'reasoning' => 'Classic phishing pattern detected'], JSON_THROW_ON_ERROR);

        $this->llmClient->expects($this->once())->method('chat')->willReturn($llmResponse);

        $this->jsonValidator->expects($this->once())->method('parseAndValidate')->with($llmResponse)->willReturn(['success' => true, 'data' => ['scam_type_code' => 'phishing', 'confidence' => 0.95, 'is_new_type' => false, 'label_en' => 'Phishing', 'label_fr' => 'Hameçonnage', 'reasoning' => 'Classic phishing pattern detected'], 'errors' => []]);

        $result = $this->classifier->classify($messages);

        $this->assertInstanceOf(ClassificationResult::class, $result);
        $this->assertSame('phishing', $result->scamTypeCode);
        $this->assertSame(0.95, $result->confidence);
        $this->assertFalse($result->isNewType);
    }

    public function testItClassifiesNewScamTypeWithPersona(): void
    {
        $messages = [['msg_id' => 'msg1', 'direction' => 'in', 'subject' => 'Crypto investment opportunity', 'body_text' => 'Invest in our new cryptocurrency', 'ts_msg' => '2025-10-27T10:00:00+00:00']];

        $this->scamTypeManager->expects($this->once())->method('getAllCodes')->willReturn(['phishing', 'invoice_scam', 'unknown']);

        $llmResponse = json_encode(['scam_type_code' => 'crypto_scam', 'confidence' => 0.88, 'is_new_type' => true, 'label_en' => 'Cryptocurrency Scam', 'label_fr' => 'Arnaque aux cryptomonnaies', 'reasoning' => 'New crypto investment scam pattern', 'suggested_persona_codes' => ['investor_greedy', 'debtor_desperate', 'generic_user']], JSON_THROW_ON_ERROR);

        $this->llmClient->expects($this->once())->method('chat')->willReturn($llmResponse);

        $this->jsonValidator->expects($this->once())->method('parseAndValidate')->with($llmResponse)->willReturn(['success' => true, 'data' => ['scam_type_code' => 'crypto_scam', 'confidence' => 0.88, 'is_new_type' => true, 'label_en' => 'Cryptocurrency Scam', 'label_fr' => 'Arnaque aux cryptomonnaies', 'reasoning' => 'New crypto investment scam pattern', 'suggested_persona_codes' => ['investor_greedy', 'debtor_desperate', 'generic_user']], 'errors' => []]);

        $result = $this->classifier->classify($messages);

        $this->assertInstanceOf(ClassificationResult::class, $result);
        $this->assertSame('crypto_scam', $result->scamTypeCode);
        $this->assertSame(0.88, $result->confidence);
        $this->assertTrue($result->isNewType);
        $this->assertNotNull($result->getSuggestedPersonaCodes());
        $this->assertCount(3, $result->getSuggestedPersonaCodes());
    }

    public function testItReturnsNullWhenJsonValidationFails(): void
    {
        $messages = [['msg_id' => 'msg1', 'direction' => 'in', 'subject' => 'Test', 'body_text' => 'Test message', 'ts_msg' => '2025-10-27T10:00:00+00:00']];

        $this->scamTypeManager->expects($this->once())->method('getAllCodes')->willReturn(['phishing']);

        $llmResponse = 'invalid json response';

        $this->llmClient->expects($this->once())->method('chat')->willReturn($llmResponse);

        $this->jsonValidator->expects($this->once())->method('parseAndValidate')->with($llmResponse)->willReturn(['success' => false, 'data' => null, 'errors' => ['Invalid JSON']]);

        $result = $this->classifier->classify($messages);

        $this->assertNull($result);
    }

    public function testItReturnsNullWhenLlmClientThrowsException(): void
    {
        $messages = [['msg_id' => 'msg1', 'direction' => 'in', 'subject' => 'Test', 'body_text' => 'Test message', 'ts_msg' => '2025-10-27T10:00:00+00:00']];

        $this->scamTypeManager->expects($this->once())->method('getAllCodes')->willReturn(['phishing']);

        $this->llmClient->expects($this->once())->method('chat')->willThrowException(new \RuntimeException('LLM API error'));

        $result = $this->classifier->classify($messages);

        $this->assertNull($result);
    }

    public function testItHandlesMultipleMessages(): void
    {
        $messages = [['msg_id' => 'msg1', 'direction' => 'in', 'subject' => 'Hello', 'body_text' => 'First message', 'ts_msg' => '2025-10-27T10:00:00+00:00'], ['msg_id' => 'msg2', 'direction' => 'out', 'subject' => 'Re: Hello', 'body_text' => 'Second message', 'ts_msg' => '2025-10-27T11:00:00+00:00']];

        $this->scamTypeManager->expects($this->once())->method('getAllCodes')->willReturn(['phishing']);

        $llmResponse = json_encode(['scam_type_code' => 'phishing', 'confidence' => 0.90, 'is_new_type' => false, 'label_en' => 'Phishing', 'label_fr' => 'Hameçonnage', 'reasoning' => 'Pattern detected'], JSON_THROW_ON_ERROR);

        $this->llmClient->expects($this->once())->method('chat')->willReturn($llmResponse);

        $this->jsonValidator->expects($this->once())->method('parseAndValidate')->willReturn(['success' => true, 'data' => ['scam_type_code' => 'phishing', 'confidence' => 0.90, 'is_new_type' => false, 'reasoning' => 'Pattern detected'], 'errors' => []]);

        $result = $this->classifier->classify($messages);

        $this->assertInstanceOf(ClassificationResult::class, $result);
        $this->assertSame('phishing', $result->scamTypeCode);
    }

    public function testItUsesCorrectLlmParameters(): void
    {
        $messages = [['msg_id' => 'msg1', 'direction' => 'in', 'subject' => 'Test', 'body_text' => 'Test', 'ts_msg' => '2025-10-27T10:00:00+00:00']];

        $this->scamTypeManager->expects($this->once())->method('getAllCodes')->willReturn(['phishing']);

        $this->llmClient->expects($this->once())->method('chat')->with($this->anything(), $this->equalTo(['temperature' => 0.3, 'max_tokens' => 1000, 'purpose' => 'classification']))->willReturn('{"scam_type_code":"phishing","confidence":0.9,"is_new_type":false,"reasoning":"test"}');

        $this->jsonValidator->method('parseAndValidate')->willReturn(['success' => true, 'data' => ['scam_type_code' => 'phishing', 'confidence' => 0.9, 'is_new_type' => false, 'reasoning' => 'test'], 'errors' => []]);

        $this->classifier->classify($messages);
    }
}
