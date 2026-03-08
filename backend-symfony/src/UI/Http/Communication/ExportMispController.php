<?php

declare(strict_types=1);

namespace App\UI\Http\Communication;

use App\Application\Communication\IocHandler;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

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
final class ExportMispController
{
    public function __construct(
        private readonly IocHandler $iocHandler
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
    #[Route('/api/v1/conversations/{id}/export/misp', methods: ['GET'])]
    public function __invoke(string $id): JsonResponse
    {
        // Retrieve all IOCs for conversation (deduplicated)
        $iocs = $this->iocHandler->getConversationIocs($id);

        if (empty($iocs)) {
            return new JsonResponse(
                ['error' => 'No IOCs found for conversation'],
                Response::HTTP_NOT_FOUND
            );
        }

        // Build MISP Event Attributes from IOCs
        $attributes = [];

        foreach ($iocs as $ioc) {
            $context = $ioc->getContext();

            // Extract MISP metadata (already enriched by IocExportMapper)
            $mispMetadata = $context['misp'] ?? null;

            if (!$mispMetadata) {
                // Skip IOCs without MISP metadata (should not happen after migration)
                continue;
            }

            $attribute = [
                'category' => $mispMetadata['category'],
                'type' => $mispMetadata['type'],
                'value' => $context['value_norm'] ?? $context['value'],
                'to_ids' => $mispMetadata['to_ids'],
                'comment' => $this->buildComment($context),
                'Tag' => $this->buildTags($context),
            ];

            $attributes[] = $attribute;
        }

        // Build MISP Event
        $event = [
            'Event' => [
                'info' => "ScamBuster conversation {$id}",
                'threat_level_id' => 2, // Medium
                'analysis' => 1, // Ongoing
                'distribution' => 3, // All communities
                'Attribute' => $attributes,
            ],
        ];

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
        if (isset($context['score']['agg']) && is_int($context['score']['agg']) && $context['score']['agg'] > 0) {
            $parts[] = "Risk score: {$context['score']['agg']}/100";
        }

        // Add source
        if (isset($context['source']) && is_string($context['source'])) {
            $parts[] = "Source: {$context['source']}";
        }

        return empty($parts) ? 'ScamBuster honeypot IOC' : implode(' | ', $parts);
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
