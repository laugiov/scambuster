<?php

declare(strict_types=1);

namespace App\Tests\Unit\Domain\Audit;

use App\Domain\Audit\AuditEventType;
use App\Domain\Audit\SiemSeverityMap;
use PHPUnit\Framework\TestCase;

class SiemSeverityMapTest extends TestCase
{
    /**
     * @dataProvider severityProvider
     */
    public function testGetSeverityReturnsExpectedValue(AuditEventType $type, int $expected): void
    {
        $this->assertSame($expected, SiemSeverityMap::getSeverity($type));
    }

    /**
     * @return iterable<string, array{AuditEventType, int}>
     */
    public static function severityProvider(): iterable
    {
        yield 'AUTH_SUCCESS is low' => [AuditEventType::AUTH_SUCCESS, 1];
        yield 'AUTH_FAILURE is medium' => [AuditEventType::AUTH_FAILURE, 5];
        yield 'INJECTION_DETECTED is high' => [AuditEventType::INJECTION_DETECTED, 8];
        yield 'KILL_SWITCH_TOGGLED is critical' => [AuditEventType::KILL_SWITCH_TOGGLED, 9];
        yield 'IOC_EXTRACTED is medium' => [AuditEventType::IOC_EXTRACTED, 4];
        yield 'CONFIG_CHANGED is high' => [AuditEventType::CONFIG_CHANGED, 7];
        yield 'RATE_LIMIT_EXCEEDED is medium' => [AuditEventType::RATE_LIMIT_EXCEEDED, 6];
        yield 'MESSAGE_INGESTED is low-medium' => [AuditEventType::MESSAGE_INGESTED, 3];
        yield 'EXPORT_MISP is low' => [AuditEventType::EXPORT_MISP, 2];
        yield 'PERSONA_SELECTED is low' => [AuditEventType::PERSONA_SELECTED, 1];
    }

    /**
     * @dataProvider labelProvider
     */
    public function testGetLabelReturnsCorrectCategory(int $severity, string $expected): void
    {
        $this->assertSame($expected, SiemSeverityMap::getLabel($severity));
    }

    /**
     * @return iterable<string, array{int, string}>
     */
    public static function labelProvider(): iterable
    {
        yield 'severity 0 is Low' => [0, 'Low'];
        yield 'severity 1 is Low' => [1, 'Low'];
        yield 'severity 3 is Low' => [3, 'Low'];
        yield 'severity 4 is Medium' => [4, 'Medium'];
        yield 'severity 6 is Medium' => [6, 'Medium'];
        yield 'severity 7 is High' => [7, 'High'];
        yield 'severity 8 is High' => [8, 'High'];
        yield 'severity 9 is Critical' => [9, 'Critical'];
        yield 'severity 10 is Critical' => [10, 'Critical'];
    }

    /**
     * @dataProvider ecsCategoryProvider
     */
    public function testGetEcsCategoryReturnsExpectedValue(AuditEventType $type, string $expected): void
    {
        $this->assertSame($expected, SiemSeverityMap::getEcsCategory($type));
    }

    /**
     * @return iterable<string, array{AuditEventType, string}>
     */
    public static function ecsCategoryProvider(): iterable
    {
        yield 'AUTH_SUCCESS => authentication' => [AuditEventType::AUTH_SUCCESS, 'authentication'];
        yield 'AUTH_FAILURE => authentication' => [AuditEventType::AUTH_FAILURE, 'authentication'];
        yield 'AUTH_LOGOUT => authentication' => [AuditEventType::AUTH_LOGOUT, 'authentication'];
        yield 'AUTH_TOKEN_EXPIRED => authentication' => [AuditEventType::AUTH_TOKEN_EXPIRED, 'authentication'];
        yield 'MESSAGE_INGESTED => email' => [AuditEventType::MESSAGE_INGESTED, 'email'];
        yield 'REPLY_SENT => email' => [AuditEventType::REPLY_SENT, 'email'];
        yield 'INJECTION_DETECTED => intrusion_detection' => [AuditEventType::INJECTION_DETECTED, 'intrusion_detection'];
        yield 'RATE_LIMIT_EXCEEDED => intrusion_detection' => [AuditEventType::RATE_LIMIT_EXCEEDED, 'intrusion_detection'];
        yield 'IOC_EXTRACTED => threat' => [AuditEventType::IOC_EXTRACTED, 'threat'];
        yield 'KILL_SWITCH_TOGGLED => configuration' => [AuditEventType::KILL_SWITCH_TOGGLED, 'configuration'];
        yield 'CONFIG_CHANGED => configuration' => [AuditEventType::CONFIG_CHANGED, 'configuration'];
        yield 'REPLY_GENERATED => process' => [AuditEventType::REPLY_GENERATED, 'process'];
        yield 'CONVERSATION_CLOSED => process' => [AuditEventType::CONVERSATION_CLOSED, 'process'];
        yield 'EXPORT_MISP => process' => [AuditEventType::EXPORT_MISP, 'process'];
    }

    public function testAllEventTypesHaveSeverityMapping(): void
    {
        foreach (AuditEventType::cases() as $type) {
            $severity = SiemSeverityMap::getSeverity($type);
            $this->assertGreaterThanOrEqual(0, $severity, "Severity for {$type->value} must be >= 0");
            $this->assertLessThanOrEqual(10, $severity, "Severity for {$type->value} must be <= 10");
        }
    }

    public function testAllEventTypesHaveEcsCategory(): void
    {
        foreach (AuditEventType::cases() as $type) {
            $category = SiemSeverityMap::getEcsCategory($type);
            $this->assertNotEmpty($category, "ECS category for {$type->value} must not be empty");
        }
    }
}
