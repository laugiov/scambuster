<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;
use Doctrine\Migrations\Exception\IrreversibleMigration;

/**
 * Refresh MITRE ATT&CK mapping in lkp_scam_type.
 *
 * Forward migration only — corrects the wrong mappings introduced by historical
 * migrations Version20260325151254 and Version20260406180000:
 *
 *   - INVOICE_FRAUD, CEO_FRAUD: T1534 (Internal Spearphishing — insider lateral
 *     movement, wrong for external scams) → T1566.002 (Spearphishing Link)
 *   - TECH_SUPPORT: T1566.004 (retired from MITRE ATT&CK) → T1656 (Impersonation,
 *     added in MITRE ATT&CK v14, October 2023)
 *   - ROMANCE, LOTTERY, CHARITY, ADVANCE_FEE_419: T1566.001 (Spearphishing
 *     Attachment — semantically wrong, no attachment in these scams) → T1656
 *   - INVESTMENT: T1566.002 (link is secondary) → T1656 (impersonation primary)
 *
 * down() is irreversible by design — restoring incorrect mappings would damage
 * the credibility of the STIX feed for any external CTI consumer.
 */
final class Version2026041100000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Refresh MITRE ATT&CK mapping. '
            . 'T1534 → T1566.002 for INVOICE_FRAUD/CEO_FRAUD. '
            . 'T1566.004 (retired) → T1656 for TECH_SUPPORT. '
            . 'T1566.001/T1566.002 → T1656 for impersonation scams '
            . '(ROMANCE, LOTTERY, CHARITY, ADVANCE_FEE_419, INVESTMENT).';
    }

    public function up(Schema $schema): void
    {
        // INVOICE_FRAUD, CEO_FRAUD: T1534 → T1566.002 (external BEC, not insider)
        $this->addSql(
            "UPDATE lkp_scam_type SET attck_technique = 'T1566.002', updated_at = NOW() "
            . "WHERE code IN ('INVOICE_FRAUD', 'CEO_FRAUD')"
        );

        // TECH_SUPPORT, ROMANCE, LOTTERY, CHARITY, ADVANCE_FEE_419, INVESTMENT: → T1656 (Impersonation)
        $this->addSql(
            "UPDATE lkp_scam_type SET attck_technique = 'T1656', updated_at = NOW() "
            . "WHERE code IN ('TECH_SUPPORT', 'ROMANCE', 'LOTTERY', 'CHARITY', 'ADVANCE_FEE_419', 'INVESTMENT')"
        );
    }

    public function down(Schema $schema): void
    {
        throw new IrreversibleMigration(
            'Restoring T1534 / T1566.004 / wrong T1566.001 mappings is unacceptable. '
            . 'They are deprecated, semantically wrong, or both. If a rollback is genuinely '
            . 'needed, write a new corrective migration with full justification.'
        );
    }
}
