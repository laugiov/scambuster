<?php

declare(strict_types=1);

namespace App\Application\LLM;

use App\Application\Communication\ClassificationResult;
use App\Application\Communication\PersonaManager;
use App\Application\Communication\ScamTypeManager;
use App\Application\LLM\Port\LLMClientInterface;
use Psr\Log\LoggerInterface;

/**
 * LLM-based scam classification service
 */
class ScamClassifier
{
    public function __construct(
        private readonly LLMClientInterface $llmClient,
        private readonly ScamTypeManager $scamTypeManager,
        private readonly PersonaManager $personaManager,
        private readonly JsonValidator $jsonValidator,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * Classify conversation messages and optionally create new scam type + persona
     *
     * @param array<int, array<string, mixed>> $messages Conversation messages
     *
     * @return ClassificationResult|null Returns null if classification fails
     */
    public function classify(array $messages): ?ClassificationResult
    {
        if ($messages === []) {
            $this->logger->warning('Cannot classify empty conversation');

            return null;
        }

        // Get known scam types (with descriptions) and available personas
        // for the prompt.
        $knownTypes = $this->scamTypeManager->getAll();
        $availablePersonas = $this->personaManager->getAllActive();

        // Build classification prompt
        $prompt = $this->buildClassificationPrompt($messages, $knownTypes, $availablePersonas);

        try {
            // Call LLM
            $llmMessages = [
                ['role' => 'system', 'content' => $prompt['system']],
                ['role' => 'user', 'content' => $prompt['user']],
            ];

            $response = $this->llmClient->chat($llmMessages, [
                'temperature' => 0.3,
                'max_tokens' => 1000,
                'purpose' => 'classification',
            ]);

            // Parse and validate JSON response
            $result = $this->jsonValidator->parseAndValidate($response);

            if (!$result['success']) {
                $this->logger->error('LLM classification JSON validation failed', [
                    'errors' => $result['errors'],
                    'response' => substr($response, 0, 500),
                ]);

                return null;
            }

            $data = $result['data'];

            if ($data === null) {
                $this->logger->error('LLM classification returned null data');

                return null;
            }

            // LLM-driven new scam_type creation is disabled.
            // The prompt forbids it; this defensive parser layer enforces it
            // even if the LLM disobeys and returns is_new_type=true.
            /** @var string[]|null $suggestedPersonaCodes */
            $suggestedPersonaCodes = null;
            /** @var array{label_en: string, label_fr: string}|null $newTypeData */
            $newTypeData = null;

            $scamTypeCode = isset($data['scam_type_code']) && is_string($data['scam_type_code']) ? $data['scam_type_code'] : 'unknown';
            $confidence = isset($data['confidence']) && is_numeric($data['confidence']) ? (float) $data['confidence'] : 0.0;
            $isNewType = false; // Forced regardless of LLM output — see comment above.
            $reasoning = isset($data['reasoning']) && is_string($data['reasoning']) ? $data['reasoning'] : 'No reasoning provided';
            $detectedLanguage = isset($data['detected_language']) && is_string($data['detected_language']) ? $data['detected_language'] : 'en';

            // Parse secondary_types from LLM response
            /** @var array<int, array{code: string, confidence: float}>|null $secondaryTypes */
            $secondaryTypes = null;

            if (isset($data['secondary_types']) && is_array($data['secondary_types']) && $data['secondary_types'] !== []) {
                $secondaryTypes = [];

                foreach ($data['secondary_types'] as $entry) {
                    if (is_array($entry) && isset($entry['code']) && is_string($entry['code'])
                        && isset($entry['confidence']) && is_numeric($entry['confidence'])) {
                        $secondaryTypes[] = [
                            'code' => $entry['code'],
                            'confidence' => (float) $entry['confidence'],
                        ];
                    }
                }

                if ($secondaryTypes === []) {
                    $secondaryTypes = null;
                }
            }

            return new ClassificationResult(
                scamTypeCode: $scamTypeCode,
                confidence: $confidence,
                isNewType: $isNewType,
                isNewPersona: false,
                personaCode: null,
                reasoning: $reasoning,
                personaData: $newTypeData,
                suggestedPersonaCodes: $suggestedPersonaCodes,
                detectedLanguage: $detectedLanguage,
                secondaryTypes: $secondaryTypes,
            );

        } catch (\Exception $e) {
            $this->logger->error('LLM classification failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return null;
        }
    }

    /**
     * Build classification prompt for LLM
     *
     * @param array<int, array<string, mixed>>               $messages
     * @param array<int, \App\Domain\Communication\ScamType> $knownTypes
     * @param array<int, \App\Domain\Communication\Persona>  $availablePersonas
     *
     * @return array{system: string, user: string}
     */
    private function buildClassificationPrompt(array $messages, array $knownTypes, array $availablePersonas): array
    {
        // List each type as "CODE — description" so the model can tell
        // categories apart (e.g. COLD_SERVICE_SPAM vs INVOICE_FRAUD)
        // instead of guessing from a bare code. Descriptions come from
        // the DB, so the list stays in sync with the taxonomy.
        $knownTypeLines = [];

        foreach ($knownTypes as $type) {
            $description = $type->getDescription();
            $knownTypeLines[] = $description !== null && $description !== ''
                ? sprintf('  - %s — %s', $type->getCode(), $description)
                : sprintf('  - %s', $type->getCode());
        }
        $knownTypesList = "\n" . implode("\n", $knownTypeLines);

        // Format available personas list
        $personasList = [];

        foreach ($availablePersonas as $persona) {
            $personasList[] = sprintf(
                '  - %s: %s (%s)',
                $persona->getPersonaCode(),
                $persona->getPersonaLabel(),
                $persona->getPersonaTone()
            );
        }
        $personasListText = implode("\n", $personasList);

        // Prompt no longer encourages new type creation.
        // The LLM must map every conversation to a known code OR use UNKNOWN.
        // Prompt is in EN to eliminate code-switching with the
        // predominantly EN corpus.
        $systemPrompt = <<<PROMPT
You are a cybersecurity expert specialized in scam detection.

Analyze the conversation below and classify the scam type.

Known scam types: {$knownTypesList}

IMPORTANT — Use ONLY a code from the list above. Never invent a code. If no known type matches, use scam_type_code='UNKNOWN'. The is_new_type field MUST ALWAYS be false.

Available personas:
{$personasListText}

Respond in the following strict JSON format:
{
  "scam_type_code": "type_code",
  "confidence": 0.85,
  "is_new_type": false,
  "label_en": "Label in English",
  "label_fr": "Label en français",
  "reasoning": "Short explanation of your decision",
  "suggested_persona_codes": null,
  "detected_language": "en",
  "secondary_types": [
    {"code": "ROMANCE", "confidence": 0.6},
    {"code": "INVOICE_FRAUD", "confidence": 0.4}
  ]
}

Rule for detected_language: ISO 639-1 code (en, fr, es, de, pt, it, nl, etc.) of the PRIMARY language of the scam message content. Base it on the message text, NOT the headers.

Rules:
1. Use ONLY a code from the list of known types (or 'UNKNOWN' if none matches)
2. is_new_type MUST ALWAYS be false — never invent a code outside the known list
3. suggested_persona_codes MUST ALWAYS be null — persona selection is handled separately
4. confidence between 0 and 1 (minimum 0.55 to be accepted)
5. reasoning max 200 characters
6. If the scam exhibits characteristics of multiple categories, list them in secondary_types with decreasing confidence. The primary scam_type_code remains the strongest match. If only one type applies, set secondary_types to null or omit it.

Example responses:

Known type (phishing):
{
  "scam_type_code": "phishing",
  "confidence": 0.92,
  "is_new_type": false,
  "label_en": "Phishing",
  "label_fr": "Hameçonnage",
  "reasoning": "Fraudulent email requesting banking credentials",
  "suggested_persona_codes": null,
  "detected_language": "fr",
  "secondary_types": null
}

Hybrid scam (romance + invoice):
{
  "scam_type_code": "ROMANCE",
  "confidence": 0.85,
  "is_new_type": false,
  "label_en": "Romance Scam",
  "label_fr": "Arnaque sentimentale",
  "reasoning": "Romance scam with invoice fraud elements",
  "suggested_persona_codes": null,
  "detected_language": "en",
  "secondary_types": [
    {"code": "INVOICE_FRAUD", "confidence": 0.6},
    {"code": "ADVANCE_FEE_419", "confidence": 0.3}
  ]
}

No known type matches (use UNKNOWN):
{
  "scam_type_code": "UNKNOWN",
  "confidence": 0.78,
  "is_new_type": false,
  "label_en": "Unknown",
  "label_fr": "Inconnu",
  "reasoning": "The message does not match any known type with sufficient certainty",
  "suggested_persona_codes": null,
  "detected_language": "en",
  "secondary_types": null
}
PROMPT;

        $conversationText = $this->formatMessagesForPrompt($messages);

        $userPrompt = <<<PROMPT
Conversation to analyze:

{$conversationText}

Analyze this conversation and return the classification JSON.
PROMPT;

        return [
            'system' => $systemPrompt,
            'user' => $userPrompt,
        ];
    }

    /**
     * Format messages for prompt
     *
     * @param array<int, array<string, mixed>> $messages
     */
    private function formatMessagesForPrompt(array $messages): string
    {
        $formatted = [];

        foreach ($messages as $msg) {
            $msgDirection = isset($msg['direction']) && is_string($msg['direction']) ? $msg['direction'] : 'unknown';
            $direction = $msgDirection === 'in' ? 'Attaquant' : 'Victime';
            $msgBody = isset($msg['body_text']) && is_string($msg['body_text']) ? $msg['body_text'] : '';
            $body = trim($msgBody);
            $subject = isset($msg['subject']) && is_string($msg['subject']) ? $msg['subject'] : '';

            $formatted[] = $subject !== '' ? "=== {$direction} ===\nSujet: {$subject}\n{$body}" : "=== {$direction} ===\n{$body}";
        }

        return implode("\n\n", $formatted);
    }
}
