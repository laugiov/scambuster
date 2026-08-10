<?php

declare(strict_types=1);

namespace App\Application\Stix;

use App\Application\Clustering\ClusterQueryService;
use App\Application\Communication\IocHandler;
use App\Application\ThreatActor\ThreatActorPsychProfileReaderInterface;
use App\Application\Ttp\TtpQueryService;
use App\Domain\Communication\Conversation;
use App\Domain\Communication\Policy\IocExportPolicy;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\ORM\EntityManagerInterface;

final readonly class ConversationStixExportHandler
{
    public function __construct(
        private EntityManagerInterface $em,
        private IocHandler $iocHandler,
        private StixBundleBuilder $bundleBuilder,
        private ?ThreatActorStixBuilder $threatActorBuilder = null,
        private ?ClusterQueryService $clusterQueryService = null,
        private ?ClusteredThreatActorStixBuilder $clusteredActorBuilder = null,
        private ?CognitiveMirrorNoteBuilder $cognitiveMirrorNoteBuilder = null,
        private ?ThreatActorPsychProfileReaderInterface $psychProfileReader = null,
        private ?ActorPsychProfileStixExtensionBuilder $psychProfileExtensionBuilder = null,
        private ?TtpQueryService $ttpQueryService = null,
        private ?TtpAttackPatternBuilder $ttpAttackPatternBuilder = null,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function export(string $convId, bool $includeThreatActor = true): array
    {
        $conversation = $this->em->getRepository(Conversation::class)->find($convId);

        if ($conversation === null) {
            throw new \RuntimeException('Conversation not found');
        }

        // Get conversation IOCs (deduplicated)
        $observedIocs = $this->iocHandler->getConversationIocs($convId);

        if ($observedIocs === []) {
            return $this->bundleBuilder->buildBundle(
                [],
                [],
                $conversation->getTlp(),
                'ScamBuster - ' . $convId,
            );
        }

        // Multi-label: the secondary scam-type codes already classified for this
        // conversation ride along as additional indicator labels (additive; the
        // primary type stays the STIX name/description driver).
        // getSecondaryScamTypes() is typed array<int, array{code: string, confidence: float}>
        // by the domain — trust the contract (the classifier writes that shape).
        $secondaryCodes = [];

        foreach ($conversation->getSecondaryScamTypes() ?? [] as $secondary) {
            if ($secondary['code'] !== '') {
                $secondaryCodes[] = $secondary['code'];
            }
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
                'secondary_scam_types' => $secondaryCodes,
            ];
        }

        $iocs = $this->attachIndicatorFields($iocs);

        // Indicator-to-indicator co-occurrence is no longer materialised as
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
        if ($includeThreatActor && $this->threatActorBuilder instanceof \App\Application\Stix\ThreatActorStixBuilder) {
            return $this->enrichWithThreatActor($conversation, $bundle);
        }

        return $bundle;
    }

    /**
     * Add threat-actor, attack-pattern, and relationships to the STIX bundle.
     *
     * If the conversation belongs to a cluster, the
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

        // Cluster delegation
        $clusterId = $this->clusterQueryService?->getClusterIdForConversation($convId);

        if ($clusterId !== null && $this->clusteredActorBuilder instanceof \App\Application\Stix\ClusteredThreatActorStixBuilder && $this->clusterQueryService instanceof \App\Application\Clustering\ClusterQueryService) {
            $clusterData = $this->clusterQueryService->getStixExportData($clusterId);

            if ($clusterData !== null) {
                $clusterObjects = $this->clusteredActorBuilder->buildThreatActorObjects($clusterData, $indicatorIds);
                $threatActor = $this->attachPsychProfile($clusterObjects['threat_actor'], $clusterId);
                $attackPatterns = $clusterObjects['attack_patterns'];
                $relationships = $clusterObjects['relationships'];

                // Attach Cognitive Mirror Note keyed on THIS
                // conversation's persona + scam type. A cluster threat-actor
                // exported via different conversations may carry different
                // notes; consumers dedup on the deterministic note id.
                $note = $this->buildCognitiveMirrorNote($bundle_conversation, $threatActor);

                // Scammer-side TTP layer for the embedded cluster actor, from the
                // cluster's confirmed aggregates assembled in getStixExportData.
                $actorId = \is_string($threatActor['id'] ?? null) ? $threatActor['id'] : '';
                /** @var list<array<string, mixed>> $clusterTtps */
                $clusterTtps = \is_array($clusterData['ttps'] ?? null) ? $clusterData['ttps'] : [];
                $ttpObjects = $this->ttpAttackPatternBuilder?->buildClusterTtpObjects($clusterTtps, $actorId, $clusterId, $this->nowStixTimestamp()) ?? [];

                return $this->mergeActorIntoBundle($bundle, $objects, $reportId, $threatActor, $attackPatterns, $relationships, $note, $ttpObjects);
            }
        }

        // Fallback: singleton threat-actor (conversation not clustered or cluster lookup failed)
        return $this->buildAndMergeSingletonActor($bundle_conversation, $bundle, $objects, $reportId, $indicatorIds);
    }

    /**
     * Enrich each IOC row with per-indicator fields (global sighting count from
     * indicator.occurrences, and per-IOC TLP from indicator.tlp) in one batch query.
     *
     * @param array<int, array<string, mixed>> $iocs
     *
     * @return array<int, array<string, mixed>>
     */
    private function attachIndicatorFields(array $iocs): array
    {
        $indicatorIds = array_values(array_filter(
            array_map(static fn (array $i): string => \is_string($i['indicator_id'] ?? null) ? $i['indicator_id'] : '', $iocs),
            static fn (string $id): bool => $id !== '',
        ));

        if ($indicatorIds === []) {
            return $iocs;
        }

        // The export-hold filter (financial IOCs without an analyst confirmation,
        // analyst false positives) lives HERE and not in IocHandler: the internal
        // UI must keep showing held IOCs so an analyst can review and release
        // them, but they must not leave the platform in a STIX bundle.
        /** @var array<int, array<string, mixed>> $rows */
        $rows = $this->em->getConnection()->fetchAllAssociative(
            'SELECT i.indicator_id, i.occurrences, i.tlp FROM indicator i'
            . ' LEFT JOIN ioc_analyst_feedback f ON i.indicator_id = f.indicator_id'
            . ' WHERE i.indicator_id IN (:ids)'
            . ' AND ' . IocExportPolicy::sqlCondition('i', 'f'),
            ['ids' => $indicatorIds],
            ['ids' => ArrayParameterType::STRING],
        );

        $byId = [];

        foreach ($rows as $row) {
            if (\is_string($row['indicator_id'] ?? null)) {
                $byId[$row['indicator_id']] = $row;
            }
        }

        // Drop the held/false-positive IOCs the query excluded.
        $iocs = array_values(array_filter(
            $iocs,
            static function (array $ioc) use ($byId): bool {
                $id = \is_string($ioc['indicator_id'] ?? null) ? $ioc['indicator_id'] : '';

                // IOC rows without a persisted indicator id cannot be verdict-checked;
                // keep legacy behaviour (they were never enriched either).
                return $id === '' || isset($byId[$id]);
            },
        ));

        foreach ($iocs as $k => $ioc) {
            $id = \is_string($ioc['indicator_id'] ?? null) ? $ioc['indicator_id'] : '';

            if ($id !== '' && isset($byId[$id])) {
                $iocs[$k]['occurrences'] = is_numeric($byId[$id]['occurrences'] ?? null) ? (int) $byId[$id]['occurrences'] : 1;

                if (\is_string($byId[$id]['tlp'] ?? null) && $byId[$id]['tlp'] !== '') {
                    $iocs[$k]['tlp'] = $byId[$id]['tlp'];
                }
            }
        }

        return $iocs;
    }

    /**
     * Attach the persisted psychological fingerprint as an x_scambuster_actor_psych
     * extension on the clustered threat-actor SDO. Silent no-op when the cluster has
     * no profile yet (or the readers are not wired).
     *
     * @param array<string, mixed> $threatActor
     *
     * @return array<string, mixed>
     */
    private function attachPsychProfile(array $threatActor, string $clusterId): array
    {
        if (!$this->psychProfileReader instanceof ThreatActorPsychProfileReaderInterface
            || !$this->psychProfileExtensionBuilder instanceof ActorPsychProfileStixExtensionBuilder) {
            return $threatActor;
        }

        $profile = $this->psychProfileReader->getByClusterId($clusterId);

        if ($profile === null) {
            return $threatActor;
        }

        // STIX 2.1: key by the extension-definition id, not a bare x_... key.
        $extensions = \is_array($threatActor['extensions'] ?? null) ? $threatActor['extensions'] : [];
        $extensions[ScambusterStixExtensions::PSYCH_ID] = ScambusterStixExtensions::wrap(
            'x_scambuster_actor_psych',
            $this->psychProfileExtensionBuilder->build($profile),
        );
        $threatActor['extensions'] = $extensions;

        return $threatActor;
    }

    /**
     * Look up the Cognitive Mirror SDO for (conversation's
     * persona, conversation's scam type) and the given threat-actor id.
     * Returns null when no mirror was cached (silent skip, not an error).
     *
     * @param array<string, mixed> $threatActor
     *
     * @return array<string, mixed>|null
     */
    private function buildCognitiveMirrorNote(Conversation $conversation, array $threatActor): ?array
    {
        if (!$this->cognitiveMirrorNoteBuilder instanceof CognitiveMirrorNoteBuilder) {
            return null;
        }

        $threatActorId = \is_string($threatActor['id'] ?? null) ? $threatActor['id'] : '';
        $persona = $conversation->getPersona();
        // Same fallback as buildAndMergeSingletonActor: a conversation with
        // no persona row is treated as 'generic_user' so the mirror lookup
        // can hit the seeded generic_user/<scam-type> pairing.
        $personaCode = $persona instanceof \App\Domain\Communication\Persona ? $persona->getPersonaCode() : 'generic_user';
        $scamTypeCode = $conversation->getScamType()->getCode();

        return $this->cognitiveMirrorNoteBuilder->build($threatActorId, $personaCode, $scamTypeCode);
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
        if (!$this->threatActorBuilder instanceof \App\Application\Stix\ThreatActorStixBuilder) {
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

        if ($excerpts !== []) {
            $excerptTexts = array_unique(array_filter(array_map(
                fn (array $r): string => \is_string($r['context_excerpt']) ? trim($r['context_excerpt']) : '',
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
        $personaCode = $persona instanceof \App\Domain\Communication\Persona ? $persona->getPersonaCode() : 'generic_user';

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

        // Use buildSingleton() instead of buildThreatActor()
        $threatActor = $this->threatActorBuilder->buildSingleton($campaignData, $actorProfile, $metrics);
        $threatActor['description'] = mb_substr($description, 0, 400);

        $attackPatterns = $this->threatActorBuilder->buildAttackPatterns($attckTechnique);
        $attackPatternIds = array_map(fn (array $ap): string => \is_string($ap['id']) ? $ap['id'] : '', $attackPatterns);

        $relationships = $this->threatActorBuilder->buildActorRelationships(
            \is_string($threatActor['id']) ? $threatActor['id'] : '',
            $indicatorIds,
            $attackPatternIds,
        );

        $note = $this->buildCognitiveMirrorNote($conversation, $threatActor);

        // Scammer-side TTP layer for the unattributed singleton actor: uses-
        // relationships to the SB-T* attack-patterns observed in THIS conversation
        // (confirmed only), with start/stop_time from the conversation's own
        // first/last observation. No sightings (those are cluster-scoped).
        $actorId = \is_string($threatActor['id'] ?? null) ? $threatActor['id'] : '';
        $convTtps = $this->ttpQueryService?->conversationTtpStixData($convId) ?? [];
        $ttpObjects = $this->ttpAttackPatternBuilder?->buildConversationTtpObjects($convTtps, $actorId, $this->nowStixTimestamp()) ?? [];

        return $this->mergeActorIntoBundle($bundle, $objects, $reportId, $threatActor, $attackPatterns, $relationships, $note, $ttpObjects);
    }

    /**
     * Append the threat-actor, attack-patterns, relationships, and (when
     * present) the Cognitive Mirror Note SDO into the bundle and
     * update the report's object_refs.
     *
     * @param array<string, mixed>       $bundle
     * @param list<array<string, mixed>> $objects
     * @param array<string, mixed>       $threatActor
     * @param list<array<string, mixed>> $attackPatterns
     * @param list<array<string, mixed>> $relationships
     * @param array<string, mixed>|null  $note           Cognitive Mirror Note SDO or null
     * @param list<array<string, mixed>> $ttpObjects     SB-T* attack-patterns + uses-relationships (+ sightings/ext-def for clustered)
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
        ?array $note = null,
        array $ttpObjects = [],
    ): array {
        // Update report.object_refs with the threat-actor + attack-patterns + note
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

                    // Reference the SB-T* attack-patterns too (relationships and
                    // sightings are discoverable via their refs, not the report).
                    foreach ($ttpObjects as $ttpObj) {
                        if (($ttpObj['type'] ?? '') === 'attack-pattern' && \is_string($ttpObj['id'] ?? null)) {
                            $refs[] = $ttpObj['id'];
                        }
                    }

                    if ($note !== null && \is_string($note['id'] ?? null)) {
                        $refs[] = $note['id'];
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

        if ($note !== null) {
            $objects[] = $note;
        }

        foreach ($ttpObjects as $ttpObj) {
            $objects[] = $ttpObj;
        }

        $bundle['objects'] = $objects;

        return $bundle;
    }

    /**
     * Current STIX 2.1 timestamp (millisecond precision) for relationship
     * created/modified stamps, matching the other STIX builders' "now" format.
     */
    private function nowStixTimestamp(): string
    {
        return (new \DateTimeImmutable())->format('Y-m-d\TH:i:s.v\Z');
    }
}
