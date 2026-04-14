<?php

declare(strict_types=1);

namespace App\Tests\Unit\Application\LLM;

use App\Application\Communication\PersonaManager;
use App\Application\LLM\ContextAnalyzer;
use App\Application\LLM\PromptBuilder;
use App\Application\LLM\ReciprocityManager;
use App\Application\LLM\VariationProvider;
use App\Domain\Communication\Persona;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Additional coverage for PromptBuilder private methods via public API.
 *
 * Targets uncovered lines: cleanBodyForLLM (414-416,419,440),
 * extractReadableText (455-456,460-461,464,467,469,473),
 * formatInstructions (311).
 */
class PromptBuilderCoverageTest extends TestCase
{
    private PromptBuilder $builder;
    private PersonaManager $personaManager;

    protected function setUp(): void
    {
        $this->personaManager = $this->createMock(PersonaManager::class);

        $persona = $this->createMock(Persona::class);
        $persona->method('getPersonaCode')->willReturn('generic_user');
        $persona->method('getPersonaLabel')->willReturn('Generic User');
        $persona->method('getPersonaTone')->willReturn('Neutral');
        $persona->method('getSystemPrompt')->willReturn(str_repeat('System prompt content. ', 10));
        $persona->method('isActive')->willReturn(true);

        $this->personaManager->method('findByCode')->willReturn($persona);

        $logger = new NullLogger();
        // Use real (final) instances
        $contextAnalyzer = new ContextAnalyzer($logger);
        $variationProvider = new VariationProvider();
        $reciprocityManager = new ReciprocityManager($logger);

        $this->builder = new PromptBuilder(
            $contextAnalyzer,
            $variationProvider,
            $reciprocityManager,
            $this->personaManager,
            $logger,
        );
    }

    public function testBuildWithLargeBodyTriggersCleanup(): void
    {
        // Body > 50KB triggers extractReadableText path
        $largeBody = str_repeat('Normal text. ', 5000); // ~65KB
        $context = $this->buildContext([
            [
                'direction' => 'in',
                'body_text' => $largeBody,
                'headers' => ['from' => 'scammer@evil.test'],
                'ts_msg' => '2026-01-01T00:00:00+00:00',
            ],
        ]);

        $result = $this->builder->buildGeneratorPrompts($context, 'generic_user');

        $this->assertIsArray($result);
        $this->assertArrayHasKey('user', $result);
        // Body should be truncated after cleaning
        $this->assertLessThan(15000, strlen($result['user']));
    }

    public function testBuildWithBase64ImageDataRemovesIt(): void
    {
        $bodyWithBase64 = 'Hello, here is my image: data:image/png;base64,' . str_repeat('ABCD', 50) . ' and more text.';
        $context = $this->buildContext([
            [
                'direction' => 'in',
                'body_text' => $bodyWithBase64,
                'headers' => ['from' => 'scammer@evil.test'],
                'ts_msg' => '2026-01-01T00:00:00+00:00',
            ],
        ]);

        $result = $this->builder->buildGeneratorPrompts($context, 'generic_user');

        $this->assertStringContainsString('[IMAGE REMOVED]', $result['user']);
    }

    public function testBuildWithLongBase64SequenceRemovesIt(): void
    {
        $bodyWithLongBase64 = 'Start ' . str_repeat('A', 150) . ' End';
        $context = $this->buildContext([
            [
                'direction' => 'in',
                'body_text' => $bodyWithLongBase64,
                'headers' => ['from' => 'scammer@evil.test'],
                'ts_msg' => '2026-01-01T00:00:00+00:00',
            ],
        ]);

        $result = $this->builder->buildGeneratorPrompts($context, 'generic_user');

        $this->assertStringContainsString('[BASE64 DATA REMOVED]', $result['user']);
    }

    public function testBuildWithMimeBoundaryRemovesIt(): void
    {
        $bodyWithMime = "Some text\n--boundary-1234567890abcdef0123\nContent-Type: text/plain\nMore text";
        $context = $this->buildContext([
            [
                'direction' => 'in',
                'body_text' => $bodyWithMime,
                'headers' => ['from' => 'scammer@evil.test'],
                'ts_msg' => '2026-01-01T00:00:00+00:00',
            ],
        ]);

        $result = $this->builder->buildGeneratorPrompts($context, 'generic_user');

        // MIME boundary and Content-Type should be removed
        $this->assertStringNotContainsString('boundary-1234567890abcdef0123', $result['user']);
    }

