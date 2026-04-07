<?php

declare(strict_types=1);

namespace App\Application\Communication;

use App\Domain\Communication\Persona;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Manage CRUD operations for Persona entities
 */
class PersonaManager
{
    public function __construct(
        private readonly EntityManagerInterface $em
    ) {
    }

    /**
     * Find persona by code
     */
    public function findByCode(string $code): ?Persona
    {
        return $this->em->getRepository(Persona::class)->findOneBy([
            'personaCode' => $code,
        ]);
    }

    /**
     * Get all active personas
     *
     * @return Persona[]
     */
    public function getAllActive(): array
    {
        return $this->em->getRepository(Persona::class)->findBy(
            ['isActive' => true],
            ['personaCode' => 'ASC']
        );
    }

    /**
     * Create a new persona
     *
     * @throws \RuntimeException if persona code already exists
     */
    public function createPersona(
        string $personaCode,
        string $personaLabel,
        string $personaTone,
        string $systemPrompt,
        string $createdBy = 'manual'
    ): Persona {
        // Validate persona code format (snake_case, 3-30 chars)
        if (!preg_match('/^[a-z_]{3,30}$/', $personaCode)) {
            throw new \RuntimeException(
                "Invalid persona_code format: must be snake_case, 3-30 characters (got: {$personaCode})"
            );
        }

        // Validate system_prompt minimum length
        if (strlen($systemPrompt) < 100) {
            throw new \RuntimeException(
                'system_prompt must be at least 100 characters long'
            );
        }

        // Check if persona already exists
        $existing = $this->findByCode($personaCode);

        if ($existing) {
            throw new \RuntimeException(
                "Persona with code '{$personaCode}' already exists"
            );
        }

        $persona = new Persona(
            personaCode: $personaCode,
            personaLabel: $personaLabel,
            personaTone: $personaTone,
            systemPrompt: $systemPrompt,
            createdBy: $createdBy,
            createdAt: new \DateTimeImmutable(),
            isActive: true
        );

        $this->em->persist($persona);
        $this->em->flush();

        return $persona;
    }

    /**
     * Update an existing persona
     */
    public function updatePersona(
        Persona $persona,
        ?string $personaLabel = null,
        ?string $personaTone = null,
        ?string $systemPrompt = null
    ): void {
        if ($personaLabel !== null) {
            $persona->setPersonaLabel($personaLabel);
        }

        if ($personaTone !== null) {
            $persona->setPersonaTone($personaTone);
        }

        if ($systemPrompt !== null) {
            if (strlen($systemPrompt) < 100) {
                throw new \RuntimeException(
                    'system_prompt must be at least 100 characters long'
                );
            }
            $persona->setSystemPrompt($systemPrompt);
        }

        $this->em->flush();
    }

    /**
     * Deactivate a persona (soft delete)
     */
    public function deactivate(Persona $persona): void
    {
        $persona->setIsActive(false);
        $this->em->flush();
    }

    /**
     * Reactivate a persona
     */
    public function activate(Persona $persona): void
    {
        $persona->setIsActive(true);
        $this->em->flush();
    }

    /**
     * Assign a random persona from those compatible with a given ScamType.
     *
     * Uses PHP's array_rand() for cryptographically secure random selection (PHP 8.2+).
     * This method is idempotent but non-deterministic by design.
     *
     * Important: The caller MUST persist the returned persona in the Conversation entity
     * to ensure consistency across multiple reply generations.
     *
     * @param \App\Domain\Communication\ScamType $scamType The scam type to select persona for
     *
     * @return Persona|null Random persona from the scam type's personas, or null if none available
     */
    public function assignRandomPersona(\App\Domain\Communication\ScamType $scamType): ?Persona
    {
        $personas = $scamType->getPersonas()->toArray();

        if (empty($personas)) {
            return null; // No persona associated with this scam type
        }

        // Random selection among compatible personas
        $randomIndex = array_rand($personas);

        return $personas[$randomIndex];
    }

    /**
     * Get system prompt for a given persona.
     *
     * @return string System prompt ready to be used in LLM generation
     */
    public function getSystemPrompt(Persona $persona): string
    {
        return <<<PROMPT
PERSONA: {$persona->getPersonaLabel()}
Ton: {$persona->getPersonaTone()}

{$persona->getSystemPrompt()}

Incarne ce persona de manière cohérente tout au long de la conversation.
PROMPT;
    }
}
