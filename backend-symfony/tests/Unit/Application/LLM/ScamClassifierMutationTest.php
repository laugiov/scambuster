<?php

declare(strict_types=1);

namespace App\Tests\Unit\Application\LLM;

use App\Application\Communication\PersonaManager;
use App\Application\Communication\ScamTypeManager;
use App\Application\LLM\JsonValidator;
use App\Application\LLM\Port\LLMClientInterface;
use App\Application\LLM\ScamClassifier;
use App\Domain\Communication\Persona;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Mutation-killing tests for ScamClassifier.
 *
 * Targets:
 * - Empty messages => null return
 * - LLM messages array structure (system + user roles)
 * - Temperature 0.3, max_tokens 1000
 * - JSON validation failure => null
 * - Null data => null
 * - scam_type_code extraction
 * - confidence extraction and float cast
 * - is_new_type boolean check
 * - suggested_persona_codes extraction
 * - label_en / label_fr extraction for new types
 * - reasoning extraction
 * - detected_language extraction
 * - Default values: 'unknown', 0.0, 'No reasoning provided', 'en'
 * - Exception handling => null
 * - Prompt contains known types and personas
 * - Message formatting: direction, subject
 */
final class ScamClassifierMutationTest extends TestCase
{
    private LLMClientInterface&MockObject $llmClient;
    private ScamTypeManager&MockObject $scamTypeManager;
    private PersonaManager&MockObject $personaManager;
    private JsonValidator&MockObject $jsonValidator;
    private ScamClassifier $classifier;

    protected function setUp(): void
    {
        $this->llmClient = $this->createMock(LLMClientInterface::class);
        $this->scamTypeManager = $this->createMock(ScamTypeManager::class);
        $this->personaManager = $this->createMock(PersonaManager::class);
        $this->jsonValidator = $this->createMock(JsonValidator::class);

        $this->scamTypeManager->method('getAllCodes')->willReturn(['PHISHING', 'ROMANCE']);

        $persona = $this->createMock(Persona::class);
        $persona->method('getPersonaCode')->willReturn('elderly_person');
        $persona->method('getPersonaLabel')->willReturn('Elderly Person');
        $persona->method('getPersonaTone')->willReturn('Confused');
        $this->personaManager->method('getAllActive')->willReturn([$persona]);

        $this->classifier = new ScamClassifier(
            $this->llmClient,
            $this->scamTypeManager,
            $this->personaManager,
            $this->jsonValidator,
            new NullLogger(),
        );
    }

    // === Empty messages ===

    public function test_empty_messages_returns_null(): void
    {
        $result = $this->classifier->classify([]);
        $this->assertNull($result);
    }

    // === Successful classification ===

    public function test_successful_classification_returns_result(): void
    {
        $this->llmClient->method('chat')->willReturn('{"scam_type_code":"PHISHING"}');
        $this->jsonValidator->method('parseAndValidate')->willReturn([
            'success' => true,
            'data' => [
                'scam_type_code' => 'PHISHING',
                'confidence' => 0.92,
                'is_new_type' => false,
                'reasoning' => 'Classic phishing email',
                'detected_language' => 'en',
            ],
            'errors' => [],
        ]);

        $result = $this->classifier->classify([['direction' => 'in', 'body_text' => 'Click here']]);

        $this->assertNotNull($result);
        $this->assertSame('PHISHING', $result->scamTypeCode);
        $this->assertSame(0.92, $result->confidence);
        $this->assertFalse($result->isNewType);
        $this->assertSame('Classic phishing email', $result->reasoning);
        $this->assertSame('en', $result->detectedLanguage);
    }

    // === JSON validation failure ===

    public function test_json_validation_failure_returns_null(): void
    {
        $this->llmClient->method('chat')->willReturn('garbage');
        $this->jsonValidator->method('parseAndValidate')->willReturn([
            'success' => false,
            'data' => null,
            'errors' => ['Invalid JSON'],
        ]);

        $result = $this->classifier->classify([['direction' => 'in', 'body_text' => 'text']]);
        $this->assertNull($result);
    }

    // === Null data ===

    public function test_null_data_returns_null(): void
    {
        $this->llmClient->method('chat')->willReturn('{}');
        $this->jsonValidator->method('parseAndValidate')->willReturn([
            'success' => true,
            'data' => null,
            'errors' => [],
        ]);

        $result = $this->classifier->classify([['direction' => 'in', 'body_text' => 'text']]);
        $this->assertNull($result);
    }

    // === Default values when fields missing ===

