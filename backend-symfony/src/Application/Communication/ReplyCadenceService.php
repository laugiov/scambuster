<?php

declare(strict_types=1);

namespace App\Application\Communication;

use App\Application\Audit\AuditLogger;
use App\Domain\Audit\AuditEventType;
use App\Domain\Communication\Message;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
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

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly LoggerInterface $logger,
        private readonly ?RateLimiterFactory $repliesPerConversationLimiter = null,
        private readonly ?RateLimiterFactory $llmCallsPerHourLimiter = null,
        private readonly ?RateLimiterFactory $activeConversationsPerDayLimiter = null,
        private readonly ?AuditLogger $auditLogger = null,
    ) {
    }

    /**
     * Check if kill switch is active.
     *
     * Reads from SCAMBUSTER_KILL_SWITCH environment variable.
     * Any truthy value ('1', 'true', 'yes', 'on') activates the kill switch
     * and halts all automated reply generation and sending.
     */
    public function isKillSwitchActive(): bool
    {
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
        if ($this->repliesPerConversationLimiter !== null) {
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
        if ($this->llmCallsPerHourLimiter !== null) {
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
        if ($this->activeConversationsPerDayLimiter !== null) {
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
        // Use "*" to allow ALL domains (production mode -- ScamBuster only receives from scammers)
        // Use specific domains to restrict during testing
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

        // Extract domain - handle invalid emails gracefully
        $atPos = strrchr($email, '@');

        if ($atPos === false) {
            return false;
        }

        $domain = strtolower(substr($atPos, 1));

        return in_array($domain, $safeDomains, true);
    }

    public function dispatchRateLimitAudit(string $limitType, string $convId): void
    {
        if ($this->auditLogger === null) {
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
