<?php

declare(strict_types=1);

namespace App\UI\Http\Scambaiting;

use App\Application\Scambaiting\ConversationClosureService;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

/**
 * Ferme une conversation et déclenche le calcul de reward + mise à jour des stats.
 * Ce endpoint est appelé par le workflow n8n WF-SCAMBAITING-END-CONVERSATION.
 */
#[Route('/api/v1/scambaiting/conversation/{convId}/close', name: 'api_scambaiting_close_conversation', methods: ['POST'])]
final class CloseConversationController extends AbstractController
{
    public function __construct(
        private readonly ConversationClosureService $closureService,
        private readonly LoggerInterface $logger
    ) {
    }

    public function __invoke(string $convId): JsonResponse
    {
        try {
            $this->closureService->closeConversation($convId);

            $this->logger->info('Conversation closed via API', [
                'conv_id' => $convId,
            ]);

            return new JsonResponse([
                'success' => true,
                'message' => 'Conversation closed successfully',
                'conv_id' => $convId,
            ], Response::HTTP_OK);
        } catch (\RuntimeException $e) {
            $this->logger->error('Failed to close conversation via API', [
                'conv_id' => $convId,
                'error' => $e->getMessage(),
            ]);

            return new JsonResponse([
                'success' => false,
                'error' => $e->getMessage(),
            ], Response::HTTP_BAD_REQUEST);
        } catch (\Exception $e) {
            $this->logger->error('Unexpected error closing conversation', [
                'conv_id' => $convId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return new JsonResponse([
                'success' => false,
                'error' => 'Internal server error',
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }
}
