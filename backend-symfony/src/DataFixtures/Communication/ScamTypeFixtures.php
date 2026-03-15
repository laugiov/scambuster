<?php

declare(strict_types=1);

namespace App\DataFixtures\Communication;

use App\Domain\Communication\ScamType;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Persistence\ObjectManager;

/**
 * ScamTypeFixtures - Create scam types for tests
 */
class ScamTypeFixtures extends Fixture
{
    public const SCAM_TYPE_UNKNOWN = 'scam-type-unknown';
    public const SCAM_TYPE_PHISH_CREDENTIALS = 'scam-type-phish-credentials';

    public function load(ObjectManager $manager): void
    {
        // Create scam types with Sprint 3 structure
        $scamTypes = [
            [
                'code' => 'unknown',
                'label' => 'Non classifié',
                'description' => 'Type de scam non identifié',
                'misp_taxonomy' => 'rsit:fraud="other"',
                'attck_technique' => null,
                'active' => true,
                'ref' => self::SCAM_TYPE_UNKNOWN,
            ],
            [
                'code' => 'PHISHING',
                'label' => 'Phishing',
                'description' => 'Tentative de phishing générique',
                'misp_taxonomy' => 'rsit:fraud="phishing"',
                'attck_technique' => 'T1566',
                'active' => true,
                'ref' => null,
            ],
            [
                'code' => 'PHISH_CREDENTIALS',
                'label' => 'Phishing d\'identifiants',
                'description' => 'Vise login/MFA (O365, banque, webmail)',
                'misp_taxonomy' => 'rsit:fraud="phishing"',
                'attck_technique' => 'T1566.002',
                'active' => true,
                'ref' => self::SCAM_TYPE_PHISH_CREDENTIALS,
            ],
            [
                'code' => 'INVOICE_FRAUD',
                'label' => 'Fraude à la facture',
                'description' => 'Fausse facture ou modification de RIB',
                'misp_taxonomy' => 'rsit:fraud="fraud"',
                'attck_technique' => null,
                'active' => true,
                'ref' => null,
            ],
            [
                'code' => 'ROMANCE',
                'label' => 'Arnaque sentimentale',
                'description' => 'Etablit confiance puis demande argent',
                'misp_taxonomy' => 'rsit:fraud="scam"',
                'attck_technique' => null,
                'active' => true,
                'ref' => null,
            ],
            [
                'code' => 'TECH_SUPPORT',
                'label' => 'Faux support technique',
                'description' => 'Se fait passer pour support Microsoft/Apple',
                'misp_taxonomy' => 'rsit:fraud="scam"',
                'attck_technique' => 'T1566.004',
                'active' => true,
                'ref' => null,
            ],
        ];

        foreach ($scamTypes as $data) {
            $scamType = new ScamType(
                $data['code'],
                $data['label'],
                $data['description'],
                $data['misp_taxonomy'],
                $data['attck_technique'],
                $data['active']
            );

            $manager->persist($scamType);

            // Add reference if specified
            if ($data['ref'] !== null) {
                $this->addReference($data['ref'], $scamType);
            }
        }

        $manager->flush();
    }
}
