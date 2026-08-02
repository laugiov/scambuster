<?php

declare(strict_types=1);

namespace App\Application\Stix;

use App\Domain\ThreatActor\ThreatActorPsychProfile;

/**
 * Projects the actor's psychological fingerprint onto standard STIX properties.
 *
 * The Cialdini profile is ScamBuster's most distinctive first-party output, but
 * shipping it only as `x_scambuster_actor_psych` means every consumer that does
 * not know the extension drops it — OpenCTI included. This builder turns the
 * profile into `description` prose and `labels`, which survive ingestion, so an
 * analyst sees the manipulation style on the threat-actor entity itself.
 *
 * The extension is still emitted next to these fields for consumers that do
 * understand it; this is a mirror, not a replacement.
 */
final class ActorPsychInteroperableFieldsBuilder
{
    /** Keeps a long LLM narrative from swamping the entity description. */
    private const MAX_SUMMARY = 600;

    /**
     * Prose describing the actor's manipulation style, appended to whatever
     * description the threat-actor SDO already carries.
     */
    public static function description(ThreatActorPsychProfile $profile): string
    {
        $sentences = [];

        $secondary = array_map(
            static fn (\App\Domain\ThreatActor\CialdiniLever $lever): string => $lever->value,
            $profile->secondaryLevers,
        );

        $sentences[] = $secondary === []
            ? \sprintf('Dominant influence lever: %s.', $profile->dominantLever->value)
            : \sprintf(
                'Dominant influence lever: %s (secondary: %s).',
                $profile->dominantLever->value,
                implode(', ', $secondary),
            );

        $summary = self::clean($profile->behaviouralSummary);

        if ($summary !== null) {
            $sentences[] = $summary;
        }

        $escalation = self::clean($profile->escalationPattern);

        if ($escalation !== null) {
            $sentences[] = 'Escalation pattern: ' . $escalation;
        }

        $targeting = self::clean($profile->victimTargeting);

        if ($targeting !== null) {
            $sentences[] = 'Victim targeting: ' . $targeting;
        }

        $sentences[] = \sprintf(
            'Behavioural signals across %d conversation(s) / %d message(s): average urgency %.2f, %d hesitation event(s), %d language switch(es).',
            $profile->conversationCount,
            $profile->messageCount,
            $profile->avgUrgency,
            $profile->hesitationEvents,
            $profile->languageSwitches,
        );

        $sentences[] = \sprintf(
            'Profile generated offline by %s (prompt %s) on %s.',
            $profile->generatedByModel,
            $profile->promptVersion,
            $profile->generatedAt->format('Y-m-d'),
        );

        return implode(' ', $sentences);
    }

    /**
     * Pivotable labels for the profile.
     *
     * @return list<string>
     */
    public static function labels(ThreatActorPsychProfile $profile): array
    {
        $labels = ['psych-lever:' . strtolower($profile->dominantLever->value)];

        foreach ($profile->secondaryLevers as $lever) {
            $labels[] = 'psych-lever-secondary:' . strtolower($lever->value);
        }

        if (\is_string($profile->dominantStimulus) && trim($profile->dominantStimulus) !== '') {
            $labels[] = 'dominant-stimulus:' . strtolower(trim($profile->dominantStimulus));
        }

        return array_values(array_unique($labels));
    }

    private static function clean(?string $value): ?string
    {
        if (!\is_string($value)) {
            return null;
        }

        $value = trim(preg_replace('/\s+/', ' ', $value) ?? $value);

        if ($value === '') {
            return null;
        }

        if (mb_strlen($value) > self::MAX_SUMMARY) {
            $value = mb_substr($value, 0, self::MAX_SUMMARY - 1) . '…';
        }

        return str_ends_with($value, '.') || str_ends_with($value, '…') ? $value : $value . '.';
    }
}
