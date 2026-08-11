<?php

declare(strict_types=1);

namespace App\Tests\Unit\Application\LLM;

use PHPUnit\Framework\TestCase;

/**
 * Sovereignty guard: no code path may force an OpenAI-specific model or endpoint
 * into an LLM call, otherwise LLM_PROVIDER=ollama cannot yield a local
 * deployment. Static guard over src/ — a permanent regression fence.
 *
 * Out of scope for this slice (tracked separately): EmbeddingService (G-05) and
 * CostEstimator's model→price lookup table (legitimate pricing data).
 */
final class LlmSovereigntyGuardTest extends TestCase
{
    private const SRC = __DIR__ . '/../../../../src';

    /**
     * @return list<string> absolute paths of every .php file under src/
     */
    private function phpFiles(string $dir): array
    {
        $out = [];
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS));

        foreach ($it as $file) {
            if ($file instanceof \SplFileInfo && $file->getExtension() === 'php') {
                $out[] = $file->getPathname();
            }
        }

        return $out;
    }

    public function testNoHardcodedModelInChatOptions(): void
    {
        $offenders = [];

        foreach ($this->phpFiles(self::SRC) as $path) {
            // Provider clients legitimately name their own default model; the
            // pricing table maps model→price. Neither selects a model for a call.
            if (str_contains($path, 'src/Infrastructure/LLM/Provider/') || str_ends_with($path, 'CostEstimator.php')) {
                continue;
            }

            $src = (string) file_get_contents($path);

            // A model literal placed into an options/log array reaches the
            // provider verbatim — OllamaClient forwards it and fails.
            if (preg_match('/[\'"]model[\'"]\s*=>\s*[\'"]gpt-/', $src)) {
                $offenders[] = basename($path) . " (\"'model' => 'gpt-...\")";
            }

            // Const-backed model overrides funnel the same literal in.
            if (preg_match('/const\s+\w*MODEL\w*\s*=\s*[\'"]gpt-/', $src)) {
                $offenders[] = basename($path) . ' (const *MODEL = gpt-...)';
            }
        }

        self::assertSame(
            [],
            $offenders,
            "OpenAI model literals must not reach an LLM call; use %llm.model% / %llm.model_strong%:\n" . implode("\n", $offenders),
        );
    }

    public function testNoHardcodedOpenAiEndpointOutsideDeferredEmbeddings(): void
    {
        $offenders = [];

        foreach ($this->phpFiles(self::SRC) as $path) {
            if (str_ends_with($path, 'EmbeddingService.php')) {
                continue; // G-05, deferred slice
            }

            if (str_contains((string) file_get_contents($path), 'api.openai.com')) {
                $offenders[] = basename($path);
            }
        }

        self::assertSame(
            [],
            $offenders,
            "Hardcoded api.openai.com endpoint; derive from %llm.api_url%:\n" . implode("\n", $offenders),
        );
    }
}
