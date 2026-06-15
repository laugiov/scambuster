<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Spec 104 follow-up — standardise lkp_scam_type.label in English.
 *
 * lkp_scam_type.label was populated in French during an earlier
 * localisation pass while the rest of the app's display layer
 * (scamTypeLabel helper, money-shot badges, convergence chart
 * selector, filter chips) is hardcoded in English. Same scam_type
 * appeared as "Arnaque à l'investissement" in some screens and
 * "Investment Scam" in others.
 *
 * Standardisation: lkp_scam_type.label is now the canonical English
 * label and matches the frontend SCAM_TYPE_MAP. A future i18n
 * improvement would route the frontend through i18next keys so
 * FR locale users see French translations; until then, EN is the
 * single source of truth at the data layer.
 *
 * Down migration restores the French labels for reversibility.
 */
final class Version2026061500100000 extends AbstractMigration
{
    /** @var array<string, array{en: string, fr: string}> */
    private const LABEL_MAP = [
        'ADVANCE_FEE_419' => ['en' => 'Advance Fee (419)', 'fr' => 'Fraude 419 / avance de frais'],
        'CEO_FRAUD' => ['en' => 'CEO Fraud', 'fr' => 'Fraude au président'],
        'CHARITY' => ['en' => 'Charity Scam', 'fr' => 'Faux appel aux dons'],
        'INVESTMENT' => ['en' => 'Investment Scam', 'fr' => 'Arnaque à l\'investissement'],
        'INVOICE_FRAUD' => ['en' => 'Invoice Fraud', 'fr' => 'Fraude à la facture'],
        'JOB_OFFER' => ['en' => 'Job Offer', 'fr' => 'Fausse offre d\'emploi'],
        'LOTTERY' => ['en' => 'Lottery', 'fr' => 'Fausse loterie'],
        'PHISHING' => ['en' => 'Phishing', 'fr' => 'Phishing'],
        'PHISH_CREDENTIALS' => ['en' => 'Credential Phish', 'fr' => 'Phishing d\'identifiants'],
        'PHISH_MALWARE' => ['en' => 'Phish / Malware', 'fr' => 'Phishing avec malware'],
        'ROMANCE' => ['en' => 'Romance Scam', 'fr' => 'Arnaque sentimentale'],
        'TECH_SUPPORT' => ['en' => 'Tech Support', 'fr' => 'Faux support technique'],
        'UNKNOWN' => ['en' => 'Unclassified', 'fr' => 'Unclassified'],
    ];

    public function getDescription(): string
    {
        return 'Spec 104 follow-up — standardise lkp_scam_type.label to English';
    }

    public function up(Schema $schema): void
    {
        foreach (self::LABEL_MAP as $code => $pair) {
            $this->addSql(
                'UPDATE lkp_scam_type SET label = :label WHERE code = :code',
                ['label' => $pair['en'], 'code' => $code]
            );
        }
    }

    public function down(Schema $schema): void
    {
        foreach (self::LABEL_MAP as $code => $pair) {
            $this->addSql(
                'UPDATE lkp_scam_type SET label = :label WHERE code = :code',
                ['label' => $pair['fr'], 'code' => $code]
            );
        }
    }
}
