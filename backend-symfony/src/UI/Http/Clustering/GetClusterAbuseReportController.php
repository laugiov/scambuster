<?php

declare(strict_types=1);

namespace App\UI\Http\Clustering;

use App\Application\ThreatActor\AbuseReportGenerator;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[OA\Get(
    path: '/api/v1/clusters/{id}/abuse-report',
    summary: 'Abuse / takedown report for a threat-actor cluster',
    description: 'A factual, first-party abuse report combining the cluster identity, actionable indicators (each routed to its standard abuse desk), temporal activity and the psychological profile. Includes a ready-to-send plain-text rendering.',
    tags: ['Clusters'],
    parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
    responses: [
        new OA\Response(response: 200, description: 'Abuse report (structured + text)'),
        new OA\Response(response: 404, description: 'Unknown cluster'),
    ],
    security: [['Bearer' => []]],
)]
final readonly class GetClusterAbuseReportController
{
    public function __construct(
        private AbuseReportGenerator $generator,
    ) {
    }

    #[Route('/api/v1/clusters/{id}/abuse-report', name: 'cluster_abuse_report', methods: ['GET'], requirements: ['id' => '[0-9a-f-]{36}'])]
    #[IsGranted('ioc:export')]
    public function __invoke(string $id): JsonResponse
    {
        $report = $this->generator->generate($id);

        if ($report === null) {
            return new JsonResponse(['error' => 'Unknown cluster'], Response::HTTP_NOT_FOUND);
        }

        return new JsonResponse($report);
    }
}
