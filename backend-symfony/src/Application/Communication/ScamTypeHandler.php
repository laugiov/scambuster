<?php

declare(strict_types=1);

namespace App\Application\Communication;

use App\Domain\Communication\ScamType;
use Doctrine\ORM\EntityManagerInterface;

final class ScamTypeHandler
{
    public function __construct(
        private readonly EntityManagerInterface $em
    ) {
    }

    /**
     * Get all scam types with their associated personas from database
     *
     * @return array<int, array{scam_type_id: int, code: string, label: string, description: string|null, misp_taxonomy: string|null, attck_technique: string|null, personas: array<string>}>
     */
    public function getAllScamTypes(): array
    {
        $scamTypes = $this->em->getRepository(ScamType::class)->findAll();

        return array_map(function (ScamType $scamType) {
            $personas = $scamType->getPersonas();
            $personaCodes = [];

            foreach ($personas as $persona) {
                $personaCodes[] = $persona->getPersonaCode();
            }

            // For backward compatibility, also include 'persona' with first persona code
            $firstPersona = count($personaCodes) > 0 ? $personaCodes[0] : 'generic_user';

            return [
                'scam_type_id' => $scamType->getScamTypeId(),
                'code' => $scamType->getCode(),
                'label' => $scamType->getLabel(),
                'description' => $scamType->getDescription(),
                'misp_taxonomy' => $scamType->getMispTaxonomy(),
                'attck_technique' => $scamType->getAttckTechnique(),
                'persona' => $firstPersona, // Legacy: first persona for backward compatibility
                'personas' => $personaCodes, // New: all personas
            ];
        }, $scamTypes);
    }
}
