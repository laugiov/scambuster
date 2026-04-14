<?php

declare(strict_types=1);

namespace App\UI\Http\Campaign;

use App\Application\Campaign\ProfileCampaignHandler;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Uid\Uuid;

#[Route('/api/v1/campaign/{campaign_id}/profile', name: 'api_campaign_profile', methods: ['POST'])]
#[IsGranted('campaign:read')]
final readonly class ProfileCampaignController
{
    public function __construct(
        private ProfileCampaignHandler $handler
    ) {
    }
    #[OA\Post(
        path: '/api/v1/campaign/{campaign_id}/profile',
        summary: 'Profiler une campagne via LLM',
        description: 'Analyzes a sample of campaign messages and generates a descriptive YAML profile via GPT-4. The profile includes: summary, tactics, target, CTA, variants and infrastructure.',
        tags: ['Campaign Radar'],
        parameters: [
            new OA\Parameter(
                name: 'campaign_id',
                in: 'path',
                required: true,
                description: 'UUID of the campaign to profile',
                schema: new OA\Schema(type: 'string', format: 'uuid')
            ),
        ],
        requestBody: new OA\RequestBody(
            required: false,
            content: new OA\JsonContent(
                type: 'object',
                properties: [
                    new OA\Property(
                        property: 'sample_size',
                        type: 'integer',
                        description: 'Number of messages to analyze (min: 3, max: 100)',
                        example: 10,
                        default: 10
                    ),
                ]
            )
        ),
        responses: [
            new OA\Response(
                response: 200,
                description: 'Profile generated successfully',
                content: new OA\JsonContent(
                    type: 'object',
                    properties: [
                        new OA\Property(property: 'profile_yaml', type: 'string', description: 'Profil de campagne au format YAML'),
                        new OA\Property(property: 'cache_hit', type: 'boolean', description: 'True if result came from Redis cache'),
                        new OA\Property(property: 'attempts', type: 'integer', description: 'Number of LLM attempts required'),
                    ]
                )
            ),
            new OA\Response(
                response: 400,
                description: 'Invalid parameters',
                content: new OA\JsonContent(
                    type: 'object',
                    properties: [new OA\Property(property: 'error', type: 'string')]
                )
            ),
            new OA\Response(
                response: 404,
                description: 'Campagne introuvable ou pas assez de messages',
                content: new OA\JsonContent(
                    type: 'object',
                    properties: [new OA\Property(property: 'error', type: 'string')]
                )
            ),
            new OA\Response(
                response: 500,
                description: 'Erreur lors du profiling LLM',
                content: new OA\JsonContent(
                    type: 'object',
                    properties: [new OA\Property(property: 'error', type: 'string')]
                )
            ),
        ]
    )]
    public function __invoke(string $campaign_id, Request $request): JsonResponse
    {
        // 1. Validation campaign_id
        try {
            $campaignId = Uuid::fromString($campaign_id);
        } catch (\InvalidArgumentException) {
            return new JsonResponse(['error' => 'Invalid campaign_id format'], Response::HTTP_BAD_REQUEST);
        }

        // 2. Optional parameters
        /** @var array<string, mixed> $data */
        $data = json_decode($request->getContent(), true) ?? [];
        $sampleSize = $data['sample_size'] ?? 10;

        if (!is_int($sampleSize) || $sampleSize < 3 || $sampleSize > 100) {
            return new JsonResponse(
                ['error' => 'sample_size must be an integer between 3 and 100'],
                Response::HTTP_BAD_REQUEST
            );
        }

        // 3. Appel handler
        try {
            $result = $this->handler->handle($campaignId, $sampleSize);
        } catch (\RuntimeException $e) {
            return new JsonResponse(['error' => $e->getMessage()], Response::HTTP_NOT_FOUND);
        } catch (\Throwable $e) {
            return new JsonResponse(['error' => 'Profiling failed: ' . $e->getMessage()], Response::HTTP_INTERNAL_SERVER_ERROR);
        }

        // 4. Response
        return new JsonResponse([
            'profile_yaml' => $result['profile_yaml'],
            'cache_hit' => $result['cache_hit'],
            'attempts' => $result['attempts'],
        ], Response::HTTP_OK);
    }
}
