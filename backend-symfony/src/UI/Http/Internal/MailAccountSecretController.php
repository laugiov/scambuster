<?php

declare(strict_types=1);

namespace App\UI\Http\Internal;

use App\Application\Communication\Dto\MailAccountSecretDto;
use App\Application\Communication\MailAccountSecretResolver;
use Nelmio\ApiDocBundle\Attribute\Model;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Endpoint to resolve IMAP/SMTP credentials for a mail account (internal use).
 */
#[Route('/api/v1/internal/mail-account/resolve-secret/{login_hash}', name: 'internal_mail_account_resolve_secret', methods: ['GET'])]
#[IsGranted('ROLE_ADMIN')]
#[OA\Get(
    path: '/api/v1/internal/mail-account/resolve-secret/{login_hash}',
    summary: 'Résoudre les credentials IMAP/SMTP pour un compte mail (usage interne)',
    tags: ['Internal'],
    parameters: [
        new OA\Parameter(name: 'login_hash', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
    ],
    responses: [
        new OA\Response(
            response: 200,
            description: 'Credentials résolus',
            content: new OA\JsonContent(ref: new Model(type: MailAccountSecretDto::class))
        ),
        new OA\Response(
            response: 404,
            description: 'Aucun secret trouvé',
            content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'error', type: 'string')])
        )
    ],
    security: [ [ 'Bearer' => [] ] ]
)]
final class MailAccountSecretController
{
    public function __construct(private MailAccountSecretResolver $resolver)
    {
    }

    /**
     * Returns the credentials and connection info for a given login_hash.
     */
    public function __invoke(string $login_hash): JsonResponse
    {
        try {
            $dto = $this->resolver->resolveSecret($login_hash);

            return new JsonResponse($dto->toArray());
        } catch (\Throwable $e) {
            return new JsonResponse([
                'error' => $e->getMessage(),
            ], Response::HTTP_NOT_FOUND);
        }
    }
}