    public function test_missing_scam_type_code_defaults_to_unknown(): void
    {
        $this->llmClient->method('chat')->willReturn('{}');
        $this->jsonValidator->method('parseAndValidate')->willReturn([
            'success' => true,
            'data' => ['confidence' => 0.5],
            'errors' => [],
        ]);

        $result = $this->classifier->classify([['direction' => 'in', 'body_text' => 'text']]);
        $this->assertSame('unknown', $result->scamTypeCode);
    }

    public function test_missing_confidence_defaults_to_0(): void
    {
        $this->llmClient->method('chat')->willReturn('{}');
        $this->jsonValidator->method('parseAndValidate')->willReturn([
            'success' => true,
            'data' => ['scam_type_code' => 'PHISHING'],
            'errors' => [],
        ]);

        $result = $this->classifier->classify([['direction' => 'in', 'body_text' => 'text']]);
        $this->assertSame(0.0, $result->confidence);
    }

    public function test_missing_reasoning_defaults_to_no_reasoning(): void
    {
        $this->llmClient->method('chat')->willReturn('{}');
        $this->jsonValidator->method('parseAndValidate')->willReturn([
            'success' => true,
            'data' => ['scam_type_code' => 'PHISHING'],
            'errors' => [],
        ]);

        $result = $this->classifier->classify([['direction' => 'in', 'body_text' => 'text']]);
        $this->assertSame('No reasoning provided', $result->reasoning);
    }

    public function test_missing_language_defaults_to_en(): void
    {
        $this->llmClient->method('chat')->willReturn('{}');
        $this->jsonValidator->method('parseAndValidate')->willReturn([
            'success' => true,
            'data' => ['scam_type_code' => 'PHISHING'],
            'errors' => [],
        ]);

        $result = $this->classifier->classify([['direction' => 'in', 'body_text' => 'text']]);
        $this->assertSame('en', $result->detectedLanguage);
    }

    // === New type with persona codes and labels — DEPRECATED ===

    /**
     * Parser now FORCES isNewType=false and drops suggested_persona_codes.
     * Renamed from `test_new_type_extracts_suggested_persona_codes`. The test pins the
     * defensive parser behavior: even when the LLM disobeys and emits is_new_type=true
     * with suggested_persona_codes, the parser ignores both fields.
     *
     */
    public function test_legacy_deprecated_parser_overrides_llm_is_new_type(): void
    {
        $this->llmClient->method('chat')->willReturn('{}');
        $this->jsonValidator->method('parseAndValidate')->willReturn([
            'success' => true,
            'data' => [
                'scam_type_code' => 'FAKE_DELIVERY',
                'confidence' => 0.88,
                'is_new_type' => true, // LLM disobedience
                'suggested_persona_codes' => ['elderly_person', 'generic_user'],
                'label_en' => 'Fake Delivery',
                'label_fr' => 'Fausse livraison',
                'reasoning' => 'New scam type',
                'detected_language' => 'fr',
            ],
            'errors' => [],
        ]);

        $result = $this->classifier->classify([['direction' => 'in', 'body_text' => 'text']]);
        $this->assertFalse($result->isNewType, 'Parser must force isNewType=false');
        $this->assertNull($result->suggestedPersonaCodes, 'Parser must drop suggested_persona_codes when isNewType is forced false');
        $this->assertNull($result->personaData, 'Parser must drop personaData when isNewType is forced false');
    }

    // === Not new type => null persona codes ===

    public function test_known_type_has_null_persona_codes(): void
    {
        $this->llmClient->method('chat')->willReturn('{}');
        $this->jsonValidator->method('parseAndValidate')->willReturn([
            'success' => true,
            'data' => [
                'scam_type_code' => 'PHISHING',
                'confidence' => 0.9,
                'is_new_type' => false,
                'reasoning' => 'Known type',
            ],
            'errors' => [],
        ]);

        $result = $this->classifier->classify([['direction' => 'in', 'body_text' => 'text']]);
        $this->assertFalse($result->isNewType);
        $this->assertNull($result->suggestedPersonaCodes);
    }

    // === LLM exception => null ===

    public function test_llm_exception_returns_null(): void
    {
        $this->llmClient->method('chat')->willThrowException(new \RuntimeException('LLM down'));

        $result = $this->classifier->classify([['direction' => 'in', 'body_text' => 'text']]);
        $this->assertNull($result);
    }

    // === LLM messages structure ===

