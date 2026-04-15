<?php

declare(strict_types=1);

namespace App\Tests\Unit\Application\LLM;

use App\Application\Communication\MessageAnonymizer;
use App\Application\LLM\ContextualEnricher;
use App\Application\LLM\Port\LLMClientInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

/**
 * Tests for F3 (urgency prompt refinement) and F5 (SHA256 role prompt).
 *
 * Verifies the fallback prompt template contains the required guidance text.
 */
final class ContextualEnricherPromptTest extends TestCase
{
    private string $promptText;

    protected function setUp(): void
    {
        $llmClient = $this->createMock(LLMClientInterface::class);
        $anonymizer = new MessageAnonymizer();
        $dispatcher = $this->createMock(EventDispatcherInterface::class);

        $enricher = new ContextualEnricher($llmClient, $anonymizer, $dispatcher, new NullLogger());

        $ref = new \ReflectionMethod($enricher, 'fallbackPromptTemplate');
        $this->promptText = $ref->invoke($enricher);
    }

    // --- F3: Urgency prompt refinement ---

    public function test_prompt_contains_do_not_default_to_075(): void
    {
        $this->assertStringContainsString(
            'Do NOT default to 0.75',
            $this->promptText,
            'Prompt must contain anti-default-0.75 instruction',
        );
    }

    public function test_prompt_contains_full_range(): void
    {
        $this->assertStringContainsString(
            'FULL range',
            $this->promptText,
            'Prompt must instruct LLM to use FULL range',
        );
    }

    public function test_prompt_contains_10_point_scale(): void
    {
        $this->assertStringContainsString('0.00-0.10', $this->promptText);
        $this->assertStringContainsString('0.95-1.00', $this->promptText);
    }

    // --- F5: SHA256 role prompt ---

    public function test_prompt_contains_hash_footer_guidance(): void
    {
        $this->assertStringContainsString(
            'footer',
            $this->promptText,
            'Prompt must mention footer context for hash role assignment',
        );
    }

    public function test_prompt_contains_hash_identity_document_default(): void
    {
        $this->assertStringContainsString(
            'IDENTITY_DOCUMENT',
            $this->promptText,
            'Prompt must mention IDENTITY_DOCUMENT as default hash role',
        );
    }

    public function test_prompt_contains_hash_malware_inline_guidance(): void
    {
        $this->assertStringContainsString(
            'MALWARE_DOWNLOAD_URL',
            $this->promptText,
            'Prompt must mention MALWARE_DOWNLOAD_URL for inline hashes',
        );
    }
}
