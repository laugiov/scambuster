<?php

declare(strict_types=1);

namespace App\UI\Http\Internal;

use App\Application\Communication\ListActiveMailAccountsHandler;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/api/v1/internal/mail-account/active', name: 'internal_mail_account_active', methods: ['GET'])]
#[IsGranted('ROLE_ADMIN')]
final readonly class MailAccountActiveController
{
    public function __construct(private ListActiveMailAccountsHandler $handler)
    {
    }
    public function __invoke(): JsonResponse
    {
        $accounts = $this->handler->handle();
        $result = array_map(fn ($dto): array => $dto->toArray(), $accounts);

        return new JsonResponse($result);
    }
}
