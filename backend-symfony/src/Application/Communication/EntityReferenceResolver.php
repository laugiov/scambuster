<?php

declare(strict_types=1);

namespace App\Application\Communication;

use App\Application\Communication\Dto\ResolvedReferences;
use App\Domain\Communication\Channel;
use App\Domain\Communication\Direction;
use App\Domain\Communication\MailAccount;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Spec 065h — Resolves reference entities needed by the ingestion pipeline.
 *
 * Extracted from IngestHandler::ingest() lines 50-73.
 * Stateless service: safe to inject as a singleton.
 */
final class EntityReferenceResolver
{
    public function __construct(
        private readonly EntityManagerInterface $em,
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
        $account = $this->em->getRepository(MailAccount::class)->find($accountId);

        if (!$account) {
            $this->logger->error('[EntityReferenceResolver] Unknown account_id', ['account_id' => $accountId]);

            throw new \RuntimeException('Unknown account_id');
        }

        $channel = $this->em->getRepository(Channel::class)->findOneBy(['code' => $channelCode]);

        if (!$channel) {
            $this->logger->error('[EntityReferenceResolver] Unknown channel', ['channel' => $channelCode]);

            throw new \RuntimeException('Unknown channel');
        }

        $direction = $this->em->getRepository(Direction::class)->findOneBy(['code' => 'in']);

        if (!$direction) {
            $this->logger->error('[EntityReferenceResolver] Unknown direction');

            throw new \RuntimeException('Unknown direction');
        }

        return new ResolvedReferences($account, $channel, $direction);
    }
}
