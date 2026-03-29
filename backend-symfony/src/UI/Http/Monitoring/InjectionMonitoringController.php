<?php

declare(strict_types=1);

namespace App\UI\Http\Monitoring;

use App\Application\Monitoring\InjectionMonitoringHandler;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/v1/monitoring/injection', name: 'api_monitoring_injection', methods: ['GET'])]
final class InjectionMonitoringController
{
    public function __construct(
        private readonly InjectionMonitoringHandler $handler,
    ) {
    }

    #[OA\Get(
        path: '/api/v1/monitoring/injection',
        summary: 'Get prompt injection detection statistics and alerts',
        tags: ['Monitoring'],
        parameters: [
            new OA\Parameter(name: 'days', in: 'query', required: false, schema: new OA\Schema(type: 'integer', default: 7)),
        ],
        responses: [new OA\Response(response: 200, description: 'Injection monitoring stats')],
        security: [['Bearer' => []]],
    )]
    public function __invoke(Request $request): JsonResponse
    {
        /** @var string $rawDays */
        $rawDays = $request->query->get('days', '7');
        $days = (int) $rawDays;

        return new JsonResponse(
            $this->handler->getStats($days),
            Response::HTTP_OK,
        );
    }
}
