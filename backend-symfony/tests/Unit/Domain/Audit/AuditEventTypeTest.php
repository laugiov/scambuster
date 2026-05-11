<?php

declare(strict_types=1);

namespace App\Tests\Unit\Domain\Audit;

use App\Domain\Audit\AuditEventType;
use App\Domain\Audit\SiemSeverityMap;
use PHPUnit\Framework\TestCase;

/**
 * Locks the existence and serialization contract of audit event cases
 * introduced or referenced by spec 080 / signature-strip work.
 *
 * Adding a case to the enum has cross-cutting effects (SIEM exporters,
 * audit log queries, alerting). This test ensures the case stays
 * present and that the default SIEM mapping behaves sanely without
 * requiring an explicit entry in SiemSeverityMap (per the map's
 * documented DEFAULT_SEVERITY fallback for unmapped cases).
 */
final class AuditEventTypeTest extends TestCase
{
    public function test_llm_signature_stripped_case_exists(): void
    {
        $case = AuditEventType::LLM_SIGNATURE_STRIPPED;

        self::assertSame('LLM_SIGNATURE_STRIPPED', $case->value);
    }

    public function test_llm_signature_stripped_resolves_to_default_severity(): void
    {
        // Per SiemSeverityMap::DEFAULT_SEVERITY (= 3) for unmapped cases.
        // Strip events are informational, "Low" severity is correct.
        $severity = SiemSeverityMap::getSeverity(AuditEventType::LLM_SIGNATURE_STRIPPED);

        self::assertSame(3, $severity, 'Strip is informational — default severity 3 (Low)');
        self::assertSame('Low', SiemSeverityMap::getLabel($severity));
    }

    public function test_llm_signature_stripped_resolves_to_default_ecs_category(): void
    {
        // Per SiemSeverityMap::getEcsCategory() default branch.
        $category = SiemSeverityMap::getEcsCategory(AuditEventType::LLM_SIGNATURE_STRIPPED);

        self::assertSame('process', $category);
    }
}
