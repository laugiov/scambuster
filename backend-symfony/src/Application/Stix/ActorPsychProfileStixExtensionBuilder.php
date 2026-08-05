<?php

declare(strict_types=1);

namespace App\Application\Stix;

use App\Domain\ThreatActor\ThreatActorPsychProfile;

/**
 * Builds the `x_scambuster_actor_psych` custom STIX extension attached to a
 * clustered threat-actor SDO, exposing the actor's persisted psychological +
 * behavioural fingerprint to downstream CTI consumers (OpenCTI / MISP).
 */
final readonly class ActorPsychProfileStixExtensionBuilder
{
    private const SCHEMA_VERSION = '1.0';

    /**
     * @return array<string, mixed>
     */
    public function build(ThreatActorPsychProfile $profile): array
    {
        return [
            'schema_version'      => self::SCHEMA_VERSION,
            'dominant_lever'      => $profile->dominantLever->value,
            'secondary_levers'    => array_map(static fn ($l): string => $l->value, $profile->secondaryLevers),
            'behavioural_summary' => $profile->behaviouralSummary,
            'escalation_pattern'  => $profile->escalationPattern,
            'victim_targeting'    => $profile->victimTargeting,
            'dominant_stimulus'   => $profile->dominantStimulus,
            'avg_urgency'         => $profile->avgUrgency,
            'hesitation_events'   => $profile->hesitationEvents,
            'language_switches'   => $profile->languageSwitches,
            'conversation_count'  => $profile->conversationCount,
            'message_count'       => $profile->messageCount,
            'generated_by_model'  => $profile->generatedByModel,
            'prompt_version'      => $profile->promptVersion,
            'generated_at'        => $profile->generatedAt->format(\DateTimeInterface::ATOM),
        ];
    }
}
