<?php

declare(strict_types=1);

namespace App\Tests\Unit\Application\Communication;

use App\Application\Communication\IocQueryService;
use PHPUnit\Framework\TestCase;

/**
 * Tests for IocQueryService::normalizeTimestamp private method.
 * Uses Reflection to access the private method directly.
 *
 * Targets uncovered lines: 464-476 (normalizeTimestamp branches).
 */
class IocQueryServiceNormalizeTimestampTest extends TestCase
{
    private \ReflectionMethod $method;
    private IocQueryService $service;

    protected function setUp(): void
    {
        $ref = new \ReflectionClass(IocQueryService::class);
        $this->service = $ref->newInstanceWithoutConstructor();
        $this->method = $ref->getMethod('normalizeTimestamp');
    }

    public function testReturnsEmptyForNullInput(): void
    {
        $this->assertSame('', $this->method->invoke($this->service, null));
    }

    public function testReturnsEmptyForEmptyString(): void
    {
        $this->assertSame('', $this->method->invoke($this->service, ''));
    }

    public function testReturnsEmptyForNonString(): void
    {
        $this->assertSame('', $this->method->invoke($this->service, 123));
    }

    public function testPassthroughForTimestampWithPlusTimezone(): void
    {
        $ts = '2026-04-10T12:00:00+02:00';
        $this->assertSame($ts, $this->method->invoke($this->service, $ts));
    }

    public function testPassthroughForTimestampWithZ(): void
    {
        $ts = '2026-04-10T12:00:00Z';
        $this->assertSame($ts, $this->method->invoke($this->service, $ts));
    }

    public function testConvertsNaiveTimestampToUTC(): void
    {
        $result = $this->method->invoke($this->service, '2026-04-10 14:30:00');
        $this->assertStringContainsString('2026-04-10', $result);
        $this->assertStringContainsString('+00:00', $result);
    }

    public function testReturnsRawForUnparseableTimestamp(): void
    {
        $raw = 'not-a-date-at-all';
        $result = $this->method->invoke($this->service, $raw);
        $this->assertSame($raw, $result);
    }
}
