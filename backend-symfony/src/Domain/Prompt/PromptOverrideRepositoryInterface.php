<?php

declare(strict_types=1);

namespace App\Domain\Prompt;

interface PromptOverrideRepositoryInterface
{
    public function findByKey(string $promptKey): ?PromptOverride;

    /**
     * @return list<PromptOverride>
     */
    public function findAll(): array;

    /**
     * @return list<PromptOverride> the enabled overrides only
     */
    public function findAllEnabled(): array;

    public function save(PromptOverride $override): void;

    public function delete(PromptOverride $override): void;
}
