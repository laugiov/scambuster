<?php

declare(strict_types=1);

namespace App\Infrastructure\LLM\Resilience;

use App\Application\LLM\Port\Exception\LlmTransportException;
use App\Application\LLM\Port\LLMClientInterface;
use App\Application\LLM\Resilience\CircuitBreakerStore;
use App\Application\LLM\Resilience\CircuitRecord;
use App\Application\LLM\Resilience\CircuitState;
use Psr\Log\LoggerInterface;
use Symfony\Component\Clock\ClockInterface;

/**
 * Circuit breaker decorating an LLM provider client.
 *
 * After {@see $failureThreshold} consecutive provider-health failures the circuit
 * opens: for the next {@see $cooldownSeconds} seconds every call fails fast with a
 * {@see CircuitOpenException} instead of hammering a provider that is already
 * down. Once the cooldown elapses the circuit is half-open and traffic is
 * re-admitted so the provider can be probed; the first probe to succeed closes
 * the circuit, the first to fail re-opens it.
 *
 * Design notes for a future reader:
 * - **Only provider-health failures count.** A {@see LlmTransportException}
 *   (timeout, connection error, 5xx, malformed response) trips the breaker; a
 *   client-side {@see \App\Application\LLM\Port\Exception\LlmRequestException}
 *   (4xx, 429 rate-limit) is propagated WITHOUT counting, so a burst of bad
 *   requests — e.g. a remote party flooding the honeypot into provider-side
 *   429s — cannot manufacture a fleet-wide outage.
 * - **Keyed per purpose.** State is stored per `options['purpose']`
 *   (reply_generation, ttp_extraction, …), so a batch job tripping its own
 *   breaker never gates live reply traffic, and reply degradation never blinds
 *   intel (TTP/IOC) capture.
 * - **Best-effort under concurrency.** The store read-modify-write is not atomic
 *   and there is no half-open probe lock: under fleet concurrency the trip may
 *   lag the threshold by a call or two and several workers may probe at once
 *   when the cooldown elapses. This is bounded and self-correcting (no stuck-open
 *   state); a strict single-probe lease is a deliberate non-goal here.
 * - **Wall clock.** Cooldown math uses the wall clock; a backward NTP step can
 *   briefly extend an open window. Acceptable for minute-scale LLM outages.
 * - State lives in a shared {@see CircuitBreakerStore} (Redis) that fails open:
 *   a storage outage degrades to "always call the provider", never to a refusal.
 */
final readonly class CircuitBreakerLLMClient implements LLMClientInterface
{
    public function __construct(
        private LLMClientInterface $inner,
        private CircuitBreakerStore $store,
        private ClockInterface $clock,
        private LoggerInterface $logger,
        private int $failureThreshold,
        private int $cooldownSeconds,
        private int $ttlSeconds,
        private string $keyPrefix,
    ) {
    }

    public function chat(array $messages, array $options = []): string
    {
        // Sanitise + bound the purpose before it reaches the cache key: it must stay
        // a valid PSR-6 key (no reserved chars) and never blow up key cardinality,
        // whatever a future caller passes.
        $rawPurpose = \is_string($options['purpose'] ?? null) ? $options['purpose'] : 'default';
        $purpose = (string) preg_replace('/[^A-Za-z0-9_-]/', '_', $rawPurpose);
        $purpose = $purpose === '' ? 'default' : substr($purpose, 0, 64);
        $key = $this->keyPrefix . '.' . $purpose;

        // One store read per call (including the hot success path); the write below
        // only happens when there is breaker state to change, not on every success.
        $record = $this->store->load($key);
        $now = (float) $this->clock->now()->format('U.u');
        $state = $this->stateOf($record, $now);

        if ($state === CircuitState::Open) {
            $this->logger->warning('[CircuitBreaker] circuit open, failing fast', [
                'key' => $key,
                'consecutive_failures' => $record->consecutiveFailures,
                'reopens_in_s' => round($this->cooldownSeconds - ($now - (float) $record->openedAt), 1),
            ]);

            // Generic message: this propagates to API responses, so it must not
            // disclose internal circuit topology/state (that goes to the log above).
            throw new CircuitOpenException('LLM provider temporarily unavailable');
        }

        try {
            $result = $this->inner->chat($messages, $options);
        } catch (LlmTransportException $e) {
            // Provider-health failure: count it toward the trip.
            $this->recordFailure($key, $record, $now, $state);

            throw $e;
        }

        // Any other throwable (client-side 4xx/429, or an unexpected error) does not
        // count as an outage — let it propagate without touching breaker state.

        if ($state === CircuitState::HalfOpen) {
            $this->logger->info('[CircuitBreaker] probe succeeded, circuit closed', ['key' => $key]);
        }

        // Reset only when there is state to clear, so a healthy provider never
        // incurs a store WRITE on the hot path (it still incurs the read above).
        if ($record->consecutiveFailures > 0 || $record->openedAt !== null) {
            $this->store->save($key, CircuitRecord::closed(), $this->effectiveTtl());
        }

        return $result;
    }

    private function stateOf(CircuitRecord $record, float $now): CircuitState
    {
        if ($record->openedAt === null) {
            return CircuitState::Closed;
        }

        if ($now - $record->openedAt >= $this->cooldownSeconds) {
            return CircuitState::HalfOpen;
        }

        return CircuitState::Open;
    }

    private function recordFailure(string $key, CircuitRecord $record, float $now, CircuitState $state): void
    {
        // A failed half-open probe re-opens the circuit immediately for a fresh cooldown.
        if ($state === CircuitState::HalfOpen) {
            $this->store->save($key, $record->withFailure($now), $this->effectiveTtl());
            $this->logger->warning('[CircuitBreaker] half-open probe failed, circuit re-opened', [
                'key' => $key,
                'consecutive_failures' => $record->consecutiveFailures + 1,
            ]);

            return;
        }

        // Closed: count the failure and trip once the threshold is reached.
        $failures = $record->consecutiveFailures + 1;
        $opened = $failures >= $this->failureThreshold;
        $this->store->save($key, new CircuitRecord($failures, $opened ? $now : null), $this->effectiveTtl());

        if ($opened) {
            $this->logger->warning('[CircuitBreaker] failure threshold reached, circuit opened', [
                'key' => $key,
                'consecutive_failures' => $failures,
                'threshold' => $this->failureThreshold,
                'cooldown_s' => $this->cooldownSeconds,
            ]);
        }
    }

    /**
     * The stored record must outlive the cooldown, otherwise an open circuit could
     * expire mid-cooldown and re-admit traffic early; clamp up to the cooldown.
     */
    private function effectiveTtl(): int
    {
        return max($this->ttlSeconds, $this->cooldownSeconds);
    }
}
