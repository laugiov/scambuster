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
     * @return iterable<string, array{string, AnalystVerdict|null, bool}>
     */
    public static function truthTable(): iterable
    {
        // A human false-positive verdict kills export whatever the type.
        yield 'false positive domain' => ['domain', AnalystVerdict::FalsePositive, false];
        yield 'false positive iban' => ['iban', AnalystVerdict::FalsePositive, false];

        // Financial IOCs are held until confirmed.
        yield 'unreviewed iban held' => ['iban', null, false];
        yield 'confirmed iban released' => ['iban', AnalystVerdict::Confirmed, true];

        // Non-financial IOCs export as before.
        yield 'unreviewed domain exports' => ['domain', null, true];
        yield 'confirmed domain exports' => ['domain', AnalystVerdict::Confirmed, true];
        yield 'unreviewed email exports' => ['email', null, true];
        yield 'unreviewed url exports' => ['url', null, true];
    }

    #[DataProvider('truthTable')]
    public function testIsExportable(string $type, ?AnalystVerdict $verdict, bool $expected): void
    {
        self::assertSame($expected, IocExportPolicy::isExportable($type, $verdict));
    }

    public function testEveryFinancialTypeIsHeldWithoutConfirmation(): void
    {
        foreach (IocCategory::FINANCIAL_TYPES as $type) {
            self::assertFalse(
                IocExportPolicy::isExportable($type, null),
                "financial type '{$type}' must be held without an analyst confirmation",
            );
            self::assertTrue(
                IocExportPolicy::isExportable($type, AnalystVerdict::Confirmed),
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
    }
}
