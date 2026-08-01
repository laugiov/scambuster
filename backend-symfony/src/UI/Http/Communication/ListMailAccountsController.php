<?php

declare(strict_types=1);

namespace App\UI\Http\Communication;

use App\Application\Communication\Dto\MailAccountListItemDto;
use App\Application\Communication\ListMailAccountsForOperatorHandler;
use Nelmio\ApiDocBundle\Attribute\Model;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[OA\Get(
    path: '/api/v1/communication/mail-accounts',
    summary: 'List active mail accounts (operator-facing)',
    tags: ['Conversations'],
    responses: [
        new OA\Response(
            response: 200,
            description: 'List of active mail accounts for filtering / display',
            content: new OA\JsonContent(
                type: 'array',
                items: new OA\Items(ref: new Model(type: MailAccountListItemDto::class))
            )
        )
    ],
    security: [ [ 'Bearer' => [] ] ]
)]
final readonly class ListMailAccountsController
{
    public function __construct(
        private ListMailAccountsForOperatorHandler $handler,
    ) {
    }

    #[Route('/api/v1/communication/mail-accounts', name: 'list_mail_accounts', methods: ['GET'])]
    #[IsGranted('conversation:read')]
    public function __invoke(): JsonResponse
    {
        $dtos = $this->handler->handle();
        $result = array_map(static fn (MailAccountListItemDto $dto): array => $dto->toArray(), $dtos);

        return new JsonResponse($result, Response::HTTP_OK);
    }
}
