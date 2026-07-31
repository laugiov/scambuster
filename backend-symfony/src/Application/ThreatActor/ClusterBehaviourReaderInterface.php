<?php

declare(strict_types=1);

namespace App\Application\ThreatActor;

/**
 * Port for the per-cluster behavioural aggregate (from ioc_context), consumed by
 * the psych-profile generator. Implemented by the clustering query service.
 */
interface ClusterBehaviourReaderInterface
{
    /**
     * @return array{
     *     dominant_stimulus: string|null,
     *     dominant_stimulus_count: int,
     *     avg_urgency_score: float,
     *     dominant_revelation_turn: int|null,
     *     hesitation_count: int,
     *     language_switch_count: int,
     *     templated_excerpt_count: int,
     *     total_excerpt_variant_count: int,
     *     total_enriched_iocs: int
     * }|null
     */
    public function getBehavioralProfile(string $clusterId): ?array;
}
