<?php

declare(strict_types=1);

namespace App\UI\Http\Communication;

use App\Application\Communication\IocHandler;
use App\Application\Stix\StixBundleBuilder;
use App\Domain\Communication\Conversation;
use Doctrine\ORM\EntityManagerInterface;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Export conversation IOCs as a STIX 2.1 bundle compatible with OpenCTI import.
 */
#[IsGranted('ioc:export')]
final class ExportConversationStixController
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly IocHandler $iocHandler,
        private readonly StixBundleBuilder $bundleBuilder,
    ) {
    }

    #[OA\Get(
        path: '/api/v1/conversations/{conv_id}/export/stix',
        summary: 'Export conversation IOCs as STIX 2.1 bundle (OpenCTI compatible)',
        tags: ['Export'],
        parameters: [
            new OA\Parameter(
                name: 'conv_id',
                in: 'path',
                required: true,
                description: 'Conversation UUID',
                schema: new OA\Schema(type: 'string', format: 'uuid')
            ),
        ],
        responses: [
            new OA\Response(response: 200, description: 'STIX 2.1 bundle JSON'),
            new OA\Response(response: 404, description: 'Conversation not found'),
        ],
        security: [['Bearer' => []]]
    )]
    #[Route('/api/v1/conversations/{conv_id}/export/stix', name: 'export_conversation_stix', methods: ['GET'])]
    public function __invoke(string $conv_id): JsonResponse
    {
        $conversation = $this->em->getRepository(Conversation::class)->find($conv_id);

        if (!$conversation) {
            return new JsonResponse(['error' => 'Conversation not found'], Response::HTTP_NOT_FOUND);
        }

        // Get conversation IOCs (deduplicated)
        $observedIocs = $this->iocHandler->getConversationIocs($conv_id);

        if (empty($observedIocs)) {
            // Return empty but valid bundle
            $bundle = $this->bundleBuilder->buildBundle(
                [],
                [],
                $conversation->getTlp(),
                'ScamBuster - ' . $conv_id,
            );

            return new JsonResponse($bundle, Response::HTTP_OK);
        }

        // Build IOC data array for the builder
        $iocs = [];
        $indicatorMap = []; // indicator_id → index in $iocs

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

            $indicatorMap[$indicatorId] = \count($iocs) - 1;
        }

        // Build relationships from co-occurrence (IOCs in same conversation are related)
        $relationships = [];

        for ($i = 0; $i < \count($iocs); ++$i) {
            for ($j = $i + 1; $j < \count($iocs); ++$j) {
                // Skip header IOC types in relationships
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
            "ScamBuster - {$scamType} conversation {$conv_id}",
            "IOCs extracted from {$scamType} scam conversation tracked by ScamBuster honeypot",
        );

        return new JsonResponse($bundle, Response::HTTP_OK);
    }
}
