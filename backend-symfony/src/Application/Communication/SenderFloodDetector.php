<?php

declare(strict_types=1);

namespace App\Application\Communication;

use Psr\Cache\CacheItemPoolInterface;
use Psr\Log\LoggerInterface;

/**
 * Detects sender email flooding (burst of N emails in M minutes).
 *
 * Uses Symfony cache (Redis-backed) for burst counting and quarantine.
 * Does NOT use Symfony RateLimiter — needs custom TTL-based burst logic.
 */
final class SenderFloodDetector
{
    private const BURST_THRESHOLD = 5;
    private const BURST_WINDOW_SECONDS = 300;    // 5 minutes
    private const QUARANTINE_SECONDS = 3600;      // 1 hour

    public function __construct(
        private readonly CacheItemPoolInterface $cache,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Check if sender is quarantined.
     */
    public function isQuarantined(string $senderHash): bool
    {
        $item = $this->cache->getItem("scambuster.quarantine.{$senderHash}");

        return $item->isHit();
    }

    /**
     * Record an email from this sender and check for flood.
     *
     * @return bool true if flood detected (sender is now quarantined)
     */
    public function recordAndCheck(string $senderHash): bool
    {
        // Check existing quarantine first
        if ($this->isQuarantined($senderHash)) {
            return true;
        }

        $key = "scambuster.flood.{$senderHash}";
        $item = $this->cache->getItem($key);

        /** @var int $prevCount */
        $prevCount = $item->isHit() ? $item->get() : 0;
        $count = $prevCount + 1;

        $item->set($count);
        $item->expiresAfter(self::BURST_WINDOW_SECONDS);
        $this->cache->save($item);

        if ($count >= self::BURST_THRESHOLD) {
            // Quarantine the sender
            $quarantineItem = $this->cache->getItem("scambuster.quarantine.{$senderHash}");
            $quarantineItem->set(true);
            $quarantineItem->expiresAfter(self::QUARANTINE_SECONDS);
            $this->cache->save($quarantineItem);

            $this->logger->warning('[FloodDetector] Sender quarantined: {count} emails in burst window', [
                'sender_hash' => $senderHash,
                'count' => $count,
                'quarantine_seconds' => self::QUARANTINE_SECONDS,
            ]);

            return true;
        }

        return false;
    }

}