    public function test_llm_called_with_system_and_user_messages(): void
    {
        $this->llmClient->expects($this->once())
            ->method('chat')
            ->willReturnCallback(function (array $messages, array $options) {
                // Verify system + user messages (kills ArrayItemRemoval on system message)
                $this->assertCount(2, $messages);
                $this->assertSame('system', $messages[0]['role']);
                $this->assertSame('user', $messages[1]['role']);
                $this->assertArrayHasKey('content', $messages[0]);
                $this->assertArrayHasKey('content', $messages[1]);

                return '{}';
            });
        $this->jsonValidator->method('parseAndValidate')->willReturn(['success' => true, 'data' => ['scam_type_code' => 'X'], 'errors' => []]);

        $this->classifier->classify([['direction' => 'in', 'body_text' => 'text']]);
    }

    public function test_llm_options_temperature_and_max_tokens(): void
    {
        $this->llmClient->expects($this->once())
            ->method('chat')
            ->willReturnCallback(function (array $messages, array $options) {
                $this->assertSame(0.3, $options['temperature']);
                $this->assertSame(1000, $options['max_tokens']);
                $this->assertSame('classification', $options['purpose']);

                return '{}';
            });
        $this->jsonValidator->method('parseAndValidate')->willReturn(['success' => true, 'data' => ['scam_type_code' => 'X'], 'errors' => []]);

        $this->classifier->classify([['direction' => 'in', 'body_text' => 'text']]);
    }

    // === Prompt contains known types ===

    public function test_prompt_contains_known_scam_types(): void
    {
        $this->llmClient->expects($this->once())
            ->method('chat')
            ->willReturnCallback(function (array $messages) {
                $systemPrompt = $messages[0]['content'];
                $this->assertStringContainsString('PHISHING', $systemPrompt);
                $this->assertStringContainsString('ROMANCE', $systemPrompt);

                return '{}';
            });
        $this->jsonValidator->method('parseAndValidate')->willReturn(['success' => true, 'data' => ['scam_type_code' => 'X'], 'errors' => []]);

        $this->classifier->classify([['direction' => 'in', 'body_text' => 'text']]);
    }

    // === Message formatting ===

    public function test_inbound_message_labeled_attaquant(): void
    {
        $this->llmClient->expects($this->once())
            ->method('chat')
            ->willReturnCallback(function (array $messages) {
                $userPrompt = $messages[1]['content'];
                $this->assertStringContainsString('Attaquant', $userPrompt);

                return '{}';
            });
        $this->jsonValidator->method('parseAndValidate')->willReturn(['success' => true, 'data' => ['scam_type_code' => 'X'], 'errors' => []]);

        $this->classifier->classify([['direction' => 'in', 'body_text' => 'Scam email content']]);
    }

    public function test_outbound_message_labeled_victime(): void
    {
        $this->llmClient->expects($this->once())
            ->method('chat')
            ->willReturnCallback(function (array $messages) {
                $userPrompt = $messages[1]['content'];
                $this->assertStringContainsString('Victime', $userPrompt);

                return '{}';
            });
        $this->jsonValidator->method('parseAndValidate')->willReturn(['success' => true, 'data' => ['scam_type_code' => 'X'], 'errors' => []]);

        $this->classifier->classify([['direction' => 'out', 'body_text' => 'Reply text']]);
    }

    public function test_message_with_subject_includes_sujet(): void
    {
        $this->llmClient->expects($this->once())
            ->method('chat')
            ->willReturnCallback(function (array $messages) {
                $userPrompt = $messages[1]['content'];
                $this->assertStringContainsString('Sujet:', $userPrompt);
                $this->assertStringContainsString('Important Subject', $userPrompt);

                return '{}';
            });
        $this->jsonValidator->method('parseAndValidate')->willReturn(['success' => true, 'data' => ['scam_type_code' => 'X'], 'errors' => []]);

        $this->classifier->classify([['direction' => 'in', 'body_text' => 'text', 'subject' => 'Important Subject']]);
    }

    public function test_confidence_cast_to_float(): void
    {
        $this->llmClient->method('chat')->willReturn('{}');
        $this->jsonValidator->method('parseAndValidate')->willReturn([
            'success' => true,
            'data' => [
                'scam_type_code' => 'PHISHING',
                'confidence' => '0.85', // string, but is_numeric
            ],
            'errors' => [],
        ]);

        $result = $this->classifier->classify([['direction' => 'in', 'body_text' => 'text']]);
        $this->assertIsFloat($result->confidence);
        $this->assertSame(0.85, $result->confidence);
    }
}
