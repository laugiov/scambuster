<?php

declare(strict_types=1);

namespace App\Application\LLM\Prompt;

use Psr\Log\LoggerInterface;

/**
 * Resolves an LLM prompt template from an optional operator override file, falling
 * back to a caller-supplied inline default, then substitutes {{named}} placeholders.
 *
 * Override files live in a tracked config directory as `<key>.txt`. Resolution is
 * fail-safe: an absent, unreadable, empty, or invalid override degrades to the inline
 * default and NEVER throws, so a bad edit can only lose the operator's own change,
 * never break the reply pipeline. It is a text resolution/substitution layer only —
 * it never reads or affects the deterministic safety gate (PolicyGuard /
 * PaymentInstigationGuard stay fully in code).
 */
final readonly class PromptProvider
{
    public function __construct(
        private string $promptDir,
        private LoggerInterface $logger,
        private ?PromptOverrideSource $overrideSource = null,
    ) {
    }

    /**
     * Substitution uses `str_replace(array_keys, array_values)` — the exact semantics
     * of the prior inline callsites (single pass per token, left-to-right).
     *
     * @param array<string, string> $vars                 map of `{{TOKEN}}` => replacement value
     * @param list<string>          $requiredPlaceholders tokens a valid override MUST still contain;
     *                                                    a present override missing any of them falls
     *                                                    back to $inlineDefault
     */
    public function resolve(string $key, array $vars, string $inlineDefault, array $requiredPlaceholders = []): string
    {
        $template = $this->resolveTemplate($key, $inlineDefault, $requiredPlaceholders);

        return str_replace(array_keys($vars), array_values($vars), $template);
    }

    /**
     * Resolve the winning template: the first candidate that is present AND valid,
     * in precedence order — operator DB override, then on-disk file override, then the
     * shipped inline default. An invalid candidate (missing a required placeholder)
     * falls through to the next source.
     *
     * @param list<string> $required
     */
    private function resolveTemplate(string $key, string $inlineDefault, array $required): string
    {
        $candidates = [
            'db' => $this->loadDbOverride($key),
            'file' => $this->loadFileOverride($key),
        ];

        foreach ($candidates as $origin => $candidate) {
            if ($candidate === null) {
                continue;
            }

            $missing = $this->firstMissingPlaceholder($candidate, $required);

            if ($missing === null) {
                return $candidate;
            }

            $this->logger->warning('[PromptProvider] override missing required placeholder, falling through to next source', [
                'key' => $key,
                'origin' => $origin,
                'missing' => $missing,
            ]);
        }

        return $inlineDefault;
    }

    /**
     * The enabled DB override body for the key, or null. Fail-safe: any backend error
     * is treated as "no override" so resolution never depends on the store.
     */
    private function loadDbOverride(string $key): ?string
    {
        if ($this->overrideSource === null) {
            return null;
        }

        try {
            $body = $this->overrideSource->get($key);
        } catch (\Throwable) {
            return null;
        }

        return is_string($body) && $body !== '' ? $body : null;
    }

    /**
     * Load `<promptDir>/<key>.txt`, or null when the key is unsafe, or the file is
     * absent / empty / unreadable, or any error occurs. The key is restricted to
     * lowercase alphanumerics and underscores so it can never traverse outside the
     * prompt directory.
     */
    private function loadFileOverride(string $key): ?string
    {
        if (preg_match('/^[a-z0-9_]+$/', $key) !== 1) {
            return null;
        }

        try {
            $content = @file_get_contents($this->promptDir . '/' . $key . '.txt');
        } catch (\Throwable) {
            return null;
        }

        return is_string($content) && $content !== '' ? $content : null;
    }

    /**
     * @param list<string> $required
     *
     * @return string|null the first required placeholder absent from $template, or null if all present
     */
    private function firstMissingPlaceholder(string $template, array $required): ?string
    {
        foreach ($required as $placeholder) {
            if (!str_contains($template, $placeholder)) {
                return $placeholder;
            }
        }

        return null;
    }
}
