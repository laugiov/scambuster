<?php

declare(strict_types=1);

namespace App\Tests\Unit\Application\Communication;

use App\Application\Communication\SenderFloodDetector;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Cache\Adapter\ArrayAdapter;

class SenderFloodDetectorTest extends TestCase
{
    private SenderFloodDetector $detector;
    private ArrayAdapter $cache;

    protected function setUp(): void
    {
        $this->cache = new ArrayAdapter();
        $this->detector = new SenderFloodDetector($this->cache, new NullLogger());
    }

    public function testNotQuarantinedInitially(): void
    {
        $this->assertFalse($this->detector->isQuarantined('abc123'));
    }

    public function testFirstFourEmailsNotFlooded(): void
    {
        $hash = hash('sha256', 'test@example.com');

        for ($i = 0; $i < 4; $i++) {
            $this->assertFalse($this->detector->recordAndCheck($hash), "Email #{$i} should not trigger flood");
        }
    }

    public function testFifthEmailTriggersFlood(): void
    {
        $hash = hash('sha256', 'flood@example.com');

        for ($i = 0; $i < 4; $i++) {
            $this->detector->recordAndCheck($hash);
        }

        $this->assertTrue($this->detector->recordAndCheck($hash), 'Fifth email should trigger flood');
    }

    public function testQuarantineSetAfterFlood(): void
    {
        $hash = hash('sha256', 'quarantine@example.com');

        for ($i = 0; $i < 5; $i++) {
            $this->detector->recordAndCheck($hash);
        }

        $this->assertTrue($this->detector->isQuarantined($hash));
    }

    public function testQuarantinedSenderAlwaysBlocked(): void
    {
        $hash = hash('sha256', 'blocked@example.com');

        // Trigger flood
        for ($i = 0; $i < 5; $i++) {
            $this->detector->recordAndCheck($hash);
        }

        // Subsequent calls should still return true
        $this->assertTrue($this->detector->recordAndCheck($hash));
        $this->assertTrue($this->detector->recordAndCheck($hash));
    }

    public function testDifferentSendersIndependent(): void
    {
        $hash1 = hash('sha256', 'sender1@example.com');
        $hash2 = hash('sha256', 'sender2@example.com');

        // Flood sender1
        for ($i = 0; $i < 5; $i++) {
            $this->detector->recordAndCheck($hash1);
        }

        // sender2 should not be affected
        $this->assertFalse($this->detector->isQuarantined($hash2));
        $this->assertFalse($this->detector->recordAndCheck($hash2));
    }
}
