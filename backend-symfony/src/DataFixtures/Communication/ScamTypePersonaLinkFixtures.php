<?php

declare(strict_types=1);

namespace App\DataFixtures\Communication;

use App\Domain\Communication\Persona;
use App\Domain\Communication\ScamType;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Common\DataFixtures\DependentFixtureInterface;
use Doctrine\Persistence\ObjectManager;

/**
 * Link existing ScamTypes to their appropriate Personas
 */
class ScamTypePersonaLinkFixtures extends Fixture implements DependentFixtureInterface
{
    /**
     * Mapping of scam_type code → persona code
     */
    private const SCAM_TYPE_TO_PERSONA = [
        'phishing_bank' => 'bank_customer',
        'phishing' => 'bank_customer',
        'lottery' => 'elderly_person',
        'romance' => 'lonely_person',
        'techsupport' => 'confused_user',
        'tech_support' => 'confused_user', // Alias if exists
        'invoice' => 'small_business_owner',
        'unknown' => 'generic_user',
    ];

    public function load(ObjectManager $manager): void
    {
        // PersonaFixtures re-enabled, references now available
        // Note: ScamType relationship changed from ManyToOne to ManyToMany
        // This fixture is kept for backward compatibility but may need updates
        // to use addPersona() instead of setPersona() for ManyToMany

        $scamTypeRepository = $manager->getRepository(ScamType::class);

        foreach (self::SCAM_TYPE_TO_PERSONA as $scamTypeCode => $personaCode) {
            $scamType = $scamTypeRepository->findOneBy(['code' => $scamTypeCode]);

            if (!$scamType) {
                // Skip if scam type doesn't exist (e.g., tech_support vs techsupport)
                continue;
            }

            /** @var Persona $persona */
            $persona = $this->getReference('persona_' . $personaCode, Persona::class);

            // Use addPersona for ManyToMany relationship
            $scamType->addPersona($persona);
            $manager->persist($scamType);
        }

        $manager->flush();
    }

    public function getDependencies(): array
    {
        return [
            PersonaFixtures::class,
            ScamTypeFixtures::class,
        ];
    }
}
