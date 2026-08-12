<?php

declare(strict_types=1);

namespace App\Tests\Unit\Infrastructure\LLM\Resilience;

use App\Application\LLM\Port\Exception\LlmRequestException;
use App\Application\LLM\Port\Exception\LlmTransportException;
use App\Application\LLM\Port\LLMClientInterface;
use App\Application\LLM\Resilience\CircuitBreakerStore;
use App\Application\LLM\Resilience\CircuitRecord;
use App\Infrastructure\LLM\Resilience\CircuitBreakerLLMClient;
use App\Infrastructure\LLM\Resilience\CircuitOpenException;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Clock\MockClock;

/**
 * The circuit-breaker decorator's state machine: it delegates while closed, trips
 * to open after N consecutive PROVIDER-HEALTH failures (not client-side ones),
 * fails fast during the cooldown, then re-admits traffic to probe recovery. State
 * is keyed per purpose so workloads do not gate each other.
 */
final class CircuitBreakerLLMClientTest extends TestCase
{
    private const THRESHOLD = 3;
    private const COOLDOWN = 30;
    private const TTL = 300;

    public function testClosedCircuitDelegatesAndReturnsProviderResult(): void
    {
        $inner = new ProgrammableLLMClient(['ok']);
        $breaker = $this->breaker($inner);

        self::assertSame('ok', $breaker->chat([['role' => 'user', 'content' => 'hi']]));
        self::assertSame(1, $inner->calls);
    }

    public function testOpensAfterThresholdConsecutiveTransportFailuresThenFailsFast(): void
    {
        $inner = new ProgrammableLLMClient([
            new LlmTransportException('down'),
            new LlmTransportException('down'),
            new LlmTransportException('down'),
            'must-not-run',
        ]);
        $breaker = $this->breaker($inner);

        for ($i = 0; $i < self::THRESHOLD; ++$i) {
            try {
                $breaker->chat([['role' => 'user', 'content' => 'hi']]);
                self::fail('expected provider failure to propagate');
            } catch (CircuitOpenException) {
                self::fail('must surface the provider error, not open, before the threshold');
            } catch (LlmTransportException $e) {
                self::assertSame('down', $e->getMessage());
            }
        }

        $this->expectException(CircuitOpenException::class);

        try {
            $breaker->chat([['role' => 'user', 'content' => 'hi']]);
        } finally {
            self::assertSame(self::THRESHOLD, $inner->calls, 'provider must not be called while open');
        }
    }

    public function testClientErrorsNeverTripTheBreaker(): void
    {
        // 4xx/429 come back as LlmRequestException: a flood of them (e.g. a remote
        // party pushing the provider into rate-limiting) must never open the circuit.
        $inner = new ProgrammableLLMClient(array_fill(0, 10, new LlmRequestException('429 Too Many Requests')));
        $breaker = $this->breaker($inner);

        for ($i = 0; $i < 10; ++$i) {
            try {
                $breaker->chat([['role' => 'user', 'content' => 'hi']]);
                self::fail('expected the client error to propagate');
            } catch (CircuitOpenException) {
                self::fail('client errors must not open the circuit');
            } catch (LlmRequestException) {
                // expected — propagated without tripping
            }
        }

        self::assertSame(10, $inner->calls, 'every call must still reach the provider');
    }

    public function testBreakerIsKeyedPerPurpose(): void
    {
        // Trip the breaker for purpose "a"; purpose "b" must stay unaffected.
        $inner = new ProgrammableLLMClient([
            new LlmTransportException('down'),
            new LlmTransportException('down'),
            new LlmTransportException('down'),
            'b-still-works',
        ]);
        $breaker = $this->breaker($inner);

        for ($i = 0; $i < self::THRESHOLD; ++$i) {
            $this->swallow(fn () => $breaker->chat([['role' => 'user', 'content' => 'x']], ['purpose' => 'a']));
        }

        // Purpose "a" is now open (fast fail, provider untouched)...
        $this->assertFailsFast($breaker, ['purpose' => 'a']);
        self::assertSame(self::THRESHOLD, $inner->calls);

        // ...but purpose "b" has its own circuit and still reaches the provider.
        self::assertSame('b-still-works', $breaker->chat([['role' => 'user', 'content' => 'x']], ['purpose' => 'b']));
    }

    public function testSanitisesThePurposeIntoASafePsr6Key(): void
    {
        $store = new KeyCapturingStore();
        $breaker = new CircuitBreakerLLMClient(
            new ProgrammableLLMClient(['ok']),
            $store,
            new MockClock(),
            new NullLogger(),
            self::THRESHOLD,
            self::COOLDOWN,
            self::TTL,
            'llm',
        );

        // Reserved PSR-6 chars ({}()/\@:) and an over-long value must not reach the key.
        $breaker->chat([['role' => 'user', 'content' => 'x']], ['purpose' => 'a/b:c@' . str_repeat('x', 200)]);

        self::assertNotNull($store->lastKey);
        self::assertSame(1, preg_match('/^llm\.[A-Za-z0-9_-]+$/', $store->lastKey), 'key must be prefix + a PSR-6-safe segment');
        self::assertLessThanOrEqual(4 + 64, \strlen($store->lastKey), 'the purpose segment must be length-bounded');
    }

    public function testASuccessResetsTheFailureStreak(): void
    {
        $inner = new ProgrammableLLMClient([
            new LlmTransportException('down'),
            new LlmTransportException('down'),
            'recovered',
            new LlmTransportException('down'),
            'still-closed',
        ]);
        $breaker = $this->breaker($inner);

        $this->swallow(fn () => $breaker->chat([['role' => 'user', 'content' => 'x']]));
        $this->swallow(fn () => $breaker->chat([['role' => 'user', 'content' => 'x']]));
        self::assertSame('recovered', $breaker->chat([['role' => 'user', 'content' => 'x']]));
        $this->swallow(fn () => $breaker->chat([['role' => 'user', 'content' => 'x']]));

        self::assertSame('still-closed', $breaker->chat([['role' => 'user', 'content' => 'x']]));
        self::assertSame(5, $inner->calls);
    }

