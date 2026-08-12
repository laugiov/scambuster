<?php

declare(strict_types=1);

namespace App\Tests\Unit\Domain\Communication\Policy;

use App\Domain\Communication\IocCategory;
use App\Domain\Communication\Policy\IocExportPolicy;
use App\Domain\ThreatActor\AnalystVerdict;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class IocExportPolicyTest extends TestCase
{
    /**
     * @return iterable<string, array{string, AnalystVerdict|null, int, bool}>
     */
    public static function truthTable(): iterable
    {
        // A human false-positive verdict kills export whatever the type.
        yield 'false positive domain' => ['domain', AnalystVerdict::FalsePositive, 9, false];
        yield 'false positive iban' => ['iban', AnalystVerdict::FalsePositive, 9, false];

        // Financial IOCs are held until confirmed (corroboration is irrelevant).
        yield 'unreviewed iban held' => ['iban', null, 9, false];
        yield 'confirmed iban released' => ['iban', AnalystVerdict::Confirmed, 1, true];

        // Non-financial IOCs: corroborated OR confirmed ships; single-sighting held.
        yield 'single-sighting domain held' => ['domain', null, 1, false];
        yield 'corroborated domain exports' => ['domain', null, 2, true];
        yield 'confirmed domain exports single sighting' => ['domain', AnalystVerdict::Confirmed, 1, true];
        yield 'single-sighting email held' => ['email', null, 1, false];
        yield 'corroborated url exports' => ['url', null, 3, true];
    }

    #[DataProvider('truthTable')]
    public function testIsExportable(string $type, ?AnalystVerdict $verdict, int $corroboration, bool $expected): void
    {
        self::assertSame($expected, IocExportPolicy::isExportable($type, $verdict, $corroboration));
    }

    public function testEveryFinancialTypeIsHeldWithoutConfirmation(): void
    {
        foreach (IocCategory::FINANCIAL_TYPES as $type) {
            self::assertFalse(
                IocExportPolicy::isExportable($type, null, 99),
                "financial type '{$type}' must be held without an analyst confirmation, however corroborated",
            );
            self::assertTrue(
                IocExportPolicy::isExportable($type, AnalystVerdict::Confirmed, 0),
                "financial type '{$type}' must export once confirmed",
            );
        }
    }

    public function testSqlConditionMirrorsThePhpPredicate(): void
    {
        $sql = IocExportPolicy::sqlCondition('i', 'f');

        self::assertStringContainsString("f.verdict <> 'false_positive'", $sql);
        self::assertStringContainsString("f.verdict = 'confirmed'", $sql);
        self::assertStringContainsString('f.verdict IS NULL', $sql);
        // Case/padding normalization mirrors classify(): ingest stores the type
        // verbatim, so the SQL hold must not be bypassable by 'IBAN' or ' iban '.
        self::assertStringContainsString('LOWER(BTRIM(i.type)) NOT IN', $sql);

        foreach (IocCategory::FINANCIAL_TYPES as $type) {
            self::assertStringContainsString("'{$type}'", $sql, "SQL hold list must include '{$type}'");
        }

        // Non-financial corroboration clause is present.
        self::assertStringContainsString('COUNT(DISTINCT m_c.conv_id)', $sql);
        self::assertStringContainsString('>= ' . IocExportPolicy::MIN_CORROBORATION, $sql);
    }
}
