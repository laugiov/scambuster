<?php

declare(strict_types=1);

namespace App\Tests\Unit\Domain\Scambaiting;

use App\Domain\Scambaiting\BanditConvergenceLog;
use PHPUnit\Framework\TestCase;

class BanditConvergenceLogTest extends TestCase
{
    public function testConstructAndGetters(): void
    {
        $now = new \DateTimeImmutable('2026-03-24 06:00:00');
        $log = new BanditConvergenceLog(
            scamTypeCode: 'ROMANCE',
            dominantPersonaCode: 'lonely_person',
            dominantPct: 0.72,
            sessionsCount: 45,
            converged: true,
            loggedAt: $now,
        );

        $this->assertNull($log->getId());
        $this->assertSame('ROMANCE', $log->getScamTypeCode());
        $this->assertSame('lonely_person', $log->getDominantPersonaCode());
        $this->assertEqualsWithDelta(0.72, $log->getDominantPct(), 0.001);
        $this->assertSame(45, $log->getSessionsCount());
        $this->assertTrue($log->isConverged());
        $this->assertSame($now, $log->getLoggedAt());
    }

    public function testDefaultLoggedAt(): void
    {
        $before = new \DateTimeImmutable();
        $log = new BanditConvergenceLog('PHISHING', 'confused_user', 0.55, 20, false);
        $after = new \DateTimeImmutable();

        $this->assertGreaterThanOrEqual($before, $log->getLoggedAt());
        $this->assertLessThanOrEqual($after, $log->getLoggedAt());
    }

    public function testNotConverged(): void
    {
        $log = new BanditConvergenceLog('TECH_SUPPORT', 'elderly_person', 0.35, 8, false);

        $this->assertFalse($log->isConverged());
        $this->assertSame(8, $log->getSessionsCount());
    }
}
