<?php

declare(strict_types=1);

namespace App\Application\Communication;

use App\Domain\Communication\Persona;
use App\Domain\Communication\ScamType;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Manage CRUD operations for ScamType entities
 */
class ScamTypeManager
{
    public function __construct(
        private readonly EntityManagerInterface $em
    ) {
    }

    /**
     * Find scam type by code (case-insensitive)
     */
    public function findByCode(string $code): ?ScamType
    {
        // Normalize code to uppercase to match database format (INVOICE_FRAUD, PHISH_CREDENTIALS, etc.)
        $normalizedCode = strtoupper($code);

        return $this->em->getRepository(ScamType::class)->findOneBy([
            'code' => $normalizedCode,
        ]);
    }

    /**
     * Get all scam type codes
     *
     * @return string[]
     */
    public function getAllCodes(): array
    {
        /** @var array<int, array{code: string}> $result */
        $result = $this->em->createQueryBuilder()
            ->select('st.code')
            ->from(ScamType::class, 'st')
            ->orderBy('st.code', 'ASC')
            ->getQuery()
            ->getResult();

        return array_column($result, 'code');
    }

    /**
     * Get all scam types
     *
     * @return ScamType[]
     */
    public function getAll(): array
    {
        return $this->em->getRepository(ScamType::class)->findAll();
    }

    /**
     * Create a new scam type with associated persona
     *
     * @throws \RuntimeException if scam type code already exists
     */
    public function createScamTypeWithPersona(
        string $scamTypeCode,
        string $label,
        Persona $persona,
        ?string $description = null,
        ?string $mispTaxonomy = null,
        ?string $attckTechnique = null,
        bool $active = true
    ): ScamType {
        // Validate scam_type_code format (snake_case or UPPERCASE, 3-30 chars)
        if (!preg_match('/^[A-Za-z_]{3,30}$/', $scamTypeCode)) {
            throw new \RuntimeException(
                "Invalid scam_type_code format: must be snake_case or UPPERCASE, 3-30 characters (got: {$scamTypeCode})"
            );
        }

        // Normalize to uppercase for database
        $normalizedCode = strtoupper($scamTypeCode);

        // Check if scam type already exists
        $existing = $this->findByCode($normalizedCode);

        if ($existing) {
            throw new \RuntimeException(
                "ScamType with code '{$normalizedCode}' already exists"
            );
        }

        $scamType = new ScamType(
            code: $normalizedCode,
            label: $label,
            description: $description,
            mispTaxonomy: $mispTaxonomy,
            attckTechnique: $attckTechnique,
            active: $active
        );

        // Link persona (ManyToMany relationship)
        $scamType->addPersona($persona);

        $this->em->persist($scamType);
        $this->em->flush();

        return $scamType;
    }

    /**
     * Create a new scam type without persona (will use generic_user as fallback)
     *
     * @throws \RuntimeException if scam type code already exists
     */
    public function createScamType(
        string $scamTypeCode,
        string $label,
        ?string $description = null,
        ?string $mispTaxonomy = null,
        ?string $attckTechnique = null,
        bool $active = true
    ): ScamType {
        // Validate scam_type_code format (snake_case or UPPERCASE, 3-30 chars)
        if (!preg_match('/^[A-Za-z_]{3,30}$/', $scamTypeCode)) {
            throw new \RuntimeException(
                "Invalid scam_type_code format: must be snake_case or UPPERCASE, 3-30 characters (got: {$scamTypeCode})"
            );
        }

        // Normalize to uppercase for database
        $normalizedCode = strtoupper($scamTypeCode);

        // Check if scam type already exists
        $existing = $this->findByCode($normalizedCode);

        if ($existing) {
            throw new \RuntimeException(
                "ScamType with code '{$normalizedCode}' already exists"
            );
        }

        $scamType = new ScamType(
            code: $normalizedCode,
            label: $label,
            description: $description,
            mispTaxonomy: $mispTaxonomy,
            attckTechnique: $attckTechnique,
            active: $active
        );

        $this->em->persist($scamType);
        $this->em->flush();

        return $scamType;
    }

}
