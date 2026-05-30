<?php

declare(strict_types=1);

namespace App\Domain\Communication;

use Symfony\Component\Uid\Uuid;

interface ConversationRepositoryInterface
{
    public function findById(Uuid $convId): ?Conversation;

    /** @return array<Conversation> */
    public function findByStatus(string $status): array;

    /** @return array<Conversation> */
    public function findActive(): array;

    /** @return array<Conversation> */
    public function findStale(\DateTimeImmutable $threshold): array;

    /**
     * Spec 092 — count OPEN conversations grouped by persona for a given scam type.
     * Used by the bandit (UCB1 exploit branch) to deflate the exploration bonus
     * via the "effective N" denominator (closed + in-flight).
     *
     * Excludes soft-deleted convs. Personas with zero open convs are NOT in the
     * result (caller defaults to 0).
     *
     * @return array<string, int> persona_code => open conv count
     */
    public function countOpenByPersonaForScamType(ScamType $scamType): array;
}
