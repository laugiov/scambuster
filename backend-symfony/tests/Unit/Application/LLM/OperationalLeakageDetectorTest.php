<?php

declare(strict_types=1);

namespace App\Tests\Unit\Application\LLM;

use App\Application\LLM\OperationalLeakageDetector;
use App\Application\LLM\Port\LLMClientInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Spec 065d — Phase 4 — Tests for the second-LLM operational leakage
 * detector.
 *
 * The detector mocks LLMClientInterface (a simple interface) and a
 * stubbed PromptBuilder. It returns a LeakageDetectionResult value
 * object indicating whether the LLM judge identified an operational
 * information leak.
 *
 * Defensive: any exception (network, JSON parse, malformed body) must
 * fail OPEN — return leakDetected=false with a logged warning. The
 * regex layer (Phase 2 PolicyGuard extension) is the hard gate; the
 * LLM detector is the deep semantic check on top.
 */
final class OperationalLeakageDetectorTest extends TestCase
{
    /**
     * Convenience: build an LLM client stub returning the given JSON
     * response. The detector builds its own prompts inline so no
     * PromptBuilder dependency is needed in the test.
     */
    private function makeDetector(string $jsonResponse): OperationalLeakageDetector
    {
        $llm = $this->createMock(LLMClientInterface::class);
        $llm->method('chat')->willReturn($jsonResponse);

        return new OperationalLeakageDetector($llm, new NullLogger());
    }

    public function test_it_returns_no_leak_for_legitimate_text(): void
    {
        $detector = $this->makeDetector('{"leak":false,"reason":"no leak","matched_terms":[]}');
        $result = $detector->check('Hello, this is a normal reply.', 'generic_user');

        $this->assertFalse($result->leakDetected);
    }

    public function test_it_returns_leak_for_paraphrased_orchestrator_mention(): void
    {
        $detector = $this->makeDetector('{"leak":true,"reason":"mentions orchestrator","matched_terms":["orchestrator"]}');
        $result = $detector->check('Let me ask my orchestrator first.', 'generic_user');

        $this->assertTrue($result->leakDetected);
        $this->assertSame('mentions orchestrator', $result->reason);
        $this->assertContains('orchestrator', $result->signals);
    }

    public function test_it_returns_leak_for_paraphrased_platform_mention(): void
    {
        $detector = $this->makeDetector('{"leak":true,"reason":"self-references the platform","matched_terms":["the platform that runs me"]}');
        $result = $detector->check('The platform that runs me will check this.', 'generic_user');

        $this->assertTrue($result->leakDetected);
    }

    public function test_it_handles_json_parse_error_gracefully(): void
    {
        // Detector receives malformed JSON → must NOT throw, must
        // return leakDetected=false (fail-open, regex is the hard gate)
        $detector = $this->makeDetector('not valid json at all');
        $result = $detector->check('some text', 'generic_user');

        $this->assertFalse($result->leakDetected);
    }

    public function test_it_handles_llm_exception_as_no_leak_with_warning(): void
    {
        $llm = $this->createMock(LLMClientInterface::class);
        $llm->method('chat')->willThrowException(new \RuntimeException('LLM API timeout'));

        $detector = new OperationalLeakageDetector($llm, new NullLogger());
        $result = $detector->check('some text', 'generic_user');

        $this->assertFalse($result->leakDetected);
    }

    public function test_it_handles_empty_input(): void
    {
        $detector = $this->makeDetector('{"leak":false,"reason":"empty","matched_terms":[]}');
        $result = $detector->check('', 'generic_user');

        $this->assertFalse($result->leakDetected);
    }

    public function test_it_handles_markdown_wrapped_json_response(): void
    {
        // Some LLMs wrap their JSON in ```json ... ``` blocks
        $detector = $this->makeDetector("```json\n{\"leak\":true,\"reason\":\"n8n mention\",\"matched_terms\":[\"n8n\"]}\n```");
        $result = $detector->check('I run on n8n.', 'generic_user');

        $this->assertTrue($result->leakDetected);
    }

    public function test_it_handles_missing_leak_field_as_no_leak(): void
    {
        $detector = $this->makeDetector('{"reason":"missing leak field"}');
        $result = $detector->check('some text', 'generic_user');

        $this->assertFalse($result->leakDetected);
    }
}
