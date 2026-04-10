<?php

declare(strict_types=1);

namespace App\Tests\Integration\Stix;

use App\Application\Stix\ThreatActorStixBuilder;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Spec 062 — MITRE ATT&CK mapping refresh.
 *
 * Asserts the canonical post-spec-062 mapping in `lkp_scam_type` and verifies
 * that the ThreatActorStixBuilder constant exposes T1656 (Impersonation),
 * which was added to MITRE ATT&CK v14 (October 2023).
 *
 * Mapping enforcement (see plan.md section 4):
 *   - INVOICE_FRAUD, CEO_FRAUD       → T1566.002 (was T1534, insider, wrong)
 *   - TECH_SUPPORT                   → T1656 (was T1566.004, retired)
 *   - ROMANCE, LOTTERY, CHARITY,
 *     ADVANCE_FEE_419, INVESTMENT    → T1656 (was T1566.001/T1566.002)
 *   - PHISHING, PHISH_*, JOB_OFFER   → unchanged
 *
 * The 'down()' of the migration is irreversible by design — restoring incorrect
 * mappings would damage the credibility of the STIX feed.
 */
final class MitreMappingTest extends KernelTestCase
{
    private Connection $conn;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->conn = self::getContainer()->get(Connection::class);
    }

    public function testNoT1534InAnyScamType(): void
    {
        $count = (int) $this->conn->fetchOne(
            "SELECT COUNT(*) FROM lkp_scam_type WHERE attck_technique = 'T1534'"
        );
        $this->assertSame(
            0,
            $count,
            'T1534 (Internal Spearphishing) is for insider lateral movement, not external scams. Spec 062 forbids it.'
        );
    }

    public function testNoT1566004InAnyScamType(): void
    {
        $count = (int) $this->conn->fetchOne(
            "SELECT COUNT(*) FROM lkp_scam_type WHERE attck_technique = 'T1566.004'"
        );
        $this->assertSame(
            0,
            $count,
            'T1566.004 was retired from MITRE ATT&CK and is no longer a valid technique ID.'
        );
    }

    public function testT1656MappedForImpersonationScams(): void
    {
        $rows = $this->conn->fetchAllAssociative(
            "SELECT code, attck_technique FROM lkp_scam_type
             WHERE code IN ('TECH_SUPPORT', 'ROMANCE', 'LOTTERY', 'CHARITY', 'ADVANCE_FEE_419', 'INVESTMENT')
             ORDER BY code"
        );
        $this->assertCount(6, $rows, 'All 6 impersonation scam types must exist in lkp_scam_type');

        foreach ($rows as $row) {
            $this->assertSame(
                'T1656',
                $row['attck_technique'],
                sprintf('%s must map to T1656 (Impersonation) per spec 062', $row['code'])
            );
        }
    }

    public function testInvoiceAndCeoFraudMappedToT1566002(): void
    {
        $rows = $this->conn->fetchAllAssociative(
            "SELECT code, attck_technique FROM lkp_scam_type
             WHERE code IN ('INVOICE_FRAUD', 'CEO_FRAUD')
             ORDER BY code"
        );
        $this->assertCount(2, $rows);

        foreach ($rows as $row) {
            $this->assertSame(
                'T1566.002',
                $row['attck_technique'],
                sprintf('%s must map to T1566.002 (Spearphishing Link) per spec 062', $row['code'])
            );
        }
    }

    public function testThreatActorStixBuilderEmitsT1656AttackPattern(): void
    {
        $builder = new ThreatActorStixBuilder();
        $patterns = $builder->buildAttackPatterns('T1656');

        $this->assertCount(1, $patterns, 'T1656 must produce exactly one attack-pattern object');

        $pattern = $patterns[0];
        $this->assertSame('attack-pattern', $pattern['type']);
        $this->assertSame('Impersonation', $pattern['name']);

        /** @var list<array{source_name: string, url: string, external_id: string}> $extRefs */
        $extRefs = $pattern['external_references'];
        $this->assertSame('mitre-attack', $extRefs[0]['source_name']);
        $this->assertSame('T1656', $extRefs[0]['external_id']);
        $this->assertSame('https://attack.mitre.org/techniques/T1656/', $extRefs[0]['url']);
    }
}
