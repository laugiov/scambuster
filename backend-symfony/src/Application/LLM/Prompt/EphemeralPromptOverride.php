<?php

declare(strict_types=1);

namespace App\Application\LLM\Prompt;

/**
 * A process-local, single-entry override holder used to validate an UNSAVED candidate prompt
 * body (the GUARD "validate this prompt" flow) without persisting it. It implements
 * {@see PromptOverrideSource} so it can sit at the HEAD of the resolution chain and win over
 * the saved DB/file override for one key, for the duration of one validation run.
 *
 * It is deliberately empty in every normal process — only the isolated canary worker calls
 * set()/clear() around a single job — so live reply generation is never affected by it.
 * Stateful (mutable) by design and wired as a shared service, so the worker that sets it and
 * the resolver that reads it see the same instance within the one worker process.
 */
final class EphemeralPromptOverride implements PromptOverrideSource
{
    private ?string $key = null;
    private ?string $body = null;

    /**
     * Run $fn with the candidate active for $key, guaranteeing the holder is cleared afterwards
     * — even if $fn throws. This is the ONLY safe way to inject a candidate: it makes isolation
     * a property of the seam rather than something the caller must remember, so an exception
     * mid-run can never leave a resident candidate to leak into a later reply.
     *
     * @template T
     *
     * @param callable(): T $fn
     *
     * @return T
     */
    public function withCandidate(string $key, string $body, callable $fn): mixed
    {
        $this->set($key, $body);

        try {
            return $fn();
        } finally {
            $this->clear();
        }
    }

    public function set(string $key, string $body): void
    {
        $this->key = $key;
        $this->body = $body;
    }

    public function clear(): void
    {
        $this->key = null;
        $this->body = null;
    }

    public function get(string $key): ?string
    {
        // Treat an empty body as absent, matching PromptProvider's "empty == no override"
        // contract, so a blank candidate never short-circuits the chain to the shipped default.
        return $this->key === $key && $this->body !== null && $this->body !== '' ? $this->body : null;
    }
}
