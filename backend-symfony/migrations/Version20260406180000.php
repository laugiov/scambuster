<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Complete MITRE ATT&CK mapping for 6 scam types that were previously null.
 */
final class Version20260406180000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Complete MITRE ATT&CK technique mapping for INVOICE_FRAUD, ROMANCE, INVESTMENT, LOTTERY, CHARITY, ADVANCE_FEE_419';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("UPDATE lkp_scam_type SET attck_technique = 'T1534' WHERE code = 'INVOICE_FRAUD'");
        $this->addSql("UPDATE lkp_scam_type SET attck_technique = 'T1566.001' WHERE code = 'ROMANCE'");
        $this->addSql("UPDATE lkp_scam_type SET attck_technique = 'T1566.002' WHERE code = 'INVESTMENT'");
        $this->addSql("UPDATE lkp_scam_type SET attck_technique = 'T1566.001' WHERE code = 'LOTTERY'");
        $this->addSql("UPDATE lkp_scam_type SET attck_technique = 'T1566.001' WHERE code = 'CHARITY'");
        $this->addSql("UPDATE lkp_scam_type SET attck_technique = 'T1566.001' WHERE code = 'ADVANCE_FEE_419'");
    }

    public function down(Schema $schema): void
    {
        $this->addSql("UPDATE lkp_scam_type SET attck_technique = NULL WHERE code IN ('INVOICE_FRAUD', 'ROMANCE', 'INVESTMENT', 'LOTTERY', 'CHARITY', 'ADVANCE_FEE_419')");
    }
}
