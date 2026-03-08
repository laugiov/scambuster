<?php

declare(strict_types=1);

namespace App\UI\Http\Internal;

use App\Application\Communication\ListActiveMailAccountsHandler;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Annotation\Route;

#[Route('/internal/mail-account/active', name: 'internal_mail_account_active', methods: ['GET'])]
final class MailAccountActiveController
{
    public function __construct(private readonly ListActiveMailAccountsHandler $handler)
    {
    }

    public function __invoke(): JsonResponse
    {
        $accounts = $this->handler->handle();
        $result = array_map(fn ($dto) => $dto->toArray(), $accounts);

        return new JsonResponse($result);
    }
}