    public function testBuildWithVeryLargeTextPlainMimePart(): void
    {
        // Over 50KB body with a text/plain MIME part triggers extractReadableText
        $mimeBody = "Content-Type: text/plain; charset=utf-8\r\n\r\nThis is the plain text part.\r\n--boundary\r\n";
        $mimeBody .= str_repeat('X', 50000);

        $context = $this->buildContext([
            [
                'direction' => 'in',
                'body_text' => $mimeBody,
                'headers' => ['from' => 'scammer@evil.test'],
                'ts_msg' => '2026-01-01T00:00:00+00:00',
            ],
        ]);

        $result = $this->builder->buildGeneratorPrompts($context, 'generic_user');

        $this->assertStringContainsString('plain text part', $result['user']);
    }

    public function testBuildWithVeryLargeHtmlMimePart(): void
    {
        // Over 50KB body with only text/html part
        $htmlContent = '<p>This is <strong>HTML</strong> content.</p>';
        $mimeBody = "Content-Type: text/html; charset=utf-8\r\n\r\n{$htmlContent}\r\n--boundary\r\n";
        $mimeBody .= str_repeat('Y', 50000);

        $context = $this->buildContext([
            [
                'direction' => 'in',
                'body_text' => $mimeBody,
                'headers' => ['from' => 'scammer@evil.test'],
                'ts_msg' => '2026-01-01T00:00:00+00:00',
            ],
        ]);

        $result = $this->builder->buildGeneratorPrompts($context, 'generic_user');

        // HTML tags should be stripped and text preserved
        $this->assertStringContainsString('HTML', $result['user']);
    }

    public function testBuildWithVeryLargeBinaryMimeFallsBackToTruncation(): void
    {
        // Over 50KB body with no text/plain or text/html part
        $mimeBody = "Content-Type: application/octet-stream\r\n\r\n" . str_repeat('Z', 50001);

        $context = $this->buildContext([
            [
                'direction' => 'in',
                'body_text' => $mimeBody,
                'headers' => ['from' => 'scammer@evil.test'],
                'ts_msg' => '2026-01-01T00:00:00+00:00',
            ],
        ]);

        $result = $this->builder->buildGeneratorPrompts($context, 'generic_user');

        // Should fall back to truncated substring
        $this->assertStringContainsString('complex MIME content', $result['user']);
    }

    public function testBuildWithBodyExceeding10KAfterClean(): void
    {
        // Body that is large after cleaning but not MIME (so no MIME extraction)
        $body = str_repeat('word ', 2500); // ~12.5KB of clean text

        $context = $this->buildContext([
            [
                'direction' => 'in',
                'body_text' => $body,
                'headers' => ['from' => 'scammer@evil.test'],
                'ts_msg' => '2026-01-01T00:00:00+00:00',
            ],
        ]);

        $result = $this->builder->buildGeneratorPrompts($context, 'generic_user');

        // Should be truncated to 10KB with truncation notice
        $this->assertStringContainsString('message truncated', $result['user']);
    }

    public function testBuildWithEmptyMessages(): void
    {
        $context = $this->buildContext([]);

        $result = $this->builder->buildGeneratorPrompts($context, 'generic_user');

        $this->assertStringContainsString('first exchange', $result['user']);
    }

    public function testBuildWithSenderHistorySummary(): void
    {
        $context = $this->buildContext([
            [
                'direction' => 'in',
                'body_text' => 'Hello target',
                'headers' => ['from' => 'scammer@evil.test'],
                'ts_msg' => '2026-01-01T00:00:00+00:00',
            ],
        ]);
        $context['sender_history_summary'] = 'This sender has been seen in 3 prior conversations.';

        $result = $this->builder->buildGeneratorPrompts($context, 'generic_user');

        $this->assertStringContainsString('Prior exchanges', $result['user']);
        $this->assertStringContainsString('3 prior conversations', $result['user']);
    }

    /**
     * @param array<int, array<string, mixed>> $messages
     *
     * @return array<string, mixed>
     */
    private function buildContext(array $messages): array
    {
        return [
            'conv_id' => 'test-conv-1',
            'status' => 'open',
            'scam_type' => ['code' => 'PHISHING', 'label' => 'Phishing'],
            'persona' => 'generic_user',
            'cadence' => ['min_hours_between_replies' => 6],
            'last_messages' => $messages,
            'extracted_iocs' => [],
            'sender_history_summary' => null,
        ];
    }
}
