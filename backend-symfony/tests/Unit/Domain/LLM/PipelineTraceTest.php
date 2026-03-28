<?php

declare(strict_types=1);

namespace App\Tests\Unit\Domain\LLM;

use App\Domain\LLM\ComponentTrace;
use App\Domain\LLM\PipelineTrace;
use PHPUnit\Framework\TestCase;

final class PipelineTraceTest extends TestCase
{
    public function test_add_component_and_total_duration(): void
    {
        $trace = new PipelineTrace('conv-1', 'elderly_person', 'PHISHING');

        $trace->addComponent(ComponentTrace::ran('language_detector', 2.0));
        $trace->addComponent(ComponentTrace::ran('context_analyzer', 1.5));
        $trace->addComponent(ComponentTrace::ran('policy_guard', 5.0));

        $this->assertSame(8.5, $trace->getTotalDurationMs());
        $this->assertCount(3, $trace->getComponents());
    }

    public function test_add_component_accumulates_cost(): void
    {
        $trace = new PipelineTrace('conv-1', 'elderly_person', 'PHISHING');

        $trace->addComponent(ComponentTrace::ran('policy_guard', 5.0, [], 0.001));
        $trace->addComponent(ComponentTrace::ran('reply_validator', 5000.0, [], 0.02));

        $this->assertSame(0.021, round($trace->totalCost, 3));
    }

    public function test_get_component_by_name(): void
    {
        $trace = new PipelineTrace('conv-1', 'p1', 'PHISHING');
        $trace->addComponent(ComponentTrace::ran('policy_guard', 5.0, ['approved' => true]));

        $found = $trace->getComponentByName('policy_guard');
        $this->assertNotNull($found);
        $this->assertTrue($found->output['approved']);

        $this->assertNull($trace->getComponentByName('nonexistent'));
    }

    public function test_get_missing_components(): void
    {
        $trace = new PipelineTrace('conv-1', 'p1', 'PHISHING');
        $trace->addComponent(ComponentTrace::ran('language_detector', 1.0));
        $trace->addComponent(ComponentTrace::ran('context_analyzer', 1.0));

        $missing = $trace->getMissingComponents();

        $this->assertContains('policy_guard', $missing);
        $this->assertContains('reply_validator', $missing);
        $this->assertContains('conversation_analyzer', $missing);
        $this->assertNotContains('language_detector', $missing);
    }

    public function test_has_alerts_on_error(): void
    {
        $trace = $this->buildCompleteTrace();

        // Complete trace without errors — no alerts
        $this->assertFalse($trace->hasAlerts());

        // Add an error component
        $traceWithError = $this->buildCompleteTrace();
        $traceWithError->addComponent(ComponentTrace::error('extra_check', 'failed'));
        $this->assertTrue($traceWithError->hasAlerts());
    }

    public function test_has_alerts_on_missing_component(): void
    {
        $trace = new PipelineTrace('conv-1', 'p1', 'PHISHING');
        $trace->addComponent(ComponentTrace::ran('language_detector', 1.0));
        // Missing most expected components

        $this->assertTrue($trace->hasAlerts());
    }

    public function test_to_array_contains_all_fields(): void
    {
        $trace = $this->buildCompleteTrace();
        $trace->approved = true;
        $trace->attempts = 1;
        $array = $trace->toArray();

        $this->assertSame('conv-1', $array['conversation_id']);
        $this->assertSame('elderly_person', $array['persona']);
        $this->assertSame('PHISHING', $array['scam_type']);
        $this->assertSame('en', $array['detected_language']);
        $this->assertTrue($array['approved']);
        $this->assertSame(1, $array['attempts']);
        $this->assertGreaterThan(0, $array['total_duration_ms']);
        $this->assertArrayHasKey('components', $array);
        $this->assertArrayHasKey('created_at', $array);
        $this->assertArrayHasKey('has_alerts', $array);
    }

    public function test_to_summary_is_compact(): void
    {
        $trace = $this->buildCompleteTrace();
        $summary = $trace->toSummary();

        $this->assertArrayNotHasKey('components', $summary);
        $this->assertArrayHasKey('component_count', $summary);
        $this->assertArrayHasKey('has_alerts', $summary);
    }

    public function test_roundtrip(): void
    {
        $original = $this->buildCompleteTrace();
        $original->approved = true;
        $original->attempts = 2;

        $restored = PipelineTrace::fromArray($original->toArray());

        $this->assertSame($original->conversationId, $restored->conversationId);
        $this->assertSame($original->persona, $restored->persona);
        $this->assertSame($original->attempts, $restored->attempts);
        $this->assertTrue($restored->approved);
        $this->assertCount(count($original->getComponents()), $restored->getComponents());
    }

    public function test_skipped_component_not_flagged_as_missing(): void
    {
        $trace = $this->buildCompleteTrace();
        $trace->addComponent(ComponentTrace::skipped('conversation_analyzer', 'message_count < 2'));

        $missing = $trace->getMissingComponents();
        $this->assertNotContains('conversation_analyzer', $missing);
    }

    private function buildCompleteTrace(): PipelineTrace
    {
        $trace = new PipelineTrace('conv-1', 'elderly_person', 'PHISHING', 'en');

        $trace->addComponent(ComponentTrace::ran('language_detector', 0.5, ['detected' => 'en']));
        $trace->addComponent(ComponentTrace::ran('context_analyzer', 1.0, ['stage' => 'follow_up']));
        $trace->addComponent(ComponentTrace::ran('reciprocity_manager', 0.3, ['should_give' => false]));
        $trace->addComponent(ComponentTrace::ran('prompt_builder', 2.0, ['system_len' => 120]));
        $trace->addComponent(ComponentTrace::ran('policy_guard', 1.5, ['approved' => true]));
        $trace->addComponent(ComponentTrace::ran('reply_validator', 5000.0, ['naturalness' => 4], 0.001));
        $trace->addComponent(ComponentTrace::ran('ioc_scorer', 0.2, ['score' => 65]));

        return $trace;
    }
}
