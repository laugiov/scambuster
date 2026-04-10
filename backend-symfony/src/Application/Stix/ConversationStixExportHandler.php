<?php

declare(strict_types=1);

namespace App\Application\Stix;

use App\Application\Clustering\ClusterQueryService;
use App\Application\Communication\IocHandler;
use App\Domain\Communication\Conversation;
use Doctrine\ORM\EntityManagerInterface;

final class ConversationStixExportHandler
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly IocHandler $iocHandler,
        private readonly StixBundleBuilder $bundleBuilder,
        private readonly ?ThreatActorStixBuilder $threatActorBuilder = null,
        private readonly ?ClusterQueryService $clusterQueryService = null,
        private readonly ?ClusteredThreatActorStixBuilder $clusteredActorBuilder = null,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function export(string $convId, bool $includeThreatActor = true): array
    {
        $conversation = $this->em->getRepository(Conversation::class)->find($convId);

        if (!$conversation) {
            throw new \RuntimeException('Conversation not found');
        }

        // Get conversation IOCs (deduplicated)
        $observedIocs = $this->iocHandler->getConversationIocs($convId);

        if (empty($observedIocs)) {
            return $this->bundleBuilder->buildBundle(
                [],
                [],
                $conversation->getTlp(),
                'ScamBuster - ' . $convId,
            );
        }

        // Build IOC data array for the builder
        $iocs = [];

        foreach ($observedIocs as $observedIoc) {
            $context = $observedIoc->getContext();
            $indicatorId = $observedIoc->getIndicatorId();

            $iocs[] = [
                'indicator_id' => $indicatorId,
                'type' => is_string($context['type'] ?? null) ? $context['type'] : 'unknown',
                'value' => is_string($context['value'] ?? null) ? $context['value'] : '',
                'value_norm' => is_string($context['value_norm'] ?? null) ? $context['value_norm'] : '',
                'first_seen' => $observedIoc->getTsObserved()->format('Y-m-d H:i:s'),
                'last_seen' => $observedIoc->getTsObserved()->format('Y-m-d H:i:s'),
                'confidence' => $observedIoc->getConfidenceScore(),
                'extraction_method' => is_string($context['extraction_method'] ?? null) ? $context['extraction_method'] : (is_string($context['source'] ?? null) ? $context['source'] : 'unknown'),
                'score' => is_array($context['score'] ?? null) ? $context['score'] : [],
                'scam_type' => $conversation->getScamType()->getCode(),
            ];
        }

        // Spec 060 S1.1: indicator-to-indicator co-occurrence is no longer materialised as
        // related-to relationships. The conversation `report` object's object_refs already
        // conveys co-occurrence to OpenCTI without the O(n^2) graph noise.
        $scamType = $conversation->getScamType()->getCode();

        $bundle = $this->bundleBuilder->buildBundle(
            $iocs,
            [],
            $conversation->getTlp(),
            "ScamBuster - {$scamType} conversation {$convId}",
            "IOCs extracted from {$scamType} scam conversation tracked by ScamBuster honeypot",
        );

        // Enrich with threat-actor
        if ($includeThreatActor && $this->threatActorBuilder !== null) {
            $bundle = $this->enrichWithThreatActor($conversation, $bundle);
        }

        return $bundle;
    }

    /**
     * Add threat-actor, attack-pattern, and relationships to the STIX bundle.
     *
     * Spec 060 Sprint 2: if the conversation belongs to a cluster, the
     * embedded threat-actor is the CLUSTER threat-actor (not a per-conversation
     * one) and `indicates` relationships target it. Otherwise a singleton
     * threat-actor is built with the new "Unattributed Scam Actor (Type)"
     * naming convention.
     *
     * @param array<string, mixed> $bundle
     *
     * @return array<string, mixed>
     */
    private function enrichWithThreatActor(Conversation $bundle_conversation, array $bundle): array
    {
        $convId = $bundle_conversation->getConvId();

        // Collect indicator IDs and report ID from the bundle (shared by both branches)
        $indicatorIds = [];
        $reportId = null;
        /** @var list<array<string, mixed>> $objects */
        $objects = \is_array($bundle['objects'] ?? null) ? $bundle['objects'] : [];

        foreach ($objects as $obj) {
            if (($obj['type'] ?? '') === 'indicator' && \is_string($obj['id'] ?? null)) {
                $indicatorIds[] = $obj['id'];
            }

            if (($obj['type'] ?? '') === 'report' && \is_string($obj['id'] ?? null)) {
                $reportId = $obj['id'];
            }
        }

        // Spec 060 Sprint 2: cluster delegation
        $clusterId = $this->clusterQueryService?->getClusterIdForConversation($convId);

        if ($clusterId !== null && $this->clusteredActorBuilder !== null && $this->clusterQueryService !== null) {
            $clusterData = $this->clusterQueryService->getStixExportData($clusterId);

            if ($clusterData !== null) {
                $clusterObjects = $this->clusteredActorBuilder->buildThreatActorObjects($clusterData, $indicatorIds);
                $threatActor = $clusterObjects['threat_actor'];
                $attackPatterns = $clusterObjects['attack_patterns'];
                $relationships = $clusterObjects['relationships'];

                return $this->mergeActorIntoBundle($bundle, $objects, $reportId, $threatActor, $attackPatterns, $relationships);
            }
        }

        // Fallback: singleton threat-actor (conversation not clustered or cluster lookup failed)
        return $this->buildAndMergeSingletonActor($bundle_conversation, $bundle, $objects, $reportId, $indicatorIds);
    }

    /**
     * Build a singleton threat-actor for an unclustered conversation and merge
     * it into the bundle.
     *
     * @param array<string, mixed>       $bundle
     * @param list<array<string, mixed>> $objects
     * @param list<string>               $indicatorIds
     *
     * @return array<string, mixed>
     */
    private function buildAndMergeSingletonActor(
        Conversation $conversation,
        array $bundle,
        array $objects,
        ?string $reportId,
        array $indicatorIds,
    ): array {
        if ($this->threatActorBuilder === null) {
            return $bundle;
        }

        $conn = $this->em->getConnection();
        $convId = $conversation->getConvId();
        $scamType = $conversation->getScamType();
        $attckTechnique = $scamType->getAttckTechnique();

        // Build description from IOC context excerpts
        $excerpts = $conn->fetchAllAssociative(
            'SELECT DISTINCT ic.context_excerpt'
            . ' FROM ioc_context ic'
            . ' JOIN observed_ioc oi ON ic.obs_id = oi.obs_id'
            . ' JOIN message m ON oi.msg_id = m.msg_id'
            . ' WHERE m.conv_id = :convId'
            . ' AND ic.enrichment_status = \'enriched\''
            . ' AND ic.context_excerpt IS NOT NULL'
            . ' LIMIT 1',
            ['convId' => $convId],
        );

        $description = sprintf('Criminal actor operating %s scam.', strtolower($scamType->getCode()));

        if (!empty($excerpts)) {
            $excerptTexts = array_unique(array_filter(array_map(
                fn (array $r) => \is_string($r['context_excerpt']) ? trim($r['context_excerpt']) : '',
                $excerpts,
            )));
            $description .= ' ' . implode(' ', $excerptTexts);
        }

        // Count IOC types
        $iocTypesRow = $conn->fetchAllAssociative(
            'SELECT DISTINCT i.type FROM observed_ioc oi'
            . ' JOIN indicator i ON oi.indicator_id = i.indicator_id'
            . ' JOIN message m ON oi.msg_id = m.msg_id'
            . ' WHERE m.conv_id = :convId',
            ['convId' => $convId],
        );
        $uniqueIocTypeCount = \count($iocTypesRow);

        // Conversation metrics
        $engagementSec = $conversation->getEngagementDurationSec();
        $turns = $conversation->getTurnsCount();

        if ($engagementSec === 0) {
            $diff = $conversation->getTsLast()->getTimestamp() - $conversation->getTsFirst()->getTimestamp();
            $engagementSec = max($diff, 0);
        }

        if ($turns === 0) {
            $msgCount = $conn->fetchOne(
                'SELECT COUNT(*) FROM message WHERE conv_id = :convId',
                ['convId' => $convId],
            );
            $turns = \is_numeric($msgCount) ? (int) ceil((int) $msgCount / 2) : 0;
        }

        $engagementHours = $engagementSec / 3600.0;

        $metrics = [
            'conversation_count' => 1,
            'avg_engagement_hours' => $engagementHours,
            'avg_turns' => (float) $turns,
            'unique_ioc_type_count' => $uniqueIocTypeCount,
            'has_injection_attempts' => false,
        ];

        $persona = $conversation->getPersona();
        $personaCode = $persona !== null ? $persona->getPersonaCode() : 'generic_user';

        $campaignData = [
            'campaign_id' => $convId,
            'scam_type' => $scamType->getCode(),
            'first_seen' => $conversation->getTsFirst()->format('Y-m-d H:i:s'),
            'last_seen' => $conversation->getTsLast()->format('Y-m-d H:i:s'),
            'profile_yaml' => null,
            'tlp' => $conversation->getTlp(),
        ];

        $actorProfile = [
            'style_dna' => [
                'persona_used' => $personaCode,
                'scam_type' => $scamType->getCode(),
                'engagement_turns' => $turns,
            ],
            'infra_dna' => [
                'ioc_type_count' => $uniqueIocTypeCount,
                'engagement_hours' => round($engagementHours, 2),
            ],
        ];

        // Spec 060 Sprint 2: use buildSingleton() (new naming convention) instead of buildThreatActor()
        $threatActor = $this->threatActorBuilder->buildSingleton($campaignData, $actorProfile, $metrics);
        $threatActor['description'] = mb_substr($description, 0, 400);

        $attackPatterns = $this->threatActorBuilder->buildAttackPatterns($attckTechnique);
        $attackPatternIds = array_map(fn (array $ap) => \is_string($ap['id']) ? $ap['id'] : '', $attackPatterns);

        $relationships = $this->threatActorBuilder->buildActorRelationships(
            \is_string($threatActor['id']) ? $threatActor['id'] : '',
            $indicatorIds,
            $attackPatternIds,
        );

        return $this->mergeActorIntoBundle($bundle, $objects, $reportId, $threatActor, $attackPatterns, $relationships);
    }

    /**
     * Append the threat-actor, attack-patterns, and relationships into the
     * bundle and update the report's object_refs.
     *
     * @param array<string, mixed>       $bundle
     * @param list<array<string, mixed>> $objects
     * @param array<string, mixed>       $threatActor
     * @param list<array<string, mixed>> $attackPatterns
     * @param list<array<string, mixed>> $relationships
     *
     * @return array<string, mixed>
     */
    private function mergeActorIntoBundle(
        array $bundle,
        array $objects,
        ?string $reportId,
        array $threatActor,
        array $attackPatterns,
        array $relationships,
    ): array {
        // Update report.object_refs with the threat-actor + attack-patterns
        if ($reportId !== null) {
            foreach ($objects as &$obj) {
                if (($obj['type'] ?? '') === 'report' && ($obj['id'] ?? '') === $reportId) {
                    /** @var list<string> $refs */
                    $refs = \is_array($obj['object_refs'] ?? null) ? $obj['object_refs'] : [];

                    if (\is_string($threatActor['id'] ?? null)) {
                        $refs[] = $threatActor['id'];
                    }

                    foreach ($attackPatterns as $ap) {
                        if (\is_string($ap['id'] ?? null)) {
                            $refs[] = $ap['id'];
                        }
                    }
                    $obj['object_refs'] = $refs;

                    break;
                }
            }
            unset($obj);
        }

        $objects[] = $threatActor;

        foreach ($attackPatterns as $ap) {
            $objects[] = $ap;
        }

        foreach ($relationships as $rel) {
            $objects[] = $rel;
        }

        $bundle['objects'] = $objects;

        return $bundle;
    }
}
