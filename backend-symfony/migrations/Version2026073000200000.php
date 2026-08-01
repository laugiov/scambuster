<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;
use Doctrine\Migrations\Exception\IrreversibleMigration;

/**
 * Converge CEO_FRAUD and INVOICE_FRAUD ATT&CK mapping on T1656 (Impersonation).
 *
 * Business email compromise — a spoofed executive asking for a wire transfer
 * (CEO_FRAUD) or a fake vendor invoice / bank-details change (INVOICE_FRAUD) —
 * is identity impersonation, which MITRE ATT&CK T1656 (Impersonation) describes
 * explicitly and cites BEC as an example. Version2026041100000000 mapped both to
 * T1566.002 (Spearphishing Link), a weaker fit since these scams are not
 * primarily link-driven. This aligns them with the other impersonation-first
 * scams (ROMANCE, TECH_SUPPORT, INVESTMENT, LOTTERY, CHARITY, ADVANCE_FEE_419)
 * already on T1656 and removes the drift between the live database (already
 * T1656) and the seed/fixture sources (which had reverted to T1566.002). It also
 * converges the denormalized ioc_context.scam_type_attck snapshot copies, which
 * the IOC-level STIX/TAXII export paths read directly, so those historical
 * exports stop emitting the superseded technique for CEO/INVOICE indicators.
 *
 * The IS DISTINCT FROM guard makes both UPDATEs a true no-op on any row already
 * at T1656, so re-running is safe and rows already correct are left untouched.
 *
 * down() is irreversible by design — restoring the weaker T1566.002 mapping
 * would degrade the STIX feed for external CTI consumers, matching the stance of
 * the previous ATT&CK correction migration.
 */
final class Version2026073000200000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Converge CEO_FRAUD/INVOICE_FRAUD ATT&CK mapping to T1656 (Impersonation).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(
            "UPDATE lkp_scam_type SET attck_technique = 'T1656', updated_at = NOW() "
            . "WHERE code IN ('CEO_FRAUD', 'INVOICE_FRAUD') "
            . "AND attck_technique IS DISTINCT FROM 'T1656'"
        );

        // Also converge the denormalized snapshot copies in ioc_context, which the
        // IOC-level STIX/TAXII export paths read directly (not live lkp_scam_type),
        // so historical CEO/INVOICE IOC exports stop emitting the superseded
        // technique. Same idempotent guard, so it is a no-op once converged.
        $this->addSql(
            "UPDATE ioc_context SET scam_type_attck = 'T1656' "
            . "WHERE scam_type_code IN ('CEO_FRAUD', 'INVOICE_FRAUD') "
            . "AND scam_type_attck IS DISTINCT FROM 'T1656'"
        );
    }

    public function down(Schema $schema): void
    {
        throw new IrreversibleMigration(
            'Restoring the weaker T1566.002 mapping for CEO_FRAUD/INVOICE_FRAUD is '
            . 'unacceptable — BEC is impersonation (T1656). Write a new corrective '
            . 'migration with justification if a change is genuinely needed.'
        );
    }
}
