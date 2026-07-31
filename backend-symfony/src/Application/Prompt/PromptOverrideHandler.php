<?php

declare(strict_types=1);

namespace App\Application\Prompt;

use App\Application\Guard\CanaryAvailability;
use App\Application\LLM\Prompt\PromptCatalog;
use App\Application\Prompt\Exception\InvalidPromptOverrideException;
use App\Application\Prompt\Exception\UnknownPromptKeyException;
use App\Domain\Prompt\PromptOverride;
use App\Domain\Prompt\PromptOverrideRepositoryInterface;

/**
 * Application service behind the prompt-override admin API. Owns the business rules:
 * a key must be in the catalog, and an override body must keep every required
 * placeholder for its key (the same contract the runtime enforces). Persistence is
 * delegated to the repository; HTTP concerns (RBAC, audit, responses) stay in the
 * controllers.
 */
final readonly class PromptOverrideHandler
{
    public function __construct(
        private PromptOverrideRepositoryInterface $repository,
        private PromptBodyValidator $validator,
        private CanaryAvailability $canaryAvailability,
    ) {
    }

    /**
     * The full catalog merged with any stored override, one row per overridable key.
     *
     * @return list<array<string, mixed>>
     */
    public function list(): array
    {
        $byKey = [];

        foreach ($this->repository->findAll() as $override) {
            $byKey[$override->getPromptKey()] = $override;
        }

        $rows = [];

        foreach (PromptCatalog::all() as $key => $meta) {
            $rows[] = $this->row($key, $meta, $byKey[$key] ?? null);
        }

        return $rows;
    }

    /**
     * @return array<string, mixed>
     */
    public function get(string $key): array
    {
        if (!PromptCatalog::isKnown($key)) {
            throw new UnknownPromptKeyException($key);
        }

        return $this->row($key, PromptCatalog::all()[$key], $this->repository->findByKey($key));
    }

    /**
     * Create or update the override for a key. Validates the key and body first.
     *
     * @throws UnknownPromptKeyException      unknown key
     * @throws InvalidPromptOverrideException empty body or missing a required placeholder
     */
    public function upsert(string $key, string $body, bool $enabled, ?string $updatedBy): void
    {
        $this->validator->validate($key, $body);

        $existing = $this->repository->findByKey($key);

        if ($existing !== null) {
            $existing->update($body, $enabled, $updatedBy);
            $this->repository->save($existing);

            return;
        }

        $this->repository->save(new PromptOverride($key, $body, $enabled, $updatedBy));
    }

    /**
     * Remove the override for a key (reverting to the file/default). Idempotent.
     *
     * @return bool whether a row was actually removed
     */
    public function delete(string $key): bool
    {
        $existing = $this->repository->findByKey($key);

        if ($existing === null) {
            return false;
        }

        $this->repository->delete($existing);

        return true;
    }

    /**
     * @return list<string>
     */
    private function missingPlaceholders(string $key, string $body): array
    {
        $missing = [];

        foreach (PromptCatalog::requiredPlaceholders($key) as $placeholder) {
            if (!str_contains($body, $placeholder)) {
                $missing[] = $placeholder;
            }
        }

        return $missing;
    }

    /**
     * @param array{description: string, required: list<string>, default: string, canary_validatable: bool} $meta
     *
     * @return array<string, mixed>
     */
    private function row(string $key, array $meta, ?PromptOverride $override): array
    {
        $hasOverride = $override !== null;
        $missing = $hasOverride ? $this->missingPlaceholders($key, $override->getBody()) : [];
        $valid = $missing === [];

        return [
            'key' => $key,
            'description' => $meta['description'],
            // Whether the GUARD reply-canary can validate this prompt — the UI shows the
            // "Validate this prompt" action only when true, so it is never offered where it
            // would run but not actually exercise the prompt.
            'canary_validatable' => $meta['canary_validatable'],
            // Whether the canary can actually run in THIS deployment (an LLM is configured).
            // The UI offers "Validate" only when both this and canary_validatable are true, so a
            // deployment without a key (e.g. the demo) never shows an action that could only hang.
            'canary_available' => $this->canaryAvailability->isConfigured(),
            'required_placeholders' => $meta['required'],
            // The shipped default text (read-only reference the UI shows so an operator can
            // see what they are replacing). Always the default, never the override body.
            'default_body' => $meta['default'],
            'has_override' => $hasOverride,
            'enabled' => $hasOverride && $override->isEnabled(),
            'valid' => $valid,
            'missing_placeholders' => $missing,
            // Whether this override is the one the runtime will actually use.
            'active' => $hasOverride && $override->isEnabled() && $valid,
            'body' => $hasOverride ? $override->getBody() : null,
            'updated_at' => $hasOverride ? $override->getUpdatedAt()->format(\DATE_ATOM) : null,
            'updated_by' => $hasOverride ? $override->getUpdatedBy() : null,
        ];
    }
}
