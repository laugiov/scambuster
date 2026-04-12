<?php

declare(strict_types=1);

namespace App\Application\Communication;

use App\Domain\Communication\Persona;
use App\Domain\Communication\ScamType;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Links ScamTypes to their configured Personas via many-to-many relationship.
 */
final class ScamTypePersonaLinker
{
    /**
     * Mapping of scam_type code to array of persona codes (ManyToMany).
     */
    private const SCAM_TYPE_TO_PERSONAS = [
        'invoice' => ['small_business_owner', 'entrepreneur_rushed', 'accountant_meticulous', 'freelance_cautious', 'admin_assistant'],
        'phishing' => ['bank_customer', 'worried_customer', 'tech_newbie', 'tech_intermediate', 'senior_trusting'],
        'lottery' => ['lottery_skeptic', 'lottery_believer', 'elderly_person', 'investor_greedy', 'debtor_desperate'],
        'romance' => ['lonely_person', 'lonely_divorcee', 'hopeless_romantic', 'widow_grieving', 'senior_isolated'],
        'techsupport' => ['confused_user', 'tech_newbie', 'tech_intermediate', 'senior_trusting', 'senior_suspicious'],
        'UNKNOWN' => ['generic_user'],
    ];

    public function __construct(
        private readonly EntityManagerInterface $em,
    ) {
    }

    /**
     * Link all configured scam types to their personas.
     *
     * @return array{linked: int, skipped: int, warnings: list<string>}
     */
    public function linkAll(): array
    {
        $linked = 0;
        $skipped = 0;
        $warnings = [];

        foreach (self::SCAM_TYPE_TO_PERSONAS as $scamTypeCode => $personaCodes) {
            $scamType = $this->em->getRepository(ScamType::class)->findOneBy(['code' => $scamTypeCode]);

            if (!$scamType) {
                $skipped++;

                continue;
            }

            // Clear existing personas for this scam type
            foreach ($scamType->getPersonas() as $persona) {
                $scamType->removePersona($persona);
            }

            // Add all configured personas
            foreach ($personaCodes as $personaCode) {
                $persona = $this->em->getRepository(Persona::class)->findOneBy(['personaCode' => $personaCode]);

                if (!$persona) {
                    $warnings[] = "Persona '{$personaCode}' not found for scam type '{$scamTypeCode}'";

                    continue;
                }

                $scamType->addPersona($persona);
                $linked++;
            }

            $this->em->persist($scamType);
        }

        $this->em->flush();

        return [
            'linked' => $linked,
            'skipped' => $skipped,
            'warnings' => $warnings,
        ];
    }

    /**
     * @return array<string, list<string>>
     */
    public function getMapping(): array
    {
        return self::SCAM_TYPE_TO_PERSONAS;
    }
}
