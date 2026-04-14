<?php

declare(strict_types=1);

namespace App\UI\Http\Communication;

use App\Application\Communication\ScamTypeHandler;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/api/v1/communication/scam-types')]
#[IsGranted('conversation:read')]
final readonly class ScamTypeController
{
    public function __construct(
        private ScamTypeHandler $handler
    ) {
    }
    #[OA\Get(
        path: '/api/v1/communication/scam-types',
        summary: 'Liste tous les types de scam disponibles',
        tags: ['Scam Types'],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Liste des types de scam',
                content: new OA\JsonContent(
                    type: 'array',
                    items: new OA\Items(
                        type: 'object',
                        properties: [
                            new OA\Property(property: 'scam_type_id', type: 'integer', example: 1),
                            new OA\Property(property: 'code', type: 'string', example: 'phishing'),
                            new OA\Property(property: 'label_en', type: 'string', example: 'Phishing'),
                            new OA\Property(property: 'label_fr', type: 'string', example: 'Hameçonnage'),
                            new OA\Property(property: 'persona', type: 'string', example: 'bank_customer', description: 'Persona associated with this scam type')
                        ]
                    )
                )
            )
        ],
        security: [ [ 'Bearer' => [] ] ]
    )]
    #[Route('', name: 'list_scam_types', methods: ['GET'])]
    public function __invoke(): JsonResponse
    {
        $scamTypes = $this->handler->getAllScamTypes();

        return new JsonResponse($scamTypes, Response::HTTP_OK);
    }
}
