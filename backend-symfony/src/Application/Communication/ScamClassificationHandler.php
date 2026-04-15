<?php

declare(strict_types=1);

namespace App\Application\Communication;

use App\Application\LLM\ScamClassifier;
use App\Domain\Communication\Conversation;
use App\Domain\Communication\Persona;
use App\Domain\Communication\ScamType;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Handle scam classification with automatic creation of ScamType + Persona
 */
class ScamClassificationHandler
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ScamClassifier $scamClassifier,
        private readonly PersonaManager $personaManager,
        private readonly ScamTypeManager $scamTypeManager,
        private readonly ConversationHandler $conversationHandler,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * Classify a conversation and update its scam_type
     *
     * @throws \RuntimeException if conversation not found or classification fails
     */
    public function classifyConversation(string $convId): ClassificationResult
    {
        // Get conversation with messages
        $conversation = $this->em->getRepository(Conversation::class)->find($convId);

        if ($conversation === null) {
            throw new \RuntimeException("Conversation not found: {$convId}");
        }

        // Get conversation messages for classification
        $messages = $this->getConversationMessages($convId);

        if ($messages === []) {
            throw new \RuntimeException("Cannot classify conversation without messages: {$convId}");
        }

        // Call LLM classifier
        $result = $this->scamClassifier->classify($messages);

        if (!$result instanceof \App\Application\Communication\ClassificationResult) {
            throw new \RuntimeException('LLM classification failed');
        }

        // Check confidence threshold
        if (!$result->shouldApply(0.75)) {
            $this->logger->warning('Classification confidence too low', [
                'conv_id' => $convId,
                'scam_type' => $result->scamTypeCode,
                'confidence' => $result->confidence,
            ]);

            throw new \RuntimeException("Classification confidence too low: {$result->confidence}");
        }

        // Handle new type + persona creation or use existing
        if ($result->isNewType && $result->isNewPersona) {
            $this->createScamTypeWithPersona($result, $convId);
        } elseif ($result->isNewType && !$result->isNewPersona) {
            $this->createScamTypeWithoutPersona($result);
        }

        // Update conversation scam_type
        $this->updateConversationScamType($convId, $result->scamTypeCode);

        // Store secondary scam types if present
        $this->updateConversationSecondaryTypes($convId, $result->secondaryTypes);

        $this->logger->info('Conversation classified successfully', [
            'conv_id' => $convId,
            'scam_type' => $result->scamTypeCode,
            'confidence' => $result->confidence,
            'is_new_type' => $result->isNewType,
            'is_new_persona' => $result->isNewPersona,
            'secondary_types_count' => $result->secondaryTypes !== null ? count($result->secondaryTypes) : 0,
        ]);

        return $result;
    }

    /**
     * Create new ScamType with associated Persona (atomic transaction)
     */
    private function createScamTypeWithPersona(ClassificationResult $result, string $convId): void
    {
        $personaData = $result->getPersonaData();

        if (!$personaData) {
            throw new \RuntimeException('No label data for new scam type');
        }

        // Get suggested persona codes from LLM
        $suggestedPersonaCodes = $result->getSuggestedPersonaCodes();

        // If we're creating a new persona, use it as the primary persona
        if ($result->isNewPersona && isset($personaData['persona_code'])) {
            // Use the new persona code as the main persona
            if (!$suggestedPersonaCodes) {
                $suggestedPersonaCodes = [$personaData['persona_code']];
            } elseif (!in_array($personaData['persona_code'], $suggestedPersonaCodes, true)) {
                // Add new persona to suggested list
                $suggestedPersonaCodes[] = $personaData['persona_code'];
            }
        } elseif (!$suggestedPersonaCodes) {
            // No new persona - use fallback if needed
            $suggestedPersonaCodes = ['generic_user'];
            $this->logger->warning('No personas suggested by LLM, using generic_user', [
                'scam_type_code' => $result->scamTypeCode,
            ]);
        }

        // Start transaction
        $this->em->beginTransaction();

        try {
            // Create new persona if needed
            if ($result->isNewPersona && isset($personaData['persona_code'])) {
                $existingPersona = $this->personaManager->findByCode($personaData['persona_code']);

                if (!$existingPersona instanceof \App\Domain\Communication\Persona) {
                    try {
                        $newPersona = $this->personaManager->createPersona(
                            personaCode: $personaData['persona_code'],
                            personaLabel: $personaData['persona_label'] ?? $personaData['persona_code'],
                            personaTone: $personaData['persona_tone'] ?? 'Neutral tone',
                            systemPrompt: $personaData['system_prompt'] ?? 'Default system prompt for automatically created persona. ' . str_repeat('x', 100),
                            createdBy: 'llm_auto'
                        );

                        $this->logger->notice('New persona created automatically by LLM', [
                            'persona_code' => $newPersona->getPersonaCode(),
                            'scam_type_code' => $result->scamTypeCode,
                            'created_from_conv_id' => $convId,
                        ]);
                    } catch (\RuntimeException $e) {
                        // If persona creation fails (e.g., validation), log and continue
                        $this->logger->error('Failed to create new persona', [
                            'persona_code' => $personaData['persona_code'],
                            'error' => $e->getMessage(),
                        ]);
                    }
                } else {
                    $this->logger->info('Persona already exists, reusing it', [
                        'persona_code' => $personaData['persona_code'],
                    ]);
                }
            }

            // Check if scam_type already exists
            $existingScamType = $this->scamTypeManager->findByCode($result->scamTypeCode);

            if ($existingScamType instanceof \App\Domain\Communication\ScamType) {
                $this->logger->info('Scam type already exists, skipping creation', [
                    'scam_type_code' => $result->scamTypeCode,
                ]);
                $scamType = $existingScamType;
            } else {
                // Create new scam type (without personas for now)
                // Use label from persona data (fallback to scam_type_code if not available)
                $label = $personaData['label_fr'] ?? $personaData['label_en'] ?? $result->scamTypeCode;

                $scamType = $this->scamTypeManager->createScamType(
                    scamTypeCode: $result->scamTypeCode,
                    label: $label,
                    description: null,
                    mispTaxonomy: null,
                    attckTechnique: null,
                    active: true
                );

                $this->logger->notice('New scam type created automatically by LLM', [
                    'scam_type_code' => $scamType->getCode(),
                    'created_from_conv_id' => $convId,
                ]);
            }

            // Link suggested personas to the scam type
            $linkedCount = 0;

            foreach ($suggestedPersonaCodes as $personaCode) {
                $persona = $this->personaManager->findByCode($personaCode);

                if ($persona instanceof \App\Domain\Communication\Persona) {
                    // Check if persona is already linked
                    if (!$scamType->getPersonas()->contains($persona)) {
                        $scamType->addPersona($persona);
                        $linkedCount++;
                        $this->logger->info('Linked persona to scam type', [
                            'scam_type_code' => $scamType->getCode(),
                            'persona_code' => $persona->getPersonaCode(),
                        ]);
                    }
                } else {
                    $this->logger->warning('Suggested persona not found, skipping', [
                        'persona_code' => $personaCode,
                    ]);
                }
            }

            if ($linkedCount > 0) {
                $this->em->persist($scamType);
            }

            $this->logger->info('Linked personas to new scam type', [
                'scam_type_code' => $scamType->getCode(),
                'linked_count' => $linkedCount,
                'suggested_codes' => $suggestedPersonaCodes,
            ]);

            $this->em->commit();

        } catch (\Exception $e) {
            $this->em->rollback();
            $this->logger->error('Failed to create scam type + link personas', [
                'error' => $e->getMessage(),
            ]);

            throw new \RuntimeException('Failed to create scam type + link personas: ' . $e->getMessage(), $e->getCode(), $e);
        }
    }

    /**
     * Create new ScamType without persona (use generic_user fallback)
     */
    private function createScamTypeWithoutPersona(ClassificationResult $result): void
    {
        $existingScamType = $this->scamTypeManager->findByCode($result->scamTypeCode);

        if ($existingScamType instanceof \App\Domain\Communication\ScamType) {
            $this->logger->info('Scam type already exists', [
                'scam_type_code' => $result->scamTypeCode,
            ]);

            return;
        }

        // Create new scam type without persona
        $this->scamTypeManager->createScamType(
            scamTypeCode: $result->scamTypeCode,
            label: $result->scamTypeCode,
            description: null,
            mispTaxonomy: null,
            attckTechnique: null,
            active: true
        );

        $this->logger->notice('New scam type created without persona', [
            'scam_type_code' => $result->scamTypeCode,
        ]);
    }

    /**
     * Update conversation scam_type
     */
    private function updateConversationScamType(string $convId, string $scamTypeCode): void
    {
        $scamType = $this->scamTypeManager->findByCode($scamTypeCode);

        if (!$scamType instanceof \App\Domain\Communication\ScamType) {
            throw new \RuntimeException("ScamType not found after classification: {$scamTypeCode}");
        }

        // Use ConversationHandler to update
        $this->conversationHandler->patchConversation($convId, [
            'scam_type_id' => $scamType->getScamTypeId(),
        ]);
    }

    /**
     * Update conversation secondary scam types
     *
     * @param array<int, array{code: string, confidence: float}>|null $secondaryTypes
     */
    private function updateConversationSecondaryTypes(string $convId, ?array $secondaryTypes): void
    {
        $conversation = $this->em->getRepository(Conversation::class)->find($convId);

        if (!$conversation instanceof Conversation) {
            return;
        }

        $conversation->setSecondaryScamTypes($secondaryTypes);
        $this->em->flush();
    }

    /**
     * Get conversation messages for classification
     *
     * @return array<int, array<string, mixed>>
     */
    private function getConversationMessages(string $convId, int $limit = 10): array
    {
        $conversation = $this->em->getRepository(Conversation::class)->find($convId);

        if ($conversation === null) {
            return [];
        }

        /** @var \App\Domain\Communication\Message[] $messages */
        $messages = $this->em->createQueryBuilder()
            ->select('m')
            ->from(\App\Domain\Communication\Message::class, 'm')
            ->where('m.conversation = :conv')
            ->andWhere('m.deletedAt IS NULL')
            ->setParameter('conv', $conversation)
            ->orderBy('m.tsMsg', 'ASC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();

        return array_map(fn (\App\Domain\Communication\Message $msg): array => [
            'msg_id' => $msg->getMsgId(),
            'direction' => $msg->getDirection()->getCode(),
            'subject' => $msg->getSubject(),
            'body_text' => $msg->getBodyText(),
            'ts_msg' => $msg->getTsMsg()->format(DATE_ATOM),
        ], $messages);
    }

    /**
     * Manually classify a conversation with a specific scam type and optional persona
     *
     * @param string      $convId       Conversation ID
     * @param string      $scamTypeCode Scam type code (e.g., PHISHING, INVOICE_FRAUD)
     * @param string|null $personaCode  Optional persona code to assign
     *
     * @throws \RuntimeException if conversation or scam type not found
     *
     * @return array{scam_type_code: string, scam_type_label: string, persona_code: string|null, persona_label: string|null}
     */
    public function manualClassifyConversation(string $convId, string $scamTypeCode, ?string $personaCode = null): array
    {
        // Get conversation
        $conversation = $this->em->getRepository(Conversation::class)->find($convId);

        if (!$conversation || $conversation->getDeletedAt() !== null) {
            throw new \RuntimeException("Conversation not found: {$convId}");
        }

        // Find scam type (normalize to uppercase)
        $scamType = $this->scamTypeManager->findByCode($scamTypeCode);

        if (!$scamType instanceof \App\Domain\Communication\ScamType) {
            throw new \RuntimeException("Scam type not found: {$scamTypeCode}");
        }

        // Update conversation scam_type
        $conversation->setScamType($scamType);

        // Handle persona assignment
        $persona = null;

        if ($personaCode) {
            $persona = $this->personaManager->findByCode($personaCode);

            if (!$persona instanceof \App\Domain\Communication\Persona) {
                throw new \RuntimeException("Persona not found: {$personaCode}");
            }

            $conversation->setPersona($persona);
        } else {
            // Auto-assign persona if scam type has associated personas
            $personas = $scamType->getPersonas();

            if (!$personas->isEmpty()) {
                $persona = $this->personaManager->assignRandomPersona($scamType);

                if ($persona instanceof \App\Domain\Communication\Persona) {
                    $conversation->setPersona($persona);
                }
            }
        }

        $this->em->flush();

        $this->logger->info('Conversation manually classified', [
            'conv_id' => $convId,
            'scam_type_code' => $scamType->getCode(),
            'persona_code' => $persona?->getPersonaCode(),
        ]);

        return [
            'scam_type_code' => $scamType->getCode(),
            'scam_type_label' => $scamType->getLabel(),
            'persona_code' => $persona?->getPersonaCode(),
            'persona_label' => $persona?->getPersonaLabel(),
        ];
    }

    /**
     * Auto-classify a conversation using LLM
     *
     * @param string $convId              Conversation ID
     * @param bool   $force               Force reclassification even if already classified
     * @param float  $confidenceThreshold Minimum confidence threshold (0.0-1.0)
     *
     * @throws \RuntimeException if conversation not found or classification fails
     *
     * @return array{scam_type_code: string, scam_type_label: string, persona_code: string|null, persona_label: string|null, confidence: float, is_new_scam_type: bool, is_new_persona: bool}
     */
    public function autoClassifyConversation(string $convId, bool $force = false, float $confidenceThreshold = 0.75): array
    {
        // Get conversation
        $conversation = $this->em->getRepository(Conversation::class)->find($convId);

        if (!$conversation || $conversation->getDeletedAt() !== null) {
            throw new \RuntimeException("Conversation not found: {$convId}");
        }

        // Check if already classified (unless force=true)
        $currentScamType = $conversation->getScamType();

        if (!$force && strtoupper($currentScamType->getCode()) !== 'UNKNOWN') {
            $this->logger->info('Conversation already classified, skipping', [
                'conv_id' => $convId,
                'scam_type_code' => $currentScamType->getCode(),
            ]);

            // Return current classification
            $persona = $conversation->getPersona();

            return [
                'scam_type_code' => $currentScamType->getCode(),
                'scam_type_label' => $currentScamType->getLabel(),
                'persona_code' => $persona?->getPersonaCode(),
                'persona_label' => $persona?->getPersonaLabel(),
                'confidence' => 1.0, // Already classified, consider it certain
                'is_new_scam_type' => false,
                'is_new_persona' => false,
            ];
        }

        // Get conversation messages for classification
        $messages = $this->getConversationMessages($convId);

        if ($messages === []) {
            throw new \RuntimeException("Cannot classify conversation without messages: {$convId}");
        }

        // Call LLM classifier
        $result = $this->scamClassifier->classify($messages);

        if (!$result instanceof \App\Application\Communication\ClassificationResult) {
            throw new \RuntimeException('LLM classification failed');
        }

        // Check confidence threshold
        if (!$result->shouldApply($confidenceThreshold)) {
            $this->logger->warning('Classification confidence too low', [
                'conv_id' => $convId,
                'scam_type' => $result->scamTypeCode,
                'confidence' => $result->confidence,
                'threshold' => $confidenceThreshold,
            ]);

            throw new \RuntimeException("Classification confidence too low: {$result->confidence} (threshold: {$confidenceThreshold})");
        }

        // Track if new types/personas were created
        $isNewScamType = $result->isNewType;
        $isNewPersona = $result->isNewPersona;

        // Handle new type + persona creation or use existing
        if ($result->isNewType && $result->isNewPersona) {
            $this->createScamTypeWithPersona($result, $convId);
        } elseif ($result->isNewType && !$result->isNewPersona) {
            $this->createScamTypeWithoutPersona($result);
        }

        // Update conversation scam_type
        $this->updateConversationScamType($convId, $result->scamTypeCode);

        // Store secondary scam types if present
        $this->updateConversationSecondaryTypes($convId, $result->secondaryTypes);

        // Get updated conversation and persona
        $this->em->refresh($conversation);
        $scamType = $conversation->getScamType();
        $persona = $conversation->getPersona();

        // Auto-assign persona if not assigned and scam type has associated personas
        if (!$persona) {
            $personas = $scamType->getPersonas();

            if (!$personas->isEmpty()) {
                $persona = $this->personaManager->assignRandomPersona($scamType);

                if ($persona instanceof \App\Domain\Communication\Persona) {
                    $conversation->setPersona($persona);
                    $this->em->flush();
                }
            }
        }

        $this->logger->info('Conversation auto-classified successfully', [
            'conv_id' => $convId,
            'scam_type' => $result->scamTypeCode,
            'confidence' => $result->confidence,
            'is_new_type' => $isNewScamType,
            'is_new_persona' => $isNewPersona,
            'persona_code' => $persona?->getPersonaCode(),
        ]);

        return [
            'scam_type_code' => $scamType->getCode(),
            'scam_type_label' => $scamType->getLabel(),
            'persona_code' => $persona?->getPersonaCode(),
            'persona_label' => $persona?->getPersonaLabel(),
            'confidence' => $result->confidence,
            'is_new_scam_type' => $isNewScamType,
            'is_new_persona' => $isNewPersona,
        ];
    }
}
