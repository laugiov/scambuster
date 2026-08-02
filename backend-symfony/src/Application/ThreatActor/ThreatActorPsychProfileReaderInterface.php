<?php

declare(strict_types=1);

namespace App\Application\ThreatActor;

use App\Domain\ThreatActor\ThreatActorPsychProfile;

/**
 * Read port for the persisted per-cluster threat-actor psychological profile.
 * Consumed by the API controller and the STIX export.
 */
interface ThreatActorPsychProfileReaderInterface
{
    public function getByClusterId(string $clusterId): ?ThreatActorPsychProfile;
}
