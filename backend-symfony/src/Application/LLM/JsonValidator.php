<?php

declare(strict_types=1);

namespace App\Application\LLM;

/**
 * Robust JSON validation for LLM responses
 *
 * Implements 3-level strategy:
 * 1. Strict parsing (json_decode)
 * 2. Auto-cleanup (remove markdown, text noise)
 * 3. LLM corrector (fallback, not implemented yet)
 */
class JsonValidator
{
    /**
     * Parse and validate JSON from LLM response
     *
     * @return array{success: bool, data: array<string, mixed>|null, errors: string[]}
     */
    public function parseAndValidate(string $jsonString): array
    {
        // Level 1: Strict parsing
        $data = $this->parseStrict($jsonString);

        if ($data !== null) {
            $validationResult = $this->validateStructure($data);

            return [
                'success' => $validationResult['valid'],
                'data' => $validationResult['valid'] ? $data : null,
                'errors' => $validationResult['errors'],
            ];
        }

        // Level 2: Auto-cleanup and retry
        $cleaned = $this->cleanupJson($jsonString);
        $data = $this->parseStrict($cleaned);

        if ($data !== null) {
            $validationResult = $this->validateStructure($data);

            if ($validationResult['valid']) {
                return [
                    'success' => true,
                    'data' => $data,
                    'errors' => ['json_cleanup_applied'],
                ];
            }

            return [
                'success' => false,
                'data' => null,
                'errors' => array_merge(['json_cleanup_applied'], $validationResult['errors']),
            ];
        }

        // Level 3 would be LLM corrector (not implemented in this version)
        return [
            'success' => false,
            'data' => null,
            'errors' => ['json_parse_failed_after_cleanup'],
        ];
    }

    /**
     * Validate LLM classification response structure
     *
     * @param array<string, mixed> $data
     *
     * @return array{valid: bool, errors: string[]}
     */
    public function validateStructure(array $data): array
    {
        $errors = [];

        // Required fields
        if (!isset($data['scam_type_code']) || !is_string($data['scam_type_code'])) {
            $errors[] = 'scam_type_code missing or invalid';
        }

        if (!isset($data['confidence']) || !is_numeric($data['confidence']) || $data['confidence'] < 0 || $data['confidence'] > 1) {
            $errors[] = 'confidence missing or invalid (must be 0-1)';
        }

        if (!isset($data['is_new_type']) || !is_bool($data['is_new_type'])) {
            $errors[] = 'is_new_type missing or invalid';
        }

        if (!isset($data['label_en']) || !is_string($data['label_en'])) {
            $errors[] = 'label_en missing or invalid';
        }

        if (!isset($data['label_fr']) || !is_string($data['label_fr'])) {
            $errors[] = 'label_fr missing or invalid';
        }

        // Validate persona if present and is_new_type is true
        if (isset($data['is_new_type']) && $data['is_new_type'] === true && isset($data['persona'])) {
            if (!is_array($data['persona'])) {
                $errors[] = 'persona must be an object';
            } else {
                $requiredPersonaFields = ['persona_code', 'persona_label', 'persona_tone', 'system_prompt'];

                foreach ($requiredPersonaFields as $field) {
                    if (!isset($data['persona'][$field]) || !is_string($data['persona'][$field])) {
                        $errors[] = "persona.{$field} missing or invalid";
                    }
                }

                if (isset($data['persona']['system_prompt']) && is_string($data['persona']['system_prompt']) && strlen($data['persona']['system_prompt']) < 100) {
                    $errors[] = 'persona.system_prompt too short (min 100 characters)';
                }

                if (isset($data['persona']['persona_code']) && is_string($data['persona']['persona_code']) && !preg_match('/^[a-z0-9_]{3,30}$/', $data['persona']['persona_code'])) {
                    $errors[] = 'persona.persona_code must be snake_case (3-30 chars)';
                }
            }
        }

        // Validate codes format (snake_case with digits allowed)
        if (isset($data['scam_type_code']) && is_string($data['scam_type_code']) && !preg_match('/^[a-z0-9_]{3,30}$/', $data['scam_type_code'])) {
            $errors[] = 'scam_type_code must be snake_case (3-30 chars)';
        }

        return [
            'valid' => $errors === [],
            'errors' => $errors,
        ];
    }

    /**
     * Attempt strict JSON parsing
     *
     * @return array<string, mixed>|null
     */
    private function parseStrict(string $jsonString): ?array
    {
        $data = json_decode($jsonString, true);

        if (json_last_error() === JSON_ERROR_NONE && is_array($data)) {
            /** @var array<string, mixed> $data */
            return $data;
        }

        return null;
    }

    /**
     * Clean JSON string from common LLM noise
     */
    private function cleanupJson(string $jsonString): string
    {
        // Remove markdown code blocks
        $cleaned = preg_replace('/```json\s*/i', '', $jsonString);

        if ($cleaned === null) {
            $cleaned = $jsonString;
        }
        $cleaned = preg_replace('/```\s*$/i', '', $cleaned);

        if ($cleaned === null) {
            $cleaned = $jsonString;
        }

        // Trim whitespace
        $cleaned = trim($cleaned);

        // Try to extract JSON if surrounded by text
        if (preg_match('/\{.*\}/s', $cleaned, $matches)) {
            return $matches[0];
        }

        return $cleaned;
    }
}
