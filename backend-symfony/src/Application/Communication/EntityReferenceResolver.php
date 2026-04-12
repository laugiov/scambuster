<?php

declare(strict_types=1);

namespace App\Application\Communication;

use App\Application\Communication\Dto\ResolvedReferences;
use App\Domain\Communication\Repository\ChannelRepositoryInterface;
use App\Domain\Communication\Repository\DirectionRepositoryInterface;
use App\Domain\Communication\Repository\MailAccountRepositoryInterface;
use Psr\Log\LoggerInterface;

/**
 * Spec 065h — Resolves reference entities needed by the ingestion pipeline.
 *
 * Extracted from IngestHandler::ingest() lines 50-73.
 * Spec 066d — Uses Domain repository interfaces instead of EntityManager.
 * Stateless service: safe to inject as a singleton.
 */
final class EntityReferenceResolver
{
    public function __construct(
        private readonly MailAccountRepositoryInterface $mailAccountRepo,
        private readonly ChannelRepositoryInterface $channelRepo,
        private readonly DirectionRepositoryInterface $directionRepo,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Resolve the MailAccount, Channel, and Direction entities for a given
     * ingest request.
     *
     * @throws \RuntimeException if any reference entity is not found
     */
    public function resolve(string $accountId, string $channelCode = 'email'): ResolvedReferences
    {
        $account = $this->mailAccountRepo->findById($accountId);

        if (!$account) {
            $this->logger->error('[EntityReferenceResolver] Unknown account_id', ['account_id' => $accountId]);

            throw new \RuntimeException('Unknown account_id');
        }

        $channel = $this->channelRepo->findByCode($channelCode);

        if (!$channel) {
            $this->logger->error('[EntityReferenceResolver] Unknown channel', ['channel' => $channelCode]);

            throw new \RuntimeException('Unknown channel');
        }

        $direction = $this->directionRepo->findByCode('in');

        if (!$direction) {
            $this->logger->error('[EntityReferenceResolver] Unknown direction');

            throw new \RuntimeException('Unknown direction');
        }

        return new ResolvedReferences($account, $channel, $direction);
    }
}
