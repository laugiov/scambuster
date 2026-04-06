<?php

declare(strict_types=1);

namespace App\Application\Stix;

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

        // Build relationships from co-occurrence (IOCs in same conversation are related)
        $relationships = [];

        for ($i = 0; $i < \count($iocs); ++$i) {
            for ($j = $i + 1; $j < \count($iocs); ++$j) {
                $headerTypes = ['message_id', 'subject', 'spf_result', 'dkim_result', 'dmarc_result', 'x_mailer', 'return_path'];

                if (\in_array($iocs[$i]['type'], $headerTypes, true) || \in_array($iocs[$j]['type'], $headerTypes, true)) {
                    continue;
                }

                $relationships[] = [
                    'source_indicator_id' => $iocs[$i]['indicator_id'],
                    'target_indicator_id' => $iocs[$j]['indicator_id'],
                    'source_type' => $iocs[$i]['type'],
                    'source_value_norm' => $iocs[$i]['value_norm'],
                    'target_type' => $iocs[$j]['type'],
                    'target_value_norm' => $iocs[$j]['value_norm'],
                    'weight' => 1,
                ];
            }
        }

        $scamType = $conversation->getScamType()->getCode();

        $bundle = $this->bundleBuilder->buildBundle(
            $iocs,
            $relationships,
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
     * @param array<string, mixed> $bundle
     *
     * @return array<string, mixed>
     */
    private function enrichWithThreatActor(Conversation $bundle_conversation, array $bundle): array
    {
        $conn = $this->em->getConnection();
        $convId = $bundle_conversation->getConvId();
        $scamType = $bundle_conversation->getScamType();

        // Get MITRE technique
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
            . ' LIMIT 3',
            ['convId' => $convId],
        );

        $description = sprintf('Criminal actor operating %s scam.', strtolower($scamType->getCode()));

        if (!empty($excerpts)) {
            $excerptTexts = array_map(fn (array $r) => \is_string($r['context_excerpt']) ? $r['context_excerpt'] : '', $excerpts);
            $description .= ' ' . implode(' ', array_filter($excerptTexts));
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
        $engagementHours = $bundle_conversation->getEngagementDurationSec() / 3600.0;
        $turns = $bundle_conversation->getTurnsCount();

        $metrics = [
            'conversation_count' => 1,
            'avg_engagement_hours' => $engagementHours,
            'avg_turns' => (float) $turns,
            'unique_ioc_type_count' => $uniqueIocTypeCount,
            'has_injection_attempts' => false,
        ];

        // Persona info for extension
        $persona = $bundle_conversation->getPersona();
        $personaCode = $persona !== null ? $persona->getPersonaCode() : 'generic_user';

        $campaignData = [
            'campaign_id' => $convId,
            'scam_type' => $scamType->getCode(),
            'first_seen' => $bundle_conversation->getTsFirst()->format('Y-m-d H:i:s'),
            'last_seen' => $bundle_conversation->getTsLast()->format('Y-m-d H:i:s'),
            'profile_yaml' => null,
            'tlp' => $bundle_conversation->getTlp(),
        ];

        // Build actor profile from conversation data
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

        $threatActor = $this->threatActorBuilder->buildThreatActor($campaignData, $actorProfile, $metrics);
        $threatActor['description'] = mb_substr($description, 0, 400);

        $attackPatterns = $this->threatActorBuilder->buildAttackPatterns($attckTechnique);

        // Collect indicator IDs from bundle
        $indicatorIds = [];
        /** @var list<array<string, mixed>> $objects */
        $objects = \is_array($bundle['objects'] ?? null) ? $bundle['objects'] : [];

        foreach ($objects as $obj) {
            if (($obj['type'] ?? '') === 'indicator' && \is_string($obj['id'] ?? null)) {
                $indicatorIds[] = $obj['id'];
            }
        }

        $attackPatternIds = array_map(fn (array $ap) => $ap['id'], $attackPatterns);

        $relationships = $this->threatActorBuilder->buildActorRelationships(
            $threatActor['id'],
            'conversation--' . $convId,
            $indicatorIds,
            $attackPatternIds,
        );

        // Merge
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
