<?php

declare(strict_types=1);

namespace App\UI\Http\Communication;

use App\Application\Audit\AuditLogger;
use App\Application\Communication\ConversationScamTaxonomyProvider;
use App\Application\Communication\IocExportMapper;
use App\Application\Communication\IocHandler;
use App\Application\Communication\TtpMispTagProvider;
use App\Application\ThreatActor\IocFeedbackReaderInterface;
use App\Domain\Communication\ObservedIoc;
use App\Domain\ThreatActor\AnalystVerdict;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Export conversation IOCs to MISP Event format.
 *
 * Returns a MISP Event JSON ready for import into MISP platform.
 * Supports TLP marking and scam type tagging.
 *
 * DDD Architecture:
 * - Controller: HTTP interface only (NO business logic)
 * - Delegates to IocHandler for IOC retrieval
 * - Transforms IOC context to MISP Event format
 */
final readonly class ExportMispController
{
    public function __construct(
        private IocHandler $iocHandler,
        private IocExportMapper $exportMapper,
        private ?AuditLogger $auditLogger = null,
        private ?ConversationScamTaxonomyProvider $scamTaxonomyProvider = null,
        private ?IocFeedbackReaderInterface $feedbackReader = null,
        private ?TtpMispTagProvider $ttpTagProvider = null,
    ) {
    }

    /**
     * Export conversation IOCs as MISP Event.
     *
     * GET /api/v1/conversations/{id}/export/misp
     *
     * Returns JSON structure compatible with MISP Event import:
     * - Event.info: Conversation description
     * - Event.Attribute[]: IOCs mapped to MISP types
     * - Tags: TLP + scam type
     *
     * @param string $id Conversation UUID
     *
     * @return JsonResponse MISP Event JSON
     */
    #[OA\Get(
        path: '/api/v1/conversations/{id}/export/misp',
        summary: 'Export conversation IOCs as MISP Event',
        description: 'Returns a MISP Event JSON ready for import into MISP platform. Includes TLP marking and scam type tagging.',
        tags: ['Communication'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, description: 'Conversation UUID', schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'MISP Event JSON', content: new OA\JsonContent(
                type: 'object',
                properties: [
                    new OA\Property(property: 'Event', type: 'object', properties: [
                        new OA\Property(property: 'info', type: 'string', example: 'ScamBuster conversation abc-123'),
                        new OA\Property(property: 'threat_level_id', type: 'integer', example: 2),
                        new OA\Property(property: 'analysis', type: 'integer', example: 1),
                        new OA\Property(property: 'distribution', type: 'integer', example: 3),
                        new OA\Property(property: 'Attribute', type: 'array', items: new OA\Items(type: 'object')),
                    ]),
                ]
            )),
            new OA\Response(response: 404, description: 'No IOCs found for conversation'),
        ],
        security: [['Bearer' => []]]
    )]
    #[Route('/api/v1/conversations/{id}/export/misp', methods: ['GET'])]
    #[IsGranted('ioc:export')]
    public function __invoke(string $id): JsonResponse
    {
        // Retrieve all IOCs for conversation (deduplicated)
        $iocs = $this->iocHandler->getConversationIocs($id);

        if ($iocs === []) {
            return new JsonResponse(
                ['error' => 'No IOCs found for conversation'],
                Response::HTTP_NOT_FOUND
            );
        }

        // An analyst verdict is authoritative for actionability: batch-fetch the current
        // verdict per indicator so a false-positive is never auto-actioned (to_ids=false)
        // and a confirmed IOC is explicitly actionable.
        $indicatorIds = array_values(array_filter(array_map(
            static fn (ObservedIoc $ioc): string => $ioc->getIndicatorId(),
            $iocs,
        )));
        $verdicts = $this->feedbackReader?->getVerdicts($indicatorIds) ?? [];

        // Build MISP Event Attributes from IOCs
        $attributes = [];

        foreach ($iocs as $ioc) {
            $context = $ioc->getContext();

            // MISP metadata is normally written at ingest time by IocExportMapper.
            // Rows that predate that step — the seeded demo dataset, anything
            // ingested before app:migrate-iocs-export-metadata ran — carry none.
            // Skipping them produced a well-formed Event with zero attributes and
            // HTTP 200: a silent, complete loss of the export. Derive the mapping
            // on the fly instead; it is a pure function of the IOC type and value,
            // and StixBundleBuilder already falls back the same way.
            $mispMetadata = $context['misp'] ?? null;

            if (!is_array($mispMetadata)) {
                $context = $this->exportMapper->enrichWithExportMetadata($context);
                $mispMetadata = \is_array($context['misp'] ?? null) ? $context['misp'] : null;
            }

            if (!is_array($mispMetadata)) {
                // Unreachable in practice: enrichWithExportMetadata always sets
                // the key. Kept so a future change there cannot emit a malformed
                // attribute instead of dropping one.
                continue;
            }

            $toIds = $mispMetadata['to_ids'];
            $tags = $this->buildTags($context);

            $verdict = $verdicts[$ioc->getIndicatorId()] ?? null;

            if ($verdict instanceof AnalystVerdict) {
                $toIds = $verdict === AnalystVerdict::Confirmed;
                $tags[] = ['name' => 'scambuster:analyst-verdict="' . $verdict->value . '"'];
            }

            $attributes[] = [
                'category' => $mispMetadata['category'],
                'type' => $mispMetadata['type'],
                'value' => $context['value_norm'] ?? $context['value'],
                'to_ids' => $toIds,
                'comment' => $this->buildComment($context),
                'Tag' => $tags,
            ];
        }

        // Event-level scam-type classification (primary + secondary), mapped to
        // standard CTI machine tags: RSIT taxonomy + MITRE ATT&CK MISP galaxy +
        // first-party scam-type. The classification is a property of the whole
        // conversation, so it rides on the Event, not on each IOC attribute.
        // Merge scam-type tags with the confirmed-TTP tags (deduplicated by tag
        // string — a TTP MITRE galaxy tag may coincide with a scam-type one).
        $eventTagNames = $this->scamTaxonomyProvider?->tagsForConversation($id) ?? [];

        foreach ($this->ttpTagProvider?->tagsForConversation($id) ?? [] as $ttpTag) {
            if (!\in_array($ttpTag, $eventTagNames, true)) {
                $eventTagNames[] = $ttpTag;
            }
        }

        $eventTags = array_map(
            static fn (string $name): array => ['name' => $name],
            $eventTagNames,
        );

        // Build MISP Event
        $eventBody = [
            'info' => "ScamBuster conversation {$id}",
            'threat_level_id' => 2, // Medium
            'analysis' => 1, // Ongoing
            'distribution' => 3, // All communities
            'Attribute' => $attributes,
        ];

        if ($eventTags !== []) {
            $eventBody['Tag'] = $eventTags;
        }

        $event = ['Event' => $eventBody];

        $this->auditLogger?->log(
            \App\Domain\Audit\AuditEventType::EXPORT_MISP,
            $id,
            'export_misp',
            'success',
            'conversation',
            $id,
            [
                'ioc_count' => count($attributes),
            ],
        );

        return new JsonResponse($event, Response::HTTP_OK);
    }

    /**
     * Build MISP attribute comment from IOC context.
     *
     * @param array<string, mixed> $context IOC context
     *
     * @return string Comment describing the IOC
     */
    private function buildComment(array $context): string
    {
        $parts = [];

        // Add scam category if available
        if (isset($context['category']) && is_string($context['category'])) {
            $parts[] = "Scam type: {$context['category']}";
        }

        // Add score if available and significant
        /** @var array<string, mixed> $score */
        $score = $context['score'] ?? [];

        if (isset($score['agg']) && is_int($score['agg']) && $score['agg'] > 0) {
            $parts[] = "Risk score: {$score['agg']}/100";
        }

        // Add source
        if (isset($context['source']) && is_string($context['source'])) {
            $parts[] = "Source: {$context['source']}";
        }

        return $parts === [] ? 'ScamBuster honeypot IOC' : implode(' | ', $parts);
    }

    /**
     * Build MISP tags from IOC context.
     *
     * @param array<string, mixed> $context IOC context
     *
     * @return array<int, array{name: string}> MISP tags
     */
    private function buildTags(array $context): array
    {
        $tags = [];

        // Add TLP marking
        if (isset($context['tlp']) && is_string($context['tlp'])) {
            $tags[] = ['name' => 'tlp:' . strtolower($context['tlp'])];
        }

        // Add scam type tag
        if (isset($context['category']) && is_string($context['category'])) {
            $tags[] = ['name' => 'scam:type=' . $context['category']];
        }

        // Add custom tags from context
        if (isset($context['tags']) && is_array($context['tags'])) {
            foreach ($context['tags'] as $tag) {
                if (is_string($tag)) {
                    $tags[] = ['name' => $tag];
                }
            }
        }

        return $tags;
    }
}
