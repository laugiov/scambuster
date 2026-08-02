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

    /**
     * Build a real ScamType entity for stubbing getAll().
     */
    private function makeScamType(string $code, string $description = ''): \App\Domain\Communication\ScamType
    {
        return new \App\Domain\Communication\ScamType($code, $code, $description, 'rsit:fraud="scam"', null, true);
    }

    public function testPromptListsTypesWithDescriptions(): void
    {
        $messages = [['msg_id' => 'msg1', 'direction' => 'in', 'subject' => 'Test', 'body_text' => 'Test body', 'ts_msg' => '2026-06-01T10:00:00+00:00']];

        $this->scamTypeManager->expects($this->once())->method('getAll')->willReturn([
            $this->makeScamType('PHISHING', 'Generic phishing attempt'),
            $this->makeScamType('INVOICE_FRAUD', 'Fake invoice or bank-details change'),
            $this->makeScamType('COLD_SERVICE_SPAM', 'Unsolicited cold commercial service outreach (SEO, web/app development, marketing)'),
        ]);

        $captured = '';
        $this->llmClient->expects($this->once())->method('chat')
            ->willReturnCallback(function (array $msgs) use (&$captured) {
                $captured = ($msgs[0]['content'] ?? '') . "\n---USER---\n" . ($msgs[1]['content'] ?? '');

                return '{"scam_type_code":"COLD_SERVICE_SPAM","confidence":0.8,"is_new_type":false,"reasoning":"test"}';
            });
        $this->jsonValidator->method('parseAndValidate')->willReturn(['success' => true, 'data' => ['scam_type_code' => 'COLD_SERVICE_SPAM', 'confidence' => 0.8, 'is_new_type' => false, 'reasoning' => 'test'], 'errors' => []]);

        $this->classifier->classify($messages);

        // Each type is listed as "CODE — description", not a bare code.
        $this->assertStringContainsString('COLD_SERVICE_SPAM — Unsolicited cold commercial service outreach', $captured);
        $this->assertStringContainsString('PHISHING — Generic phishing attempt', $captured);
        $this->assertStringContainsString('INVOICE_FRAUD — Fake invoice or bank-details change', $captured);
        // Not a bare comma-joined code list anymore.
        $this->assertStringNotContainsString('PHISHING, INVOICE_FRAUD', $captured);
    }

    public function testItReturnsNullForEmptyMessages(): void
    {
        $result = $this->classifier->classify([]);

        $this->assertNull($result);
    }

    public function testItClassifiesExistingScamType(): void
    {
        $messages = [['msg_id' => 'msg1', 'direction' => 'in', 'subject' => 'Urgent payment', 'body_text' => 'Please send money immediately', 'ts_msg' => '2025-10-27T10:00:00+00:00']];

        $this->scamTypeManager->expects($this->once())->method('getAll')->willReturn([$this->makeScamType('phishing'), $this->makeScamType('invoice_scam'), $this->makeScamType('unknown')]);

        $llmResponse = json_encode(['scam_type_code' => 'phishing', 'confidence' => 0.95, 'is_new_type' => false, 'label_en' => 'Phishing', 'label_fr' => 'Hameçonnage', 'reasoning' => 'Classic phishing pattern detected'], JSON_THROW_ON_ERROR);

        $this->llmClient->expects($this->once())->method('chat')->willReturn($llmResponse);

        $this->jsonValidator->expects($this->once())->method('parseAndValidate')->with($llmResponse)->willReturn(['success' => true, 'data' => ['scam_type_code' => 'phishing', 'confidence' => 0.95, 'is_new_type' => false, 'label_en' => 'Phishing', 'label_fr' => 'Hameçonnage', 'reasoning' => 'Classic phishing pattern detected'], 'errors' => []]);

        $result = $this->classifier->classify($messages);

        $this->assertInstanceOf(ClassificationResult::class, $result);
        $this->assertSame('phishing', $result->scamTypeCode);
        $this->assertSame(0.95, $result->confidence);
        $this->assertFalse($result->isNewType);
    }

    /**
     * LLM-driven new scam_type creation is disabled.
     * Renamed from `testItClassifiesNewScamTypeWithPersona`. The test now documents
     * the DEFENSIVE behavior: even when the LLM disobeys and returns is_new_type=true
     * with suggested_persona_codes, the parser overrides BOTH fields.
     *
     */
    public function testLegacyDeprecated_isNewTypeAlwaysFalse(): void
    {
        $messages = [['msg_id' => 'msg1', 'direction' => 'in', 'subject' => 'Crypto investment opportunity', 'body_text' => 'Invest in our new cryptocurrency', 'ts_msg' => '2025-10-27T10:00:00+00:00']];

        $this->scamTypeManager->expects($this->once())->method('getAll')->willReturn([$this->makeScamType('phishing'), $this->makeScamType('invoice_scam'), $this->makeScamType('unknown')]);

        $llmResponse = json_encode(['scam_type_code' => 'crypto_scam', 'confidence' => 0.88, 'is_new_type' => true, 'label_en' => 'Cryptocurrency Scam', 'label_fr' => 'Arnaque aux cryptomonnaies', 'reasoning' => 'New crypto investment scam pattern', 'suggested_persona_codes' => ['investor_greedy', 'debtor_desperate', 'generic_user']], JSON_THROW_ON_ERROR);

        $this->llmClient->expects($this->once())->method('chat')->willReturn($llmResponse);

        $this->jsonValidator->expects($this->once())->method('parseAndValidate')->with($llmResponse)->willReturn(['success' => true, 'data' => ['scam_type_code' => 'crypto_scam', 'confidence' => 0.88, 'is_new_type' => true, 'label_en' => 'Cryptocurrency Scam', 'label_fr' => 'Arnaque aux cryptomonnaies', 'reasoning' => 'New crypto investment scam pattern', 'suggested_persona_codes' => ['investor_greedy', 'debtor_desperate', 'generic_user']], 'errors' => []]);

        $result = $this->classifier->classify($messages);

        $this->assertInstanceOf(ClassificationResult::class, $result);
        $this->assertSame('crypto_scam', $result->scamTypeCode);
        $this->assertSame(0.88, $result->confidence);
        // These MUST be forced regardless of LLM output
        $this->assertFalse($result->isNewType, 'Parser must force isNewType=false');
        $this->assertNull($result->getSuggestedPersonaCodes(), 'Parser must drop suggested_persona_codes when isNewType is forced false');
    }

    public function testItReturnsNullWhenJsonValidationFails(): void
    {
        $messages = [['msg_id' => 'msg1', 'direction' => 'in', 'subject' => 'Test', 'body_text' => 'Test message', 'ts_msg' => '2025-10-27T10:00:00+00:00']];

        $this->scamTypeManager->expects($this->once())->method('getAll')->willReturn([$this->makeScamType('phishing')]);

        $llmResponse = 'invalid json response';

        $this->llmClient->expects($this->once())->method('chat')->willReturn($llmResponse);

        $this->jsonValidator->expects($this->once())->method('parseAndValidate')->with($llmResponse)->willReturn(['success' => false, 'data' => null, 'errors' => ['Invalid JSON']]);

        $result = $this->classifier->classify($messages);

        $this->assertNull($result);
    }

    public function testItReturnsNullWhenLlmClientThrowsException(): void
    {
        $messages = [['msg_id' => 'msg1', 'direction' => 'in', 'subject' => 'Test', 'body_text' => 'Test message', 'ts_msg' => '2025-10-27T10:00:00+00:00']];

        $this->scamTypeManager->expects($this->once())->method('getAll')->willReturn([$this->makeScamType('phishing')]);

        $this->llmClient->expects($this->once())->method('chat')->willThrowException(new \RuntimeException('LLM API error'));

        $result = $this->classifier->classify($messages);

        $this->assertNull($result);
    }

    public function testItHandlesMultipleMessages(): void
    {
        $messages = [['msg_id' => 'msg1', 'direction' => 'in', 'subject' => 'Hello', 'body_text' => 'First message', 'ts_msg' => '2025-10-27T10:00:00+00:00'], ['msg_id' => 'msg2', 'direction' => 'out', 'subject' => 'Re: Hello', 'body_text' => 'Second message', 'ts_msg' => '2025-10-27T11:00:00+00:00']];

        $this->scamTypeManager->expects($this->once())->method('getAll')->willReturn([$this->makeScamType('phishing')]);

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

        $this->scamTypeManager->expects($this->once())->method('getAll')->willReturn([$this->makeScamType('phishing')]);

        $this->llmClient->expects($this->once())->method('chat')->with($this->anything(), $this->equalTo(['temperature' => 0.3, 'max_tokens' => 1000, 'purpose' => 'classification']))->willReturn('{"scam_type_code":"phishing","confidence":0.9,"is_new_type":false,"reasoning":"test"}');

        $this->jsonValidator->method('parseAndValidate')->willReturn(['success' => true, 'data' => ['scam_type_code' => 'phishing', 'confidence' => 0.9, 'is_new_type' => false, 'reasoning' => 'test'], 'errors' => []]);

        $this->classifier->classify($messages);
    }

    /**
     * Defensive parser: even if the LLM disobeys the prompt
     * instruction and returns is_new_type=true, the parser MUST force it to false.
     * This guarantees no new scam_type rows are created via the LLM path,
     * regardless of LLM compliance.
     *
     */
    public function testItForcesIsNewTypeToFalseEvenWhenLLMReturnsTrue(): void
    {
        $messages = [['msg_id' => 'msg1', 'direction' => 'in', 'subject' => 'Novel scam', 'body_text' => 'Some new pattern', 'ts_msg' => '2026-06-01T10:00:00+00:00']];

        $this->scamTypeManager->expects($this->once())->method('getAll')->willReturn([$this->makeScamType('phishing'), $this->makeScamType('unknown')]);

        // Simulate LLM disobeying the prompt and returning is_new_type=true
        $llmData = [
            'scam_type_code' => 'novel_authority_scam',
            'confidence' => 0.92,
            'is_new_type' => true, // ← LLM disobedience
            'label_en' => 'Novel scam',
            'label_fr' => 'Nouvelle arnaque',
            'reasoning' => 'A pattern not seen before',
            'suggested_persona_codes' => ['generic_user'],
        ];

        $this->llmClient->expects($this->once())->method('chat')->willReturn(json_encode($llmData, JSON_THROW_ON_ERROR));
        $this->jsonValidator->expects($this->once())->method('parseAndValidate')->willReturn(['success' => true, 'data' => $llmData, 'errors' => []]);

        $result = $this->classifier->classify($messages);

        $this->assertInstanceOf(ClassificationResult::class, $result);
        $this->assertFalse($result->isNewType, 'Parser MUST force isNewType to false regardless of LLM output');
        $this->assertNull($result->getSuggestedPersonaCodes(), 'When isNewType is forced to false, suggested_persona_codes must also be null');
    }

    /**
     * Prompt layer: the generated classification prompt
     * MUST NOT instruct or invite the LLM to create new types. It must
     * instruct the LLM to fall back to 'UNKNOWN' when no known type matches.
     *
     */
    /**
     * ScamClassifier prompt is now in English to eliminate
     * code-switching with the 90 % EN corpus. The prompt content must contain
     * EN markers and NO key French markers from the previous version.
     *
     */
    public function testPromptIsInEnglish(): void
    {
        $messages = [['msg_id' => 'msg1', 'direction' => 'in', 'subject' => 'Test', 'body_text' => 'Test body', 'ts_msg' => '2026-06-01T10:00:00+00:00']];
        $this->scamTypeManager->expects($this->once())->method('getAll')->willReturn([$this->makeScamType('PHISHING')]);

        $captured = '';
        $this->llmClient->expects($this->once())->method('chat')
            ->willReturnCallback(function (array $msgs) use (&$captured) {
                $captured = ($msgs[0]['content'] ?? '') . "\n---USER---\n" . ($msgs[1]['content'] ?? '');

                return '{"scam_type_code":"PHISHING","confidence":0.9,"is_new_type":false,"reasoning":"test"}';
            });
        $this->jsonValidator->method('parseAndValidate')->willReturn(['success' => true, 'data' => ['scam_type_code' => 'PHISHING', 'confidence' => 0.9, 'is_new_type' => false, 'reasoning' => 'test'], 'errors' => []]);

        $this->classifier->classify($messages);

        // Negative: no French markers
        $this->assertStringNotContainsString('Vous êtes', $captured, 'FR intro phrase must be gone');
        $this->assertStringNotContainsString('Analysez', $captured, 'FR Analysez verb must be gone');
        $this->assertStringNotContainsString('Types de scams', $captured, 'FR Types de scams must be gone');
        $this->assertStringNotContainsString('Règle pour', $captured, 'FR Règle pour must be gone');
        $this->assertStringNotContainsString('Voici la conversation', $captured, 'FR Voici must be gone');
        // Positive: EN markers present
        $this->assertStringContainsString('You are', $captured, 'EN intro phrase must be present');
        $this->assertStringContainsString('Analyze', $captured, 'EN Analyze verb must be present');
        $this->assertStringContainsString('Known scam types', $captured, 'EN Known scam types must be present');
    }

    public function testPromptForbidsNewTypeCreation(): void
    {
        $messages = [['msg_id' => 'msg1', 'direction' => 'in', 'subject' => 'Test', 'body_text' => 'Test body', 'ts_msg' => '2026-06-01T10:00:00+00:00']];

        $this->scamTypeManager->expects($this->once())->method('getAll')->willReturn([$this->makeScamType('PHISHING'), $this->makeScamType('INVOICE_FRAUD')]);

        // Capture the prompt sent to LLM via the chat() mock
        $capturedPrompt = '';
        $this->llmClient->expects($this->once())->method('chat')
            ->willReturnCallback(function (array $messages) use (&$capturedPrompt) {
                $capturedPrompt = ($messages[0]['content'] ?? '') . "\n---USER---\n" . ($messages[1]['content'] ?? '');

                return '{"scam_type_code":"PHISHING","confidence":0.9,"is_new_type":false,"reasoning":"test"}';
            });
        $this->jsonValidator->method('parseAndValidate')->willReturn(['success' => true, 'data' => ['scam_type_code' => 'PHISHING', 'confidence' => 0.9, 'is_new_type' => false, 'reasoning' => 'test'], 'errors' => []]);

        $this->classifier->classify($messages);

        $this->assertStringNotContainsStringIgnoringCase('NOUVEAU type', $capturedPrompt, 'Prompt must not encourage new type creation (FR)');
        $this->assertStringNotContainsStringIgnoringCase('propose a new type', $capturedPrompt, 'Prompt must not encourage new type creation (EN)');
        $this->assertStringContainsStringIgnoringCase('UNKNOWN', $capturedPrompt, 'Prompt must mention UNKNOWN as the fallback code');
    }
}
