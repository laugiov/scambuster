<?php

declare(strict_types=1);

namespace App\Application\Monitoring;

use App\Application\Audit\AuditLogger;
use App\Domain\Audit\AuditEventType;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Log\LoggerInterface;

/**
 * Spec 065b — Soft warning notifier for the LLM monthly budget.
 *
 * Polled by the `app:llm:check-budget` console command (via the
 * scheduler, every 15 minutes). Checks the 80% threshold and emits a
 * `BUDGET_THRESHOLD_REACHED` audit event exactly **once per day** to
 * avoid log spam if the threshold oscillates.
 *
 * Daily deduplication uses the application cache pool (PSR-6, backed
 * by Redis in production and filesystem in test/e2e) with a 24h TTL on
 * a key shaped `budget:threshold:reached:YYYY-MM-DD`.
 *
 * The notifier is intentionally non-blocking. The hard cap enforcement
 * lives in `ReplyHandler` (via `LlmCostHandler::isLimitExceeded()`),
 * not here.
 */
final class BudgetThresholdNotifier
{
    private const THRESHOLD_PCT = 0.8;
    private const CACHE_KEY_PREFIX = 'budget.threshold.reached.';
    private const CACHE_TTL_SECONDS = 86400; // 24h

    public function __construct(
        private readonly LlmCostHandler $costHandler,
        private readonly AuditLogger $auditLogger,
        private readonly CacheItemPoolInterface $cache,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Check the threshold and emit the audit event if needed.
     *
     * Idempotent within a single calendar day.
     */
    public function check(): void
    {
        if (!$this->costHandler->isThresholdReached(self::THRESHOLD_PCT)) {
            return;
        }

        $today = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d');
        $cacheKey = self::CACHE_KEY_PREFIX . $today;

        $item = $this->cache->getItem($cacheKey);

        if ($item->isHit()) {
            // Already notified today — no-op
            return;
        }

        $currentSpent = $this->costHandler->getCurrentMonthUsdSpent();
        $limit = $this->costHandler->getMonthlyLimitUsd();
        $pctUsed = $limit > 0 ? round(($currentSpent / $limit) * 100, 1) : 0.0;

        $this->logger->warning('[BudgetThresholdNotifier] LLM monthly budget threshold reached', [
            'current_usd' => $currentSpent,
            'limit_usd' => $limit,
            'pct_used' => $pctUsed,
            'threshold_pct' => self::THRESHOLD_PCT * 100,
        ]);

        $this->auditLogger->log(
            eventType: AuditEventType::BUDGET_THRESHOLD_REACHED,
            actorId: 'system',
            action: 'budget_check',
            outcome: 'warning',
            resourceType: 'llm_budget',
            resourceId: $today,
            details: [
                'current_usd' => $currentSpent,
                'limit_usd' => $limit,
                'pct_used' => $pctUsed,
                'threshold_pct' => self::THRESHOLD_PCT * 100,
            ],
            actorType: 'system',
        );

        // Mark today as notified
        $item->set(true);
        $item->expiresAfter(self::CACHE_TTL_SECONDS);
        $this->cache->save($item);
    }
}
