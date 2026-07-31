<?php

declare(strict_types=1);

namespace App\Tests\Unit\Application\LLM;

use App\Application\LLM\JsonValidator;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for JsonValidator.
 *
 * Covers: parseAndValidate (strict parse, cleanup, structure validation)
 * and validateStructure (required fields, persona, code format).
 */
class JsonValidatorTest extends TestCase
{
    private JsonValidator $validator;

    protected function setUp(): void
    {
        $this->validator = new JsonValidator();
    }

    // ------------------------------------------------------------------ //
    //  parseAndValidate — valid JSON
    // ------------------------------------------------------------------ //

    public function testParseAndValidateWithValidJson(): void
    {
        $json = json_encode([
            'scam_type_code' => 'phishing_email',
            'confidence' => 0.85,
            'is_new_type' => false,
            'label_en' => 'Phishing Email',
            'label_fr' => 'Email de Phishing',
        ], JSON_THROW_ON_ERROR);

        $result = $this->validator->parseAndValidate($json);

        $this->assertTrue($result['success']);
        $this->assertNotNull($result['data']);
        $this->assertSame('phishing_email', $result['data']['scam_type_code']);
        $this->assertEmpty($result['errors']);
    }

    // ------------------------------------------------------------------ //
    //  parseAndValidate — invalid JSON string
    // ------------------------------------------------------------------ //

    public function testParseAndValidateWithTotallyInvalidJson(): void
    {
        $result = $this->validator->parseAndValidate('not json at all {{{');

        $this->assertFalse($result['success']);
        $this->assertNull($result['data']);
        $this->assertNotEmpty($result['errors']);
    }

    // ------------------------------------------------------------------ //
    //  parseAndValidate — markdown-wrapped JSON (cleanup path)
    // ------------------------------------------------------------------ //

    public function testParseAndValidateWithMarkdownWrappedJson(): void
    {
        $wrapped = "```json\n" . json_encode([
            'scam_type_code' => 'romance_scam',
            'confidence' => 0.9,
            'is_new_type' => false,
            'label_en' => 'Romance Scam',
            'label_fr' => 'Arnaque Sentimentale',
        ], JSON_THROW_ON_ERROR) . "\n```";

        $result = $this->validator->parseAndValidate($wrapped);

        $this->assertTrue($result['success']);
        $this->assertNotNull($result['data']);
        $this->assertContains('json_cleanup_applied', $result['errors']);
    }

    public function testParseAndValidateWithJsonSurroundedByText(): void
    {
        $noisy = 'Here is the result: ' . json_encode([
            'scam_type_code' => 'lottery_scam',
            'confidence' => 0.7,
            'is_new_type' => false,
            'label_en' => 'Lottery Scam',
            'label_fr' => 'Arnaque Loterie',
        ], JSON_THROW_ON_ERROR) . ' end of result.';

        $result = $this->validator->parseAndValidate($noisy);

        $this->assertTrue($result['success']);
        $this->assertNotNull($result['data']);
    }

    // ------------------------------------------------------------------ //
    //  parseAndValidate — valid JSON but missing required fields
    // ------------------------------------------------------------------ //

    public function testParseAndValidateWithMissingRequiredFields(): void
    {
        $json = json_encode([
            'scam_type_code' => 'test',
            // missing confidence, is_new_type, label_en, label_fr
        ], JSON_THROW_ON_ERROR);

        $result = $this->validator->parseAndValidate($json);

        $this->assertFalse($result['success']);
        $this->assertNull($result['data']);
        $this->assertNotEmpty($result['errors']);
    }

    // ------------------------------------------------------------------ //
    //  validateStructure — required field checks
    // ------------------------------------------------------------------ //

    public function testValidateStructureAllFieldsPresent(): void
    {
        $data = [
            'scam_type_code' => 'advance_fee',
            'confidence' => 0.8,
            'is_new_type' => false,
            'label_en' => 'Advance Fee',
            'label_fr' => 'Avance de Frais',
        ];

        $result = $this->validator->validateStructure($data);

        $this->assertTrue($result['valid']);
        $this->assertEmpty($result['errors']);
    }

