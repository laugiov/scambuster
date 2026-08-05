<?php

declare(strict_types=1);

namespace App\Infrastructure\Prompt;

use App\Application\LLM\Prompt\PromptOverrideSource;
use App\Domain\Prompt\PromptOverrideRepositoryInterface;
use Psr\Log\LoggerInterface;

/**
 * Database-backed {@see PromptOverrideSource}. Loads all ENABLED overrides once into an
 * in-memory map and serves every lookup from it, so the reply pipeline costs at most one
 * query regardless of how many prompts it resolves.
 *
 * Cache lifetime = the service instance = one HTTP request in this codebase (reply
 * generation is driven by request-scoped endpoints), so an operator edit is picked up on
 * the next request. If reply generation were ever moved to a long-running worker, this
 * would need per-message reset or a TTL to avoid serving a stale override.
 *
 * Fail-safe: any store error is swallowed and treated as "no overrides", so an
 * unavailable or broken database can never block generation — resolution falls through
 * to the on-disk file and the shipped default.
 */
final class CachedDbPromptOverrideSource implements PromptOverrideSource
{
    /** @var array<string, string>|null enabled override bodies keyed by promptKey */
    private ?array $cache = null;

    public function __construct(
        private readonly PromptOverrideRepositoryInterface $repository,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function get(string $key): ?string
    {
        return $this->loadCache()[$key] ?? null;
    }

    /**
     * @return array<string, string>
     */
    private function loadCache(): array
    {
        if ($this->cache !== null) {
            return $this->cache;
        }

        try {
            $map = [];

            foreach ($this->repository->findAllEnabled() as $override) {
                $map[$override->getPromptKey()] = $override->getBody();
            }

            return $this->cache = $map;
        } catch (\Throwable $e) {
            $this->logger->warning('[CachedDbPromptOverrideSource] could not load prompt overrides, treating as none', [
                'error' => $e->getMessage(),
            ]);

            return $this->cache = [];
        }
    }
}
