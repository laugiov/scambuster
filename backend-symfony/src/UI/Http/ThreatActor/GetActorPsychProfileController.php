<?php

declare(strict_types=1);

namespace App\UI\Http\ThreatActor;

use App\Application\ThreatActor\ThreatActorPsychProfileReaderInterface;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[OA\Get(
    path: '/api/v1/clusters/{id}/psych-profile',
    summary: 'Threat-actor psychological profile for a cluster',
    tags: ['Clusters'],
    parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
    responses: [
        new OA\Response(response: 200, description: 'Psychological profile'),
        new OA\Response(response: 404, description: 'No profile for this cluster'),
    ],
    security: [['Bearer' => []]],
)]
final readonly class GetActorPsychProfileController
{
    public function __construct(
        private ThreatActorPsychProfileReaderInterface $reader,
    ) {
    }

    #[Route('/api/v1/clusters/{id}/psych-profile', name: 'cluster_psych_profile', methods: ['GET'], requirements: ['id' => '[0-9a-f-]{36}'])]
    #[IsGranted('ioc:read')]
    public function __invoke(string $id): JsonResponse
    {
        $profile = $this->reader->getByClusterId($id);

        if ($profile === null) {
            return new JsonResponse(['error' => 'No psychological profile for this cluster'], Response::HTTP_NOT_FOUND);
        }

        return new JsonResponse($profile->toArray());
    }
}