    public function testValidateStructureMissingScamTypeCode(): void
    {
        $data = [
            'confidence' => 0.5,
            'is_new_type' => false,
            'label_en' => 'Test',
            'label_fr' => 'Test',
        ];

        $result = $this->validator->validateStructure($data);

        $this->assertFalse($result['valid']);
        $this->assertContains('scam_type_code missing or invalid', $result['errors']);
    }

    public function testValidateStructureConfidenceOutOfRange(): void
    {
        $data = [
            'scam_type_code' => 'test_type',
            'confidence' => 1.5, // > 1
            'is_new_type' => false,
            'label_en' => 'Test',
            'label_fr' => 'Test',
        ];

        $result = $this->validator->validateStructure($data);

        $this->assertFalse($result['valid']);
        $this->assertContains('confidence missing or invalid (must be 0-1)', $result['errors']);
    }

    public function testValidateStructureConfidenceNegative(): void
    {
        $data = [
            'scam_type_code' => 'test_type',
            'confidence' => -0.1,
            'is_new_type' => false,
            'label_en' => 'Test',
            'label_fr' => 'Test',
        ];

        $result = $this->validator->validateStructure($data);

        $this->assertFalse($result['valid']);
    }

    public function testValidateStructureIsNewTypeNotBool(): void
    {
        $data = [
            'scam_type_code' => 'test_type',
            'confidence' => 0.5,
            'is_new_type' => 'yes', // string, not bool
            'label_en' => 'Test',
            'label_fr' => 'Test',
        ];

        $result = $this->validator->validateStructure($data);

        $this->assertFalse($result['valid']);
        $this->assertContains('is_new_type missing or invalid', $result['errors']);
    }

    public function testValidateStructureMissingLabels(): void
    {
        $data = [
            'scam_type_code' => 'test_type',
            'confidence' => 0.5,
            'is_new_type' => false,
            // missing label_en, label_fr
        ];

        $result = $this->validator->validateStructure($data);

        $this->assertFalse($result['valid']);
        $this->assertContains('label_en missing or invalid', $result['errors']);
        $this->assertContains('label_fr missing or invalid', $result['errors']);
    }

    // ------------------------------------------------------------------ //
    //  validateStructure — scam_type_code format
    // ------------------------------------------------------------------ //

    public function testValidateStructureInvalidScamTypeCodeFormat(): void
    {
        $data = [
            'scam_type_code' => 'INVALID-FORMAT!',
            'confidence' => 0.5,
            'is_new_type' => false,
            'label_en' => 'Test',
            'label_fr' => 'Test',
        ];

        $result = $this->validator->validateStructure($data);

        $this->assertFalse($result['valid']);
        $this->assertContains('scam_type_code must be alphanumeric_underscore (3-30 chars)', $result['errors']);
    }

    public function testValidateStructureAcceptsUppercaseScamTypeCode(): void
    {
        $data = [
            'scam_type_code' => 'ADVANCE_FEE_419',
            'confidence' => 0.92,
            'is_new_type' => false,
            'label_en' => 'Advance Fee Fraud',
            'label_fr' => 'Fraude aux frais anticipés',
        ];

        $result = $this->validator->validateStructure($data);

        $this->assertTrue($result['valid']);
        $this->assertEmpty($result['errors']);
    }

    public function testValidateStructureScamTypeCodeTooShort(): void
    {
        $data = [
            'scam_type_code' => 'ab', // < 3 chars
            'confidence' => 0.5,
            'is_new_type' => false,
            'label_en' => 'Test',
            'label_fr' => 'Test',
        ];

        $result = $this->validator->validateStructure($data);

        $this->assertFalse($result['valid']);
    }

    // ------------------------------------------------------------------ //
    //  validateStructure — persona validation (new type)
    // ------------------------------------------------------------------ //

    public function testValidateStructureNewTypeWithValidPersona(): void
    {
        $data = [
            'scam_type_code' => 'new_scam_type',
            'confidence' => 0.6,
            'is_new_type' => true,
            'label_en' => 'New Scam Type',
            'label_fr' => 'Nouveau Type Arnaque',
            'persona' => [
                'persona_code' => 'worried_investor',
                'persona_label' => 'Worried Investor',
                'persona_tone' => 'anxious',
                'system_prompt' => str_repeat('x', 120), // >= 100 chars
            ],
        ];

        $result = $this->validator->validateStructure($data);

        $this->assertTrue($result['valid']);
        $this->assertEmpty($result['errors']);
    }

