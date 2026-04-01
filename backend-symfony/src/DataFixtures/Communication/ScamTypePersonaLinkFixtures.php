<?php

declare(strict_types=1);

namespace App\DataFixtures\Communication;

use App\Domain\Communication\Persona;
use App\Domain\Communication\ScamType;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Common\DataFixtures\DependentFixtureInterface;
use Doctrine\Persistence\ObjectManager;

/**
 * Link existing ScamTypes to their appropriate Personas (ManyToMany)
 */
class ScamTypePersonaLinkFixtures extends Fixture implements DependentFixtureInterface
{
    /**
     * Mapping of scam_type code → list of persona codes
     *
     * @var array<string, list<string>>
     */
    private const SCAM_TYPE_TO_PERSONAS = [
        'PHISHING' => [
            'bank_customer',
            'worried_customer',
            'tech_newbie',
            'tech_intermediate',
            'senior_trusting',
        ],
        'PHISH_CREDENTIALS' => [
            'bank_customer',
            'worried_customer',
            'tech_newbie',
            'tech_intermediate',
            'senior_trusting',
        ],
        'PHISH_MALWARE' => [
            'bank_customer',
            'worried_customer',
            'tech_newbie',
            'tech_intermediate',
            'senior_trusting',
        ],
        'INVOICE_FRAUD' => [
            'small_business_owner',
            'entrepreneur_rushed',
            'accountant_meticulous',
            'freelance_cautious',
            'admin_assistant',
        ],
        'CEO_FRAUD' => [
            'small_business_owner',
            'entrepreneur_rushed',
            'accountant_meticulous',
            'freelance_cautious',
            'admin_assistant',
        ],
        'ROMANCE' => [
            'lonely_person',
            'lonely_divorcee',
            'hopeless_romantic',
            'widow_grieving',
            'senior_isolated',
        ],
        'TECH_SUPPORT' => [
            'confused_user',
            'tech_newbie',
            'tech_intermediate',
            'senior_trusting',
            'senior_suspicious',
        ],
        'LOTTERY' => [
            'lottery_skeptic',
            'lottery_believer',
            'elderly_person',
            'investor_greedy',
            'debtor_desperate',
        ],
        'INVESTMENT' => [
            'investor_greedy',
            'debtor_desperate',
            'senior_trusting',
            'lottery_believer',
            'entrepreneur_rushed',
        ],
        'JOB_OFFER' => [
            'student_busy',
            'debtor_desperate',
            'freelance_cautious',
            'confused_user',
            'generic_user',
        ],
        'CHARITY' => [
            'senior_trusting',
            'elderly_person',
            'lonely_person',
            'senior_isolated',
            'hopeless_romantic',
        ],
        'ADVANCE_FEE_419' => [
            'senior_trusting',
            'elderly_person',
            'debtor_desperate',
            'lottery_believer',
            'lonely_person',
        ],
        'UNKNOWN' => [
            'generic_user',
        ],
    ];

    public function load(ObjectManager $manager): void
    {
        $scamTypeRepository = $manager->getRepository(ScamType::class);

        foreach (self::SCAM_TYPE_TO_PERSONAS as $scamTypeCode => $personaCodes) {
            $scamType = $scamTypeRepository->findOneBy(['code' => $scamTypeCode]);

            if (!$scamType) {
                continue;
            }

            foreach ($personaCodes as $personaCode) {
                /** @var Persona $persona */
                $persona = $this->getReference('persona_' . $personaCode, Persona::class);
                $scamType->addPersona($persona);
            }

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
