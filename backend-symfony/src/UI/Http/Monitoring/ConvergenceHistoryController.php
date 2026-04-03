<?php

declare(strict_types=1);

namespace App\UI\Http\Monitoring;

use App\Domain\Scambaiting\BanditConvergenceLog;
use Doctrine\ORM\EntityManagerInterface;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/api/v1/monitoring/convergence-history', name: 'monitoring_convergence_history', methods: ['GET'])]
#[IsGranted('monitoring:read')]
final class ConvergenceHistoryController
{
    #[OA\Get(
        path: '/api/v1/monitoring/convergence-history',
        summary: 'Bandit convergence history by scam type (last 30 days)',
        tags: ['Monitoring'],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Convergence history grouped by scam type',
                content: new OA\JsonContent(
                    type: 'object',
                    properties: [
                        new OA\Property(property: 'period_days', type: 'integer', example: 30),
                        new OA\Property(
                            property: 'by_scam_type',
                            type: 'object',
                            additionalProperties: new OA\AdditionalProperties(
                                type: 'array',
                                items: new OA\Items(
                                    type: 'object',
                                    properties: [
                                        new OA\Property(property: 'date', type: 'string', format: 'date'),
                                        new OA\Property(property: 'dominant_persona', type: 'string'),
                                        new OA\Property(property: 'dominant_pct', type: 'number', format: 'float'),
                                        new OA\Property(property: 'sessions_count', type: 'integer'),
                                        new OA\Property(property: 'converged', type: 'boolean'),
                                    ]
                                )
                            )
                        ),
                    ]
                )
            )
        ],
        security: [['Bearer' => []]]
    )]
    public function __invoke(EntityManagerInterface $em): JsonResponse
    {
        $since = new \DateTimeImmutable('-30 days');

        /** @var list<BanditConvergenceLog> $logs */
        $logs = $em->createQueryBuilder()
            ->select('l')
            ->from(BanditConvergenceLog::class, 'l')
            ->where('l.loggedAt >= :since')
            ->setParameter('since', $since)
            ->orderBy('l.loggedAt', 'DESC')
            ->getQuery()
            ->getResult();

        $grouped = [];

        foreach ($logs as $log) {
            $grouped[$log->getScamTypeCode()][] = [
                'date' => $log->getLoggedAt()->format('Y-m-d'),
                'dominant_persona' => $log->getDominantPersonaCode(),
                'dominant_pct' => $log->getDominantPct(),
                'sessions_count' => $log->getSessionsCount(),
                'converged' => $log->isConverged(),
            ];
        }

        return new JsonResponse([
            'period_days' => 30,
            'by_scam_type' => (object) $grouped,
        ]);
    }
}
