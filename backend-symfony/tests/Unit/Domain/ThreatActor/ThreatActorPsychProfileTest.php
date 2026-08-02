<?php

declare(strict_types=1);

namespace App\Tests\Unit\Domain\ThreatActor;

use App\Domain\ThreatActor\CialdiniLever;
use App\Domain\ThreatActor\ThreatActorPsychProfile;
use PHPUnit\Framework\TestCase;

final class ThreatActorPsychProfileTest extends TestCase
{
    private function profile(): ThreatActorPsychProfile
    {
        return new ThreatActorPsychProfile(
            clusterId: '11111111-1111-1111-1111-111111111111',
            dominantLever: CialdiniLever::Urgency,
            secondaryLevers: [CialdiniLever::Authority, CialdiniLever::Scarcity],
            behaviouralSummary: 'Escalates pressure over three turns.',
            escalationPattern: 'rapid',
            victimTargeting: 'Time-poor small-business owners.',
            dominantStimulus: 'fear',
            avgUrgency: 0.72,
            hesitationEvents: 4,
            languageSwitches: 2,
            conversationCount: 5,
            messageCount: 41,
            generatedByModel: 'gpt-4o-mini',
            promptVersion: 'v1',
            generatedAt: new \DateTimeImmutable('2026-07-06T10:00:00+00:00'),
        );
    }

    public function testToArrayExposesStableFieldNames(): void
    {
        $array = $this->profile()->toArray();

        self::assertSame('11111111-1111-1111-1111-111111111111', $array['cluster_id']);
        self::assertSame('Urgency', $array['dominant_lever']);
        self::assertSame(['Authority', 'Scarcity'], $array['secondary_levers']);
        self::assertSame('rapid', $array['escalation_pattern']);
        self::assertSame('fear', $array['dominant_stimulus']);
        self::assertSame(0.72, $array['avg_urgency']);
        self::assertSame(4, $array['hesitation_events']);
        self::assertSame(5, $array['conversation_count']);
    }

    public function testToArrayFormatsGeneratedAtAsAtom(): void
    {
        self::assertSame('2026-07-06T10:00:00+00:00', $this->profile()->toArray()['generated_at']);
    }

    public function testSecondaryLeversAreSerialisedToTheirLabels(): void
    {
        $array = $this->profile()->toArray();

        self::assertContainsOnly('string', $array['secondary_levers']);
    }

    public function testDominantStimulusMayBeNull(): void
    {
        $profile = new ThreatActorPsychProfile(
            clusterId: 'c',
            dominantLever: CialdiniLever::None,
            secondaryLevers: [],
            behaviouralSummary: '',
            escalationPattern: 'stable',
            victimTargeting: '',
            dominantStimulus: null,
            avgUrgency: 0.0,
            hesitationEvents: 0,
            languageSwitches: 0,
            conversationCount: 1,
            messageCount: 1,
            generatedByModel: 'm',
            promptVersion: 'v1',
            generatedAt: new \DateTimeImmutable('2026-07-06T10:00:00+00:00'),
        );

        self::assertNull($profile->toArray()['dominant_stimulus']);
        self::assertSame([], $profile->toArray()['secondary_levers']);
    }
}
