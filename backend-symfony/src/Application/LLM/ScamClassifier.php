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
     * @return ClassificationResult|null Returns null if classification fails
     */
    public function classify(array $messages): ?ClassificationResult
    {
        if (empty($messages)) {
            $this->logger->warning('Cannot classify empty conversation');
            return null;
        }

        // Get known scam types and available personas for the prompt
        $knownTypes = $this->scamTypeManager->getAllCodes();
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

            // Extract suggested persona codes for new types
            /** @var string[]|null $suggestedPersonaCodes */
            $suggestedPersonaCodes = null;

            /** @var array{label_en: string, label_fr: string}|null $newTypeData */
            $newTypeData = null;

            if (isset($data['is_new_type']) && $data['is_new_type']) {
                // Extract suggested persona codes
                if (isset($data['suggested_persona_codes']) && is_array($data['suggested_persona_codes'])) {
                    $suggestedPersonaCodes = array_filter($data['suggested_persona_codes'], 'is_string');
                }

                // Extract labels for new type
                if (isset($data['label_en']) && is_string($data['label_en'])
                    && isset($data['label_fr']) && is_string($data['label_fr'])) {
                    $newTypeData = [
                        'label_en' => $data['label_en'],
                        'label_fr' => $data['label_fr'],
                    ];
                }
            }

            $scamTypeCode = isset($data['scam_type_code']) && is_string($data['scam_type_code']) ? $data['scam_type_code'] : 'unknown';
            $confidence = isset($data['confidence']) && is_numeric($data['confidence']) ? (float) $data['confidence'] : 0.0;
            $isNewType = isset($data['is_new_type']) && is_bool($data['is_new_type']) ? $data['is_new_type'] : false;
            $reasoning = isset($data['reasoning']) && is_string($data['reasoning']) ? $data['reasoning'] : 'No reasoning provided';

            return new ClassificationResult(
                scamTypeCode: $scamTypeCode,
                confidence: $confidence,
                isNewType: $isNewType,
                isNewPersona: false, // No longer creating new personas, just suggesting existing ones
                personaCode: null, // Deprecated - use suggestedPersonaCodes instead
                reasoning: $reasoning,
                personaData: $newTypeData,
                suggestedPersonaCodes: $suggestedPersonaCodes
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
     * @param array<int, array<string, mixed>> $messages
     * @param string[] $knownTypes
     * @param array<int, \App\Domain\Communication\Persona> $availablePersonas
     * @return array{system: string, user: string}
     */
    private function buildClassificationPrompt(array $messages, array $knownTypes, array $availablePersonas): array
    {
        $knownTypesList = implode(', ', $knownTypes);

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

        $systemPrompt = <<<PROMPT
Vous êtes un expert en cybersécurité spécialisé dans la détection de scams.

Analysez le contenu de cette conversation et classifiez le type de scam.

Types de scams connus : {$knownTypesList}

Si aucun type connu ne correspond EXACTEMENT, vous pouvez proposer un NOUVEAU type de scam.

Personas disponibles (27 personas avec prompts déjà optimisés) :
{$personasListText}

Répondez au format JSON strict suivant :
{
  "scam_type_code": "code_du_type",
  "confidence": 0.85,
  "is_new_type": false,
  "label_en": "Label in English",
  "label_fr": "Label en français",
  "reasoning": "Explication courte de votre décision",
  "suggested_persona_codes": ["persona_code_1", "persona_code_2", "persona_code_3"]
}

Règles :
1. Si type connu, utilisez son code exact et mettez "suggested_persona_codes": null
2. is_new_type = false si type connu, true si nouveau
3. Si nouveau type: proposez code snake_case + suggérez 3-5 personas EXISTANTS les plus adaptés parmi la liste ci-dessus
4. confidence entre 0 et 1 (minimum 0.75 pour être accepté)
5. reasoning max 200 caractères
6. suggested_persona_codes: array de 3-5 codes de personas existants (pour nouveau type uniquement)

IMPORTANT: Ne créez PAS de nouveaux personas. Utilisez UNIQUEMENT les personas de la liste disponible ci-dessus.

Exemples de réponse:

Type connu (phishing):
{
  "scam_type_code": "phishing",
  "confidence": 0.92,
  "is_new_type": false,
  "label_en": "Phishing",
  "label_fr": "Hameçonnage",
  "reasoning": "Email frauduleux demandant des identifiants bancaires",
  "suggested_persona_codes": null
}

Nouveau type (fake_delivery):
{
  "scam_type_code": "fake_delivery",
  "confidence": 0.88,
  "is_new_type": true,
  "label_en": "Fake Delivery Scam",
  "label_fr": "Fausse livraison",
  "reasoning": "Faux message de livraison demandant paiement frais douane",
  "suggested_persona_codes": ["buyer_eager", "seller_trusting", "student_busy", "elderly_person", "generic_user"]
}
PROMPT;

        $conversationText = $this->formatMessagesForPrompt($messages);

        $userPrompt = <<<PROMPT
Voici la conversation à analyser :

{$conversationText}

Analysez cette conversation et retournez le JSON de classification.
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

            if ($subject !== '') {
                $formatted[] = "=== {$direction} ===\nSujet: {$subject}\n{$body}";
            } else {
                $formatted[] = "=== {$direction} ===\n{$body}";
            }
        }

        return implode("\n\n", $formatted);
    }
}
