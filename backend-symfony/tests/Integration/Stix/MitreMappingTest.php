<?php

declare(strict_types=1);

namespace App\Tests\Integration\Stix;

use App\Application\Stix\ThreatActorStixBuilder;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * MITRE ATT&CK mapping refresh.
 *
 * Asserts the canonical mapping in `lkp_scam_type` and verifies
 * that the ThreatActorStixBuilder constant exposes T1656 (Impersonation),
 * which was added to MITRE ATT&CK v14 (October 2023).
 *
 * Mapping enforcement:
 *   - INVOICE_FRAUD, CEO_FRAUD       → T1656 (BEC is impersonation; was T1566.002)
 *   - TECH_SUPPORT, ROMANCE, LOTTERY,
 *     CHARITY, ADVANCE_FEE_419,
 *     INVESTMENT                     → T1656 (impersonation-first scams)
 *   - PHISHING (T1566), PHISH_CREDENTIALS (T1566.002),
 *     PHISH_MALWARE (T1566.001), JOB_OFFER (T1566.003)
 *                                    → phishing sub-techniques, preserved
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
            'T1534 (Internal Spearphishing) is for insider lateral movement, not external scams.'
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
                sprintf('%s must map to T1656 (Impersonation)', $row['code'])
            );
        }
    }

    public function testInvoiceAndCeoFraudMappedToT1656(): void
    {
        $rows = $this->conn->fetchAllAssociative(
            "SELECT code, attck_technique FROM lkp_scam_type
             WHERE code IN ('INVOICE_FRAUD', 'CEO_FRAUD')
             ORDER BY code"
        );
        $this->assertCount(2, $rows);

        foreach ($rows as $row) {
            $this->assertSame(
                'T1656',
                $row['attck_technique'],
                sprintf('%s (BEC / executive impersonation) must map to T1656 (Impersonation)', $row['code'])
            );
        }
    }

    public function testPhishingSubtechniquesArePreserved(): void
    {
        // Guard against an over-broad "T1566.002 → T1656" convergence: the genuine
        // phishing (sub-)techniques must keep their specific, correct mappings.
        $map = $this->conn->fetchAllKeyValue(
            "SELECT code, attck_technique FROM lkp_scam_type
             WHERE code IN ('PHISHING', 'PHISH_CREDENTIALS', 'PHISH_MALWARE', 'JOB_OFFER')"
        );

        $this->assertSame('T1566', $map['PHISHING'] ?? null, 'PHISHING must stay T1566');
        $this->assertSame('T1566.002', $map['PHISH_CREDENTIALS'] ?? null, 'PHISH_CREDENTIALS must stay T1566.002 (real spearphishing link)');
        $this->assertSame('T1566.001', $map['PHISH_MALWARE'] ?? null, 'PHISH_MALWARE must stay T1566.001 (spearphishing attachment)');
        $this->assertSame('T1566.003', $map['JOB_OFFER'] ?? null, 'JOB_OFFER must stay T1566.003');
    }

    public function testEveryScamTypeMatchesTheCanonicalAttckMap(): void
    {
        // Drift lock: the complete lkp_scam_type ATT&CK map (as produced by the
        // reference fixtures) must equal this canonical table exactly, so a change
        // to one seed source without the migration/prod-seed can never silently
        // pass again — the divergence that this convergence corrects.
        $expected = [
            'UNKNOWN' => null,
            'PHISHING' => 'T1566',
            'PHISH_CREDENTIALS' => 'T1566.002',
            'INVOICE_FRAUD' => 'T1656',
            'ROMANCE' => 'T1656',
            'TECH_SUPPORT' => 'T1656',
            'PHISH_MALWARE' => 'T1566.001',
            'CEO_FRAUD' => 'T1656',
            'INVESTMENT' => 'T1656',
            'LOTTERY' => 'T1656',
            'JOB_OFFER' => 'T1566.003',
            'CHARITY' => 'T1656',
            'ADVANCE_FEE_419' => 'T1656',
            'COLD_SERVICE_SPAM' => null,
        ];

        $actual = $this->conn->fetchAllKeyValue('SELECT code, attck_technique FROM lkp_scam_type');

        ksort($expected);
        ksort($actual);

        $this->assertSame($expected, $actual, 'lkp_scam_type ATT&CK map drifted from the canonical table');
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
