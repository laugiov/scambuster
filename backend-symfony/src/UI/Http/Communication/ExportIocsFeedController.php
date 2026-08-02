<?php

declare(strict_types=1);

namespace App\UI\Http\Communication;

use App\Application\Export\IocFeedExporter;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Export a selection of IOCs as a flat CSV or NDJSON feed.
 *
 * Sibling to {@see ExportIocsStixController}: same `indicator_ids` selection
 * contract and `ioc:export` gate, but analyst-friendly flat formats
 * (CSV for spreadsheets/grep, NDJSON for streaming SIEM ingestion / jq).
 */
#[IsGranted('ioc:export')]
final readonly class ExportIocsFeedController
{
    public function __construct(
        private IocFeedExporter $exporter,
    ) {
    }
    #[OA\Post(
        path: '/api/v1/iocs/export/feed',
        summary: 'Export selected IOCs as a flat CSV or NDJSON feed',
        tags: ['Export'],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                type: 'object',
                properties: [
                    new OA\Property(
                        property: 'indicator_ids',
                        type: 'array',
                        items: new OA\Items(type: 'string', format: 'uuid'),
                        description: 'List of indicator UUIDs to export (from IOC Explorer)'
                    ),
                    new OA\Property(
                        property: 'format',
                        type: 'string',
                        enum: ['csv', 'ndjson'],
                        default: 'csv',
                        description: 'Feed format'
                    ),
                ]
            )
        ),
        responses: [
            new OA\Response(response: 200, description: 'CSV or NDJSON feed'),
            new OA\Response(response: 400, description: 'Missing indicator_ids or unsupported format'),
        ],
        security: [['Bearer' => []]]
    )]
    #[Route('/api/v1/iocs/export/feed', name: 'export_iocs_feed', methods: ['POST'])]
    public function __invoke(Request $request): Response
    {
        $data = json_decode((string) $request->getContent(), true);

        if (!\is_array($data)) {
            return new JsonResponse(['error' => 'Invalid JSON'], Response::HTTP_BAD_REQUEST);
        }

        /** @var array<int, string> $indicatorIds */
        $indicatorIds = [];

        if (isset($data['indicator_ids']) && \is_array($data['indicator_ids'])) {
            $indicatorIds = array_values(array_filter($data['indicator_ids'], 'is_string'));
        }

        if ($indicatorIds === []) {
            return new JsonResponse(['error' => 'Missing or empty indicator_ids'], Response::HTTP_BAD_REQUEST);
        }

        $format = \is_string($data['format'] ?? null) && $data['format'] !== ''
            ? $data['format']
            : IocFeedExporter::FORMAT_CSV;

        if (!\in_array($format, IocFeedExporter::supportedFormats(), true)) {
            return new JsonResponse(
                ['error' => sprintf('Unsupported format "%s". Use one of: %s', $format, implode(', ', IocFeedExporter::supportedFormats()))],
                Response::HTTP_BAD_REQUEST
            );
        }

        $body = $this->exporter->export($indicatorIds, $format);

        $response = new Response($body, Response::HTTP_OK);
        $response->headers->set('Content-Type', IocFeedExporter::contentType($format));
        $response->headers->set(
            'Content-Disposition',
            sprintf('attachment; filename="scambuster-iocs.%s"', IocFeedExporter::fileExtension($format))
        );

        return $response;
    }
}
