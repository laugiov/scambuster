<?php

declare(strict_types=1);

namespace App\Application\Guard;

/**
 * Whether the prompt canary can produce a real verdict in THIS deployment. The canary drives the
 * real reply pipeline over a scenario set, so it needs a live model provider with usable
 * credentials; without one (e.g. the public demo, or a fresh install that never set a key) a
 * validation job can only hang or fail. The admin UI and the request endpoint read this to avoid
 * offering / accepting an action that cannot complete.
 *
 * The signal keys off LLM_PROVIDER — the same var {@see \App\Infrastructure\LLM\LLMProviderCompilerPass}
 * swaps the client on — because the four supported providers carry credentials differently:
 *   - mock      -> never (static responses, no real model; this is the demo)
 *   - ollama    -> always (local model, no API key required)
 *   - anthropic -> needs ANTHROPIC_API_KEY
 *   - openai (the default; also the fallback for an unknown/empty provider) -> needs LLM_API_KEY
 *
 * It is a necessary, not sufficient, signal: it cannot see whether the canary-worker process is
 * actually running. It deliberately covers the credential/provider misconfigurations rather than
 * pretending to certify the whole pipeline.
 */
final readonly class CanaryAvailability
{
    /**
     * Case-insensitive substrings that mark a present-but-unusable key: the shipped provider
     * samples (`sk-your-api-key-here`, `sk-proj-your-key-here`, `sk-ant-your-key-here`) and the
     * demo sentinel (`not-needed-in-demo-mode`). Matched as substrings, not prefixes, so the
     * `sk-proj-…`/`sk-ant-…`-prefixed samples are caught too; no real key contains these tokens.
     */
    private const PLACEHOLDER_MARKERS = ['your-api-key', 'your-key-here', 'not-needed', 'changeme'];

    public function __construct(
        private string $provider,
        private string $openAiApiKey,
        private string $anthropicApiKey,
    ) {
    }

    public function isConfigured(): bool
    {
        return match (strtolower(trim($this->provider))) {
            'mock' => false,
            'ollama' => true,
            'anthropic' => $this->hasUsableKey($this->anthropicApiKey),
            default => $this->hasUsableKey($this->openAiApiKey),
        };
    }

    private function hasUsableKey(string $key): bool
    {
        $key = trim($key);

        if ($key === '') {
            return false;
        }

        $lower = strtolower($key);

        foreach (self::PLACEHOLDER_MARKERS as $marker) {
            if (str_contains($lower, $marker)) {
                return false;
            }
        }

        return true;
    }
}
