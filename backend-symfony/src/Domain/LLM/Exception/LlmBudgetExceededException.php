<?php

declare(strict_types=1);

namespace App\Domain\LLM\Exception;

/**
 * Spec 065b — LLM cost guard
 *
 * Thrown when the monthly LLM budget is exhausted and a new LLM call is
 * attempted. The exception is caught in the ReplyController layer and
 * translated to HTTP 503 Service Unavailable with a `Retry-After` header
 * pointing to the next month rollover.
 *
 * Carries the current month-to-date spend, the configured monthly limit,
 * and the reset datetime as public readonly properties so the controller
 * can build a structured error response.
 */
final class LlmBudgetExceededException extends \RuntimeException
{
    public readonly \DateTimeImmutable $resetAt;

    public function __construct(
        public readonly float $currentUsdSpent,
        public readonly float $monthlyLimitUsd,
        ?\DateTimeImmutable $resetAt = null,
    ) {
        $this->resetAt = $resetAt ?? self::defaultResetAt();

        parent::__construct(sprintf(
            'LLM monthly budget exceeded: %.2f USD spent, %.2f USD limit, resets at %s',
            $currentUsdSpent,
            $monthlyLimitUsd,
            $this->resetAt->format(\DateTimeInterface::ATOM),
        ));
    }

    /**
     * Default reset: first day of next month at 00:00:00 UTC.
     */
    private static function defaultResetAt(): \DateTimeImmutable
    {
        return (new \DateTimeImmutable('first day of next month', new \DateTimeZone('UTC')))
            ->setTime(0, 0, 0);
    }
}
