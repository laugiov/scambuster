<?php

declare(strict_types=1);

namespace App\Tests\Unit\Application\LLM;

use App\Application\Communication\MessageAnonymizer;
use App\Application\LLM\ContextualEnricher;
use App\Application\LLM\ContextualEnrichmentRequest;
use App\Application\LLM\Port\LLMClientInterface;
use App\Application\LLM\Prompt\PromptCatalog;
use App\Application\LLM\Prompt\PromptProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

/**
 * Regression lock for re-pointing ContextualEnricher::buildPrompt onto PromptProvider.
 *
 * The refactor MUST be behaviour-preserving: with no override file, buildPrompt must
 * produce byte-identical output to the prior inline `str_replace(fallbackTemplate)`.
 * It also proves the new capability: a valid override file is used, and an override
 * that drops a required placeholder safely falls back to the shipped default.
 */
final class ContextualEnricherProviderRegressionTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/scambuster_enricher_' . bin2hex(random_bytes(6));
        mkdir($this->dir, 0777, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/*') ?: [] as $path) {
            @unlink($path);
        }
        @rmdir($this->dir);
    }

    private function makeEnricher(string $promptDir): ContextualEnricher
    {
        return new ContextualEnricher(
            $this->createMock(LLMClientInterface::class),
            new MessageAnonymizer(),
            $this->createMock(EventDispatcherInterface::class),
            new NullLogger(),
            new PromptProvider($promptDir, new NullLogger()),
        );
    }

    private function request(): ContextualEnrichmentRequest
    {
        return new ContextualEnrichmentRequest(
            iocTypes: ['url', 'iban'],
            scamType: 'PHISHING',
            personaCode: 'bank_customer',
            revelationTurn: 3,
            totalTurns: 5,
            revelationMessageText: 'Please pay via https://evil.com or send to FR7630006000011234567890189',
            stimulusMessageText: 'I would like to proceed. What are the payment details?',
            previousInboundText: 'Dear customer, your account needs verification.',
        );
    }

    /**
     * @return array<string, string>
     */
    private function expectedReplacements(): array
    {
        $anonymizer = new MessageAnonymizer();

        return [
            '{{SCAM_TYPE}}' => 'PHISHING',
            '{{PERSONA_CODE}}' => 'bank_customer',
            '{{REVELATION_TURN}}' => '3',
            '{{TOTAL_TURNS}}' => '5',
            '{{IOC_TYPES}}' => 'url, iban',
            '{{PREVIOUS_INBOUND}}' => $anonymizer->anonymize('Dear customer, your account needs verification.'),
            '{{STIMULUS_MESSAGE}}' => $anonymizer->anonymize('I would like to proceed. What are the payment details?'),
            '{{REVELATION_MESSAGE}}' => $anonymizer->anonymize('Please pay via https://evil.com or send to FR7630006000011234567890189'),
        ];
    }

    private function buildPrompt(ContextualEnricher $enricher, ContextualEnrichmentRequest $request): string
    {
        return (string) (new \ReflectionMethod($enricher, 'buildPrompt'))->invoke($enricher, $request);
    }

    private function fallbackTemplate(ContextualEnricher $enricher): string
    {
        return (string) (new \ReflectionMethod($enricher, 'fallbackPromptTemplate'))->invoke($enricher);
    }

    public function testPromptCatalogRequiredTokensMatchTheEnricherEnforcedTokens(): void
    {
        // Drift guard: the catalog (which the CLI/UI validate against) must list exactly
        // the tokens the enricher actually enforces via PromptProvider::resolve. The
        // enricher passes array_keys($replacements); expectedReplacements() mirrors that
        // map and is itself locked byte-identical to the enricher's output above, so this
        // transitively binds the catalog to the runtime contract.
        self::assertSame(
            array_keys($this->expectedReplacements()),
            PromptCatalog::requiredPlaceholders('contextual_enrichment'),
        );
    }

    public function testBuildPromptIsByteIdenticalToLegacyInlinePathWhenNoOverride(): void
    {
        $enricher = $this->makeEnricher($this->dir); // empty dir → inline default

        $actual = $this->buildPrompt($enricher, $this->request());

        $map = $this->expectedReplacements();
        $expected = str_replace(
            array_keys($map),
            array_values($map),
            $this->fallbackTemplate($enricher),
        );

        self::assertSame($expected, $actual, 'buildPrompt must be byte-identical to the prior inline str_replace path');
    }

    public function testValidOverrideFileIsUsedByEnricher(): void
    {
        // Override that keeps every required placeholder + a distinctive marker.
        $override = "CUSTOM-OVERRIDE scam={{SCAM_TYPE}} persona={{PERSONA_CODE}} "
            . "turn={{REVELATION_TURN}} total={{TOTAL_TURNS}} iocs={{IOC_TYPES}} "
            . "prev={{PREVIOUS_INBOUND}} stim={{STIMULUS_MESSAGE}} rev={{REVELATION_MESSAGE}}";
        file_put_contents($this->dir . '/contextual_enrichment.txt', $override);

        $actual = $this->buildPrompt($this->makeEnricher($this->dir), $this->request());

        self::assertStringContainsString('CUSTOM-OVERRIDE', $actual);
        self::assertStringContainsString('scam=PHISHING', $actual);
        self::assertStringContainsString('persona=bank_customer', $actual);
    }

    public function testOverrideMissingARequiredPlaceholderFallsBackToDefault(): void
    {
        // Missing {{SCAM_TYPE}} → PromptProvider rejects it, enricher uses the default.
        file_put_contents(
            $this->dir . '/contextual_enrichment.txt',
            'BROKEN OVERRIDE with no scam-type token, persona={{PERSONA_CODE}}',
        );
        $enricher = $this->makeEnricher($this->dir);

        $actual = $this->buildPrompt($enricher, $this->request());

        // Identical to the no-override (default) result, and free of the broken marker.
        $map = $this->expectedReplacements();
        $expected = str_replace(array_keys($map), array_values($map), $this->fallbackTemplate($enricher));

        self::assertSame($expected, $actual);
        self::assertStringNotContainsString('BROKEN OVERRIDE', $actual);
    }
}
