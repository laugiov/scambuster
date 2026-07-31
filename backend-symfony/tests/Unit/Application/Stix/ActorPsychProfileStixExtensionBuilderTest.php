<?php

declare(strict_types=1);

namespace App\Tests\Unit\Application\Stix;

use App\Application\Stix\ActorPsychProfileStixExtensionBuilder;
use App\Domain\ThreatActor\CialdiniLever;
use App\Domain\ThreatActor\ThreatActorPsychProfile;
use PHPUnit\Framework\TestCase;

final class ActorPsychProfileStixExtensionBuilderTest extends TestCase
{
    private function profile(): ThreatActorPsychProfile
    {
        return new ThreatActorPsychProfile(
            clusterId: 'c-1',
            dominantLever: CialdiniLever::Urgency,
            secondaryLevers: [CialdiniLever::Authority, CialdiniLever::Scarcity],
            behaviouralSummary: 'Escalates deadlines.',
            escalationPattern: 'rapid',
            victimTargeting: 'Time-poor holders.',
            dominantStimulus: 'fear',
            avgUrgency: 0.72,
            hesitationEvents: 2,
            languageSwitches: 1,
            conversationCount: 3,
            messageCount: 20,
            generatedByModel: 'gpt-4o-mini',
            promptVersion: 'v1',
            generatedAt: new \DateTimeImmutable('2026-07-06T10:00:00+00:00'),
        );
    }

    public function testBuildProducesTheExtensionShape(): void
    {
        $ext = (new ActorPsychProfileStixExtensionBuilder())->build($this->profile());

        self::assertSame('1.0', $ext['schema_version']);
        self::assertSame('Urgency', $ext['dominant_lever']);
        self::assertSame(['Authority', 'Scarcity'], $ext['secondary_levers']);
        self::assertSame('rapid', $ext['escalation_pattern']);
        self::assertSame('fear', $ext['dominant_stimulus']);
        self::assertSame(0.72, $ext['avg_urgency']);
        self::assertSame(2, $ext['hesitation_events']);
        self::assertSame('2026-07-06T10:00:00+00:00', $ext['generated_at']);
        self::assertSame('gpt-4o-mini', $ext['generated_by_model']);
    }

    public function testSecondaryLeversAreStringLabels(): void
    {
        $ext = (new ActorPsychProfileStixExtensionBuilder())->build($this->profile());

        self::assertContainsOnly('string', $ext['secondary_levers']);
    }
}
