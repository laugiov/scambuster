<?php

declare(strict_types=1);

namespace App\Application\ThreatActor;

use App\Domain\ThreatActor\CialdiniLever;
use App\Domain\ThreatActor\ThreatActorPsychProfile;
use Doctrine\DBAL\Connection;

/**
 * Read-only query service for the threat-actor psychological profile.
 * Hydrates the persisted row back into the domain value object.
 */
final readonly class ThreatActorPsychProfileQueryService implements ThreatActorPsychProfileReaderInterface
{
    public function __construct(
        private Connection $conn,
    ) {
    }

    public function getByClusterId(string $clusterId): ?ThreatActorPsychProfile
    {
        $row = $this->conn->fetchAssociative(
            'SELECT cluster_id, dominant_lever, secondary_levers, behavioural_summary, escalation_pattern,
                    victim_targeting, dominant_stimulus, avg_urgency, hesitation_events, language_switches,
                    conversation_count, message_count, generated_at, generated_by_model, prompt_version
             FROM threat_actor_psych_profile
             WHERE cluster_id = :cid',
            ['cid' => $clusterId],
        );

        if ($row === false) {
            return null;
        }

        $dominant = \is_string($row['dominant_lever'] ?? null)
            ? (CialdiniLever::tryFromLabel($row['dominant_lever']) ?? CialdiniLever::None)
            : CialdiniLever::None;

        $generatedAt = \is_string($row['generated_at'] ?? null)
            ? (new \DateTimeImmutable($row['generated_at']))
            : new \DateTimeImmutable('@0');

        return new ThreatActorPsychProfile(
            clusterId: \is_string($row['cluster_id'] ?? null) ? $row['cluster_id'] : $clusterId,
            dominantLever: $dominant,
            secondaryLevers: $this->parseLevers($row['secondary_levers'] ?? null),
            behaviouralSummary: \is_string($row['behavioural_summary'] ?? null) ? $row['behavioural_summary'] : '',
            escalationPattern: \is_string($row['escalation_pattern'] ?? null) ? $row['escalation_pattern'] : 'unknown',
            victimTargeting: \is_string($row['victim_targeting'] ?? null) ? $row['victim_targeting'] : '',
            dominantStimulus: \is_string($row['dominant_stimulus'] ?? null) ? $row['dominant_stimulus'] : null,
            avgUrgency: \is_numeric($row['avg_urgency'] ?? null) ? (float) $row['avg_urgency'] : 0.0,
            hesitationEvents: \is_numeric($row['hesitation_events'] ?? null) ? (int) $row['hesitation_events'] : 0,
            languageSwitches: \is_numeric($row['language_switches'] ?? null) ? (int) $row['language_switches'] : 0,
            conversationCount: \is_numeric($row['conversation_count'] ?? null) ? (int) $row['conversation_count'] : 0,
            messageCount: \is_numeric($row['message_count'] ?? null) ? (int) $row['message_count'] : 0,
            generatedByModel: \is_string($row['generated_by_model'] ?? null) ? $row['generated_by_model'] : '',
            promptVersion: \is_string($row['prompt_version'] ?? null) ? $row['prompt_version'] : '',
            generatedAt: $generatedAt,
        );
    }

    /**
     * Parse a Postgres text[] literal ({Authority,Scarcity}) into levers.
     *
     * @return list<CialdiniLever>
     */
    private function parseLevers(mixed $raw): array
    {
        if (!\is_string($raw)) {
            return [];
        }

        $inner = trim($raw, '{}');

        if ($inner === '') {
            return [];
        }

        $levers = [];

        foreach (explode(',', $inner) as $label) {
            $lever = CialdiniLever::tryFromLabel(trim($label, '" '));

            if ($lever instanceof CialdiniLever) {
                $levers[] = $lever;
            }
        }

        return $levers;
    }
}
