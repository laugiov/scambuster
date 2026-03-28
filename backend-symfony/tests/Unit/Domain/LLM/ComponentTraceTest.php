<?php

declare(strict_types=1);

namespace App\Tests\Unit\Domain\LLM;

use App\Domain\LLM\ComponentTrace;
use PHPUnit\Framework\TestCase;

final class ComponentTraceTest extends TestCase
{
    public function test_ran_factory(): void
    {
        $trace = ComponentTrace::ran('policy_guard', 5.2, ['approved' => true, 'flags' => []], 0.001);

        $this->assertSame('policy_guard', $trace->name);
        $this->assertSame('ran', $trace->status);
        $this->assertSame(5.2, $trace->durationMs);
        $this->assertSame(0.001, $trace->cost);
        $this->assertTrue($trace->output['approved']);
        $this->assertNull($trace->error);
        $this->assertNull($trace->skipReason);
    }

    public function test_skipped_factory(): void
    {
        $trace = ComponentTrace::skipped('conversation_analyzer', 'message_count < 2');

        $this->assertSame('skipped', $trace->status);
        $this->assertSame(0.0, $trace->durationMs);
        $this->assertSame('message_count < 2', $trace->skipReason);
        $this->assertNull($trace->cost);
    }

    public function test_error_factory(): void
    {
        $trace = ComponentTrace::error('reply_validator', 'JSON parse failed', 3.1);

        $this->assertSame('error', $trace->status);
        $this->assertSame('JSON parse failed', $trace->error);
        $this->assertSame(3.1, $trace->durationMs);
    }

    public function test_to_array_minimal(): void
    {
        $trace = ComponentTrace::ran('ioc_scorer', 1.0);
        $array = $trace->toArray();

        $this->assertSame('ioc_scorer', $array['name']);
        $this->assertSame('ran', $array['status']);
        $this->assertSame(1.0, $array['duration_ms']);
        $this->assertArrayNotHasKey('cost', $array);
        $this->assertArrayNotHasKey('error', $array);
        $this->assertArrayNotHasKey('skip_reason', $array);
    }

    public function test_to_array_full(): void
    {
        $trace = ComponentTrace::ran('reply_validator', 5000.0, ['naturalness' => 4], 0.02);
        $array = $trace->toArray();

        $this->assertSame(0.02, $array['cost']);
        $this->assertSame(['naturalness' => 4], $array['output']);
    }

    public function test_roundtrip(): void
    {
        $original = ComponentTrace::ran('policy_guard', 3.5, ['flags' => ['too_short']], 0.001);
        $restored = ComponentTrace::fromArray($original->toArray());

        $this->assertSame($original->name, $restored->name);
        $this->assertSame($original->status, $restored->status);
        $this->assertSame($original->durationMs, $restored->durationMs);
        $this->assertSame($original->cost, $restored->cost);
    }

    public function test_skipped_roundtrip(): void
    {
        $original = ComponentTrace::skipped('conversation_analyzer', 'first_message');
        $restored = ComponentTrace::fromArray($original->toArray());

        $this->assertSame('skipped', $restored->status);
        $this->assertSame('first_message', $restored->skipReason);
    }
}