    public function testValidateStructureNewTypeWithInvalidPersonaCode(): void
    {
        $data = [
            'scam_type_code' => 'new_scam_type',
            'confidence' => 0.6,
            'is_new_type' => true,
            'label_en' => 'New Scam Type',
            'label_fr' => 'Nouveau Type',
            'persona' => [
                'persona_code' => 'INVALID CODE!',
                'persona_label' => 'Worried Investor',
                'persona_tone' => 'anxious',
                'system_prompt' => str_repeat('x', 120),
            ],
        ];

        $result = $this->validator->validateStructure($data);

        $this->assertFalse($result['valid']);
        $this->assertContains('persona.persona_code must be snake_case (3-30 chars)', $result['errors']);
    }

    public function testValidateStructureNewTypeWithShortSystemPrompt(): void
    {
        $data = [
            'scam_type_code' => 'new_scam_type',
            'confidence' => 0.6,
            'is_new_type' => true,
            'label_en' => 'New Scam Type',
            'label_fr' => 'Nouveau Type',
            'persona' => [
                'persona_code' => 'worried_investor',
                'persona_label' => 'Worried Investor',
                'persona_tone' => 'anxious',
                'system_prompt' => 'too short', // < 100 chars
            ],
        ];

        $result = $this->validator->validateStructure($data);

        $this->assertFalse($result['valid']);
        $this->assertContains('persona.system_prompt too short (min 100 characters)', $result['errors']);
    }

    public function testValidateStructureNewTypePersonaNotObject(): void
    {
        $data = [
            'scam_type_code' => 'new_scam_type',
            'confidence' => 0.6,
            'is_new_type' => true,
            'label_en' => 'New Scam Type',
            'label_fr' => 'Nouveau Type',
            'persona' => 'not an array',
        ];

        $result = $this->validator->validateStructure($data);

        $this->assertFalse($result['valid']);
        $this->assertContains('persona must be an object', $result['errors']);
    }

    public function testValidateStructureNewTypePersonaMissingFields(): void
    {
        $data = [
            'scam_type_code' => 'new_scam_type',
            'confidence' => 0.6,
            'is_new_type' => true,
            'label_en' => 'New Scam Type',
            'label_fr' => 'Nouveau Type',
            'persona' => [
                'persona_code' => 'valid_code',
                // missing persona_label, persona_tone, system_prompt
            ],
        ];

        $result = $this->validator->validateStructure($data);

        $this->assertFalse($result['valid']);
        $this->assertContains('persona.persona_label missing or invalid', $result['errors']);
    }

    // ------------------------------------------------------------------ //
    //  parseAndValidate — boundary values
    // ------------------------------------------------------------------ //

    public function testParseAndValidateWithConfidenceZero(): void
    {
        $json = json_encode([
            'scam_type_code' => 'test_type',
            'confidence' => 0,
            'is_new_type' => false,
            'label_en' => 'Test',
            'label_fr' => 'Test',
        ], JSON_THROW_ON_ERROR);

        $result = $this->validator->parseAndValidate($json);

        $this->assertTrue($result['success']);
    }

    public function testParseAndValidateWithConfidenceOne(): void
    {
        $json = json_encode([
            'scam_type_code' => 'test_type',
            'confidence' => 1,
            'is_new_type' => false,
            'label_en' => 'Test',
            'label_fr' => 'Test',
        ], JSON_THROW_ON_ERROR);

        $result = $this->validator->parseAndValidate($json);

        $this->assertTrue($result['success']);
    }

    public function testParseAndValidateWithEmptyString(): void
    {
        $result = $this->validator->parseAndValidate('');

        $this->assertFalse($result['success']);
        $this->assertNull($result['data']);
    }

    public function testParseAndValidateWithExtraFieldsStillValid(): void
    {
        $json = json_encode([
            'scam_type_code' => 'phishing_email',
            'confidence' => 0.85,
            'is_new_type' => false,
            'label_en' => 'Phishing Email',
            'label_fr' => 'Email de Phishing',
            'extra_field' => 'should be ignored',
            'another_extra' => 42,
        ], JSON_THROW_ON_ERROR);

        $result = $this->validator->parseAndValidate($json);

        $this->assertTrue($result['success']);
        $this->assertNotNull($result['data']);
    }
}
