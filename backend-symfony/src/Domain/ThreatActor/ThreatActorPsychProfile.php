<?php

declare(strict_types=1);

namespace App\Domain\ThreatActor;

/**
 * A durable psychological + behavioural fingerprint of a threat actor (an IOC
 * cluster), aggregated across all of the actor's conversations.
 *
 * Combines an LLM reading of the actor's manipulation style (the dominant
 * {@see CialdiniLever} + narrative) with the deterministic behavioural signals
 * already persisted in `ioc_context` (urgency, hesitation, language switching).
 * Generated offline per cluster — it never touches reply generation.
 */
final readonly class ThreatActorPsychProfile
{
    /**
     * @param list<CialdiniLever> $secondaryLevers
     */
    public function __construct(
        public string $clusterId,
        public CialdiniLever $dominantLever,
        public array $secondaryLevers,
        public string $behaviouralSummary,
        public string $escalationPattern,
        public string $victimTargeting,
        public ?string $dominantStimulus,
        public float $avgUrgency,
        public int $hesitationEvents,
        public int $languageSwitches,
        public int $conversationCount,
        public int $messageCount,
        public string $generatedByModel,
        public string $promptVersion,
        public \DateTimeImmutable $generatedAt,
    ) {
    }

    /**
     * API/UI representation (stable field names consumed by the frontend + STIX).
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'cluster_id'          => $this->clusterId,
            'dominant_lever'      => $this->dominantLever->value,
            'secondary_levers'    => array_map(static fn (CialdiniLever $l): string => $l->value, $this->secondaryLevers),
            'behavioural_summary' => $this->behaviouralSummary,
            'escalation_pattern'  => $this->escalationPattern,
            'victim_targeting'    => $this->victimTargeting,
            'dominant_stimulus'   => $this->dominantStimulus,
            'avg_urgency'         => $this->avgUrgency,
            'hesitation_events'   => $this->hesitationEvents,
            'language_switches'   => $this->languageSwitches,
            'conversation_count'  => $this->conversationCount,
            'message_count'       => $this->messageCount,
            'generated_by_model'  => $this->generatedByModel,
            'prompt_version'      => $this->promptVersion,
            'generated_at'        => $this->generatedAt->format(\DateTimeInterface::ATOM),
        ];
    }
}
