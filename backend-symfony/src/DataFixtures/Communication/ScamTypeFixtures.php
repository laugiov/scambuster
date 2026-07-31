<?php

declare(strict_types=1);

namespace App\DataFixtures\Communication;

use App\Domain\Communication\ScamType;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Bundle\FixturesBundle\FixtureGroupInterface;
use Doctrine\Persistence\ObjectManager;

/**
 * ScamTypeFixtures - Create scam types for tests
 */
class ScamTypeFixtures extends Fixture implements FixtureGroupInterface
{
    /** Reference/lookup data — loadable on its own for the lightweight demo seed. */
    public static function getGroups(): array
    {
        return ['reference'];
    }

    public const SCAM_TYPE_UNKNOWN = 'scam-type-unknown';
    public const SCAM_TYPE_PHISH_CREDENTIALS = 'scam-type-phish-credentials';

    public function load(ObjectManager $manager): void
    {
        // Create scam types
        $scamTypes = [
            [
                'code' => 'UNKNOWN',
                'label' => 'Unclassified',
                'description' => 'Unidentified scam type',
                'misp_taxonomy' => 'rsit:fraud="other"',
                'attck_technique' => null,
                'active' => true,
                'ref' => self::SCAM_TYPE_UNKNOWN,
            ],
            [
                'code' => 'PHISHING',
                'label' => 'Phishing',
                'description' => 'Generic phishing attempt',
                'misp_taxonomy' => 'rsit:fraud="phishing"',
                'attck_technique' => 'T1566',
                'active' => true,
                'ref' => null,
            ],
            [
                'code' => 'PHISH_CREDENTIALS',
                'label' => 'Credential Phish',
                'description' => 'Targets login/MFA (O365, banking, webmail)',
                'misp_taxonomy' => 'rsit:fraud="phishing"',
                'attck_technique' => 'T1566.002',
                'active' => true,
                'ref' => self::SCAM_TYPE_PHISH_CREDENTIALS,
            ],
            [
                'code' => 'INVOICE_FRAUD',
                'label' => 'Invoice Fraud',
                'description' => 'Fake invoice or bank-details change',
                'misp_taxonomy' => 'rsit:fraud="fraud"',
                'attck_technique' => 'T1656',
                'active' => true,
                'ref' => null,
            ],
            [
                'code' => 'ROMANCE',
                'label' => 'Romance Scam',
                'description' => 'Builds trust, then asks for money',
                'misp_taxonomy' => 'rsit:fraud="scam"',
                'attck_technique' => 'T1656',
                'active' => true,
                'ref' => null,
            ],
            [
                'code' => 'TECH_SUPPORT',
                'label' => 'Tech Support',
                'description' => 'Impersonates Microsoft/Apple support',
                'misp_taxonomy' => 'rsit:fraud="scam"',
                'attck_technique' => 'T1656',
                'active' => true,
                'ref' => null,
            ],
            [
                'code' => 'PHISH_MALWARE',
                'label' => 'Phish / Malware',
                'description' => 'Email with a malicious attachment or link',
                'misp_taxonomy' => 'rsit:fraud="phishing"',
                'attck_technique' => 'T1566.001',
                'active' => true,
                'ref' => null,
            ],
            [
                'code' => 'CEO_FRAUD',
                'label' => 'CEO Fraud',
                'description' => 'Impersonates an executive to obtain a wire transfer',
                'misp_taxonomy' => 'rsit:fraud="fraud"',
                'attck_technique' => 'T1656',
                'active' => true,
                'ref' => null,
            ],
            [
                'code' => 'INVESTMENT',
                'label' => 'Investment Scam',
                'description' => 'Fake financial investment, crypto, or forex',
                'misp_taxonomy' => 'rsit:fraud="scam"',
                'attck_technique' => 'T1656',
                'active' => true,
                'ref' => null,
            ],
            [
                'code' => 'LOTTERY',
                'label' => 'Lottery',
                'description' => 'Fictitious winnings requiring release fees',
                'misp_taxonomy' => 'rsit:fraud="scam"',
                'attck_technique' => 'T1656',
                'active' => true,
                'ref' => null,
            ],
            [
                'code' => 'JOB_OFFER',
                'label' => 'Job Offer',
                'description' => 'Fraudulent job offer aimed at harvesting personal information',
                'misp_taxonomy' => 'rsit:fraud="scam"',
                'attck_technique' => 'T1566.003',
                'active' => true,
                'ref' => null,
            ],
            [
                'code' => 'CHARITY',
                'label' => 'Charity Scam',
                'description' => 'Donation collection for a fictitious or diverted cause',
                'misp_taxonomy' => 'rsit:fraud="scam"',
                'attck_technique' => 'T1656',
                'active' => true,
                'ref' => null,
            ],
            [
                'code' => 'ADVANCE_FEE_419',
                'label' => 'Advance Fee (419)',
                'description' => 'Fictitious inheritance, release fees, Nigerian prince',
                'misp_taxonomy' => 'rsit:fraud="419_scam"',
                'attck_technique' => 'T1656',
                'active' => true,
                'ref' => null,
            ],
            [
                'code' => 'COLD_SERVICE_SPAM',
                'label' => 'Cold Service Spam / Fake Vendor',
                'description' => 'Unsolicited cold commercial service outreach (SEO, web/app development, marketing) and the fake-vendor / advance-fee-for-services pattern it often escalates into: anonymous senders, contact pushed to WhatsApp/Telegram, verification attachments, pressing follow-ups.',
                'misp_taxonomy' => 'rsit:fraud="scam"',
                'attck_technique' => null,
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
