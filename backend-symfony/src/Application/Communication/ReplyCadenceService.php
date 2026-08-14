<?php

declare(strict_types=1);

namespace App\Application\Communication;

use App\Application\Audit\AuditLogger;
use App\Domain\Audit\AuditEventType;
use App\Domain\Communication\Message;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Mime\Address;
use Symfony\Component\RateLimiter\RateLimiterFactory;

/**
 * Enforces reply cadence, rate limits, safelist, and kill switch.
 *
 * Responsibilities:
 * - Kill switch (env-based global halt)
 * - Minimum time between replies per conversation
 * - Redis-backed rate limits (per-conversation, global LLM, active conversations)
 * - Domain safelist validation
 * - Rate limit audit logging
 */
class ReplyCadenceService
{
    private const MIN_HOURS_BETWEEN_REPLIES = 6;

    /** Cache key used by the admin endpoint to toggle the kill switch at runtime. */
    public const KILL_SWITCH_CACHE_KEY = 'llm.killswitch.active';

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly LoggerInterface $logger,
        private readonly ?RateLimiterFactory $repliesPerConversationLimiter = null,
        private readonly ?RateLimiterFactory $llmCallsPerHourLimiter = null,
        private readonly ?RateLimiterFactory $activeConversationsPerDayLimiter = null,
        private readonly ?AuditLogger $auditLogger = null,
        private readonly ?CacheItemPoolInterface $killSwitchCache = null,
    ) {
    }

    /**
     * Check if kill switch is active.
     *
     * Two layers:
     *   1. Runtime toggle via the application cache pool (Redis in prod,
     *      filesystem in test/e2e). Set by the admin endpoint
     *      POST /api/v1/admin/llm/killswitch.
     *   2. Fallback env var SCAMBUSTER_KILL_SWITCH for emergency operator
     *      access (shell on the host with no admin token).
     *
     * Either layer enabled → kill switch is active.
     */
    public function isKillSwitchActive(): bool
    {
        // Layer 1: runtime cache pool toggle
        if ($this->killSwitchCache instanceof \Psr\Cache\CacheItemPoolInterface) {
            try {
                $item = $this->killSwitchCache->getItem(self::KILL_SWITCH_CACHE_KEY);

                if ($item->isHit() && $item->get() === true) {
                    return true;
                }
            } catch (\Throwable $e) {
                // Cache pool failure must not crash the cadence check.
                $this->logger->warning('[ReplyCadenceService] Kill switch cache lookup failed', [
                    'error' => $e->getMessage(),
                ]);
            }
        }

        // Layer 2: env var fallback
        $value = $_ENV['SCAMBUSTER_KILL_SWITCH'] ?? $_SERVER['SCAMBUSTER_KILL_SWITCH'] ?? '0';

        return filter_var($value, FILTER_VALIDATE_BOOLEAN);
    }

    /**
     * Check cadence (minimum time between replies).
     */
    public function checkCadence(string $convId): bool
    {
        // Get last outgoing message in this conversation
        /** @var Message|null $lastOut */
        $lastOut = $this->em->createQueryBuilder()
            ->select('m')
            ->from(Message::class, 'm')
            ->join('m.direction', 'd')
            ->where('m.conversation = :convId')
            ->andWhere('d.code = :out')
            ->andWhere('m.deletedAt IS NULL')
            ->setParameter('convId', $convId)
            ->setParameter('out', 'out')
            ->orderBy('m.tsMsg', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();

        if (!$lastOut) {
            return true; // No previous outgoing message
        }

        $now = new \DateTimeImmutable();
        $diff = $now->getTimestamp() - $lastOut->getTsMsg()->getTimestamp();
        $hoursDiff = $diff / 3600;

        return $hoursDiff >= self::MIN_HOURS_BETWEEN_REPLIES;
    }

    /**
     * Check Redis-backed rate limits at three levels.
     *
     * Returns null if all limits pass, or a string describing which limit was exceeded.
     */
    public function checkRateLimits(string $convId): ?string
    {
        // Level 1: max replies per conversation per day
        if ($this->repliesPerConversationLimiter instanceof \Symfony\Component\RateLimiter\RateLimiterFactory) {
            $limiter = $this->repliesPerConversationLimiter->create($convId);
            $limit = $limiter->consume();

            if (!$limit->isAccepted()) {
                $this->logger->warning('[ReplyCadenceService] Rate limit exceeded: replies per conversation', [
                    'conv_id' => $convId,
                    'retry_after' => $limit->getRetryAfter()->format(DATE_ATOM),
                ]);
                $this->dispatchRateLimitAudit('conv_replies', $convId);

                return 'max replies per conversation per day';
            }
        }

        // Level 2: max LLM API calls per hour (global)
        if ($this->llmCallsPerHourLimiter instanceof \Symfony\Component\RateLimiter\RateLimiterFactory) {
            $limiter = $this->llmCallsPerHourLimiter->create('global');
            $limit = $limiter->consume();

            if (!$limit->isAccepted()) {
                $this->logger->warning('[ReplyCadenceService] Rate limit exceeded: LLM calls per hour', [
                    'retry_after' => $limit->getRetryAfter()->format(DATE_ATOM),
                ]);
                $this->dispatchRateLimitAudit('llm_calls_per_hour', $convId);

                return 'max LLM API calls per hour';
            }
        }

        // Level 3: max active conversations per day
        if ($this->activeConversationsPerDayLimiter instanceof \Symfony\Component\RateLimiter\RateLimiterFactory) {
            $limiter = $this->activeConversationsPerDayLimiter->create('global');
            $limit = $limiter->consume();

            if (!$limit->isAccepted()) {
                $this->logger->warning('[ReplyCadenceService] Rate limit exceeded: active conversations per day', [
                    'retry_after' => $limit->getRetryAfter()->format(DATE_ATOM),
                ]);
                $this->dispatchRateLimitAudit('active_conversations_per_day', $convId);

                return 'max active conversations per day';
            }
        }

        return null;
    }

    /**
     * Check if email is in safelist.
     */
    public function checkSafelist(string $email): bool
    {
        // Load safe domains from env var (comma-separated)
        // "*" disables the check entirely. It is supported, not recommended: the
        // recipient comes from the inbound mail, so "*" lets an inbound message
        // decide who receives mail from the operator mailbox. See .env.dist.
        // Use specific domains to restrict during testing
        /** @var string $envDomains */
        $envDomains = $_ENV['SCAMBUSTER_SAFE_DOMAINS'] ?? $_SERVER['SCAMBUSTER_SAFE_DOMAINS'] ?? '';

        // Wildcard: allow all domains in production
        if (trim($envDomains) === '*') {
            return true;
        }

        $safeDomains = ['example.test', 'mailinator.com', 'guerrillamail.com'];

        if ($envDomains !== '') {
            $extraDomains = array_map('trim', explode(',', $envDomains));
            $safeDomains = array_merge($safeDomains, array_filter($extraDomains));
        }

        // Extract the domain the way the *sender* will, not the way a regex
        // would. `strrchr($email, '@')` on the raw header disagrees with
        // Symfony's Address parser on values a scammer controls:
        // `victim@target.example@gmail.com` reads as `gmail.com` to strrchr
        // (allowed) while the mail goes to the literal string. A safelist that
        // parses differently from the sender is not a safelist.
        try {
            $address = Address::create($email)->getAddress();
        } catch (\Throwable) {
            // Not a parseable mailbox — `bad space@custom.org` and friends stop
            // here. Nothing to allow.
            return false;
        }

        // The one malformed shape Address::create lets through:
        // `victim@target.example@gmail.com` parses, and `strrchr` would read it
        // as `gmail.com`. More than one `@` means there is no single domain to
        // reason about.
        if (substr_count($address, '@') !== 1) {
            return false;
        }

        $atPos = strrchr($address, '@');

        if ($atPos === false) {
            return false;
        }

        $domain = strtolower(substr($atPos, 1));

        if ($domain === '') {
            return false;
        }

        return in_array($domain, $safeDomains, true);
    }

    public function dispatchRateLimitAudit(string $limitType, string $convId): void
    {
        if (!$this->auditLogger instanceof \App\Application\Audit\AuditLogger) {
            return;
        }

        $this->auditLogger->log(
            eventType: AuditEventType::RATE_LIMIT_EXCEEDED,
            actorId: 'system',
            action: 'rate_limit',
            outcome: 'blocked',
            resourceType: 'conversation',
            resourceId: $convId,
            details: ['limit_type' => $limitType],
            actorType: 'system'
        );
    }
}