    public function testHalfOpenProbeSuccessClosesTheCircuit(): void
    {
        $clock = new MockClock();
        $inner = new ProgrammableLLMClient([
            new LlmTransportException('down'),
            new LlmTransportException('down'),
            new LlmTransportException('down'),
            'probe-ok',
            'fully-open-again',
        ]);
        $breaker = $this->breaker($inner, $clock);

        for ($i = 0; $i < self::THRESHOLD; ++$i) {
            $this->swallow(fn () => $breaker->chat([['role' => 'user', 'content' => 'x']]));
        }

        $clock->sleep(self::COOLDOWN - 1);
        $this->assertFailsFast($breaker);
        self::assertSame(self::THRESHOLD, $inner->calls);

        $clock->sleep(2);
        self::assertSame('probe-ok', $breaker->chat([['role' => 'user', 'content' => 'x']]));
        self::assertSame(self::THRESHOLD + 1, $inner->calls);

        self::assertSame('fully-open-again', $breaker->chat([['role' => 'user', 'content' => 'x']]));
    }

    public function testHalfOpenProbeFailureReopensTheCircuit(): void
    {
        $clock = new MockClock();
        $inner = new ProgrammableLLMClient([
            new LlmTransportException('down'),
            new LlmTransportException('down'),
            new LlmTransportException('down'),
            new LlmTransportException('still-down'),
            'must-not-run',
        ]);
        $breaker = $this->breaker($inner, $clock);

        for ($i = 0; $i < self::THRESHOLD; ++$i) {
            $this->swallow(fn () => $breaker->chat([['role' => 'user', 'content' => 'x']]));
        }

        $clock->sleep(self::COOLDOWN + 1);
        $this->swallow(fn () => $breaker->chat([['role' => 'user', 'content' => 'x']]));
        self::assertSame(self::THRESHOLD + 1, $inner->calls);

        $this->assertFailsFast($breaker);
        self::assertSame(self::THRESHOLD + 1, $inner->calls);
    }

    public function testFailsOpenWhenTheStoreIsUnavailable(): void
    {
        // A store that always reads closed and drops writes (its own outage) must
        // never stop the breaker from calling the provider.
        $inner = new ProgrammableLLMClient(['ok', 'ok']);
        $breaker = new CircuitBreakerLLMClient(
            $inner,
            new AlwaysClosedStore(),
            new MockClock(),
            new NullLogger(),
            self::THRESHOLD,
            self::COOLDOWN,
            self::TTL,
            'llm_chat',
        );

        self::assertSame('ok', $breaker->chat([['role' => 'user', 'content' => 'x']]));
        self::assertSame('ok', $breaker->chat([['role' => 'user', 'content' => 'x']]));
        self::assertSame(2, $inner->calls);
    }

    private function breaker(LLMClientInterface $inner, ?MockClock $clock = null): CircuitBreakerLLMClient
    {
        return new CircuitBreakerLLMClient(
            $inner,
            new InMemoryCircuitBreakerStore(),
            $clock ?? new MockClock(),
            new NullLogger(),
            self::THRESHOLD,
            self::COOLDOWN,
            self::TTL,
            'llm_chat',
        );
    }

    /** @param array<string, mixed> $options */
    private function assertFailsFast(CircuitBreakerLLMClient $breaker, array $options = []): void
    {
        try {
            $breaker->chat([['role' => 'user', 'content' => 'x']], $options);
            self::fail('expected the open circuit to fail fast');
        } catch (CircuitOpenException) {
            // expected
        }
    }

    private function swallow(callable $fn): void
    {
        try {
            $fn();
        } catch (LlmTransportException) {
            // expected provider failure
        }
    }
}

/**
 * Inner client returning a scripted sequence of results; a \Throwable entry is
 * thrown, any other value is returned as the response string.
 */
final class ProgrammableLLMClient implements LLMClientInterface
{
    public int $calls = 0;

    /** @param list<string|\Throwable> $script */
    public function __construct(private array $script)
    {
    }

    public function chat(array $messages, array $options = []): string
    {
        $step = $this->script[$this->calls] ?? throw new \LogicException('no scripted response for call ' . $this->calls);
        ++$this->calls;

        if ($step instanceof \Throwable) {
            throw $step;
        }

        return $step;
    }
}

final class InMemoryCircuitBreakerStore implements CircuitBreakerStore
{
    /** @var array<string, CircuitRecord> */
    private array $records = [];

    public function load(string $key): CircuitRecord
    {
        return $this->records[$key] ?? CircuitRecord::closed();
    }

    public function save(string $key, CircuitRecord $record, int $ttlSeconds): void
    {
        $this->records[$key] = $record;
    }
}

/** Records the last key it was asked to load, to assert key derivation. */
final class KeyCapturingStore implements CircuitBreakerStore
{
    public ?string $lastKey = null;

    public function load(string $key): CircuitRecord
    {
        $this->lastKey = $key;

        return CircuitRecord::closed();
    }

    public function save(string $key, CircuitRecord $record, int $ttlSeconds): void
    {
    }
}

/** Simulates a store outage: always closed, never persists (fail-open). */
final class AlwaysClosedStore implements CircuitBreakerStore
{
    public function load(string $key): CircuitRecord
    {
        return CircuitRecord::closed();
    }

    public function save(string $key, CircuitRecord $record, int $ttlSeconds): void
    {
        // drop
    }
}
