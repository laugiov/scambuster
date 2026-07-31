<?php

declare(strict_types=1);

namespace App\Application\Communication;

use App\Application\Communication\Dto\ResolvedReferences;
use App\Domain\Communication\MailAccount;
use App\Domain\Communication\Repository\ChannelRepositoryInterface;
use App\Domain\Communication\Repository\DirectionRepositoryInterface;
use App\Domain\Communication\Repository\MailAccountRepositoryInterface;
use Psr\Log\LoggerInterface;

/**
 * Resolves reference entities needed by the ingestion pipeline.
 *
 * Uses Domain repository interfaces instead of EntityManager.
 * Stateless service: safe to inject as a singleton.
 */
final readonly class EntityReferenceResolver
{
    public function __construct(
        private MailAccountRepositoryInterface $mailAccountRepo,
        private ChannelRepositoryInterface $channelRepo,
        private DirectionRepositoryInterface $directionRepo,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * Resolve the MailAccount, Channel, and Direction entities for a given
     * ingest request.
     *
     * The account is resolved from the explicit id when provided, otherwise
     * from the inbound recipient addresses. Deriving the account from the
     * recipient lets an operator plug in a new mailbox by configuration alone.
     *
     * @param list<string> $recipientEmails Candidate recipient addresses (To, Cc,
     *                                      Delivered-To, …), tried in order.
     *
     * @throws \RuntimeException if any reference entity is not found
     */
    public function resolve(string $accountId, string $channelCode = 'email', array $recipientEmails = []): ResolvedReferences
    {
        $account = $this->resolveAccount($accountId, $recipientEmails);

        $channel = $this->channelRepo->findByCode($channelCode);

        if (!$channel instanceof \App\Domain\Communication\Channel) {
            $this->logger->error('[EntityReferenceResolver] Unknown channel', ['channel' => $channelCode]);

            throw new \RuntimeException('Unknown channel');
        }

        $direction = $this->directionRepo->findByCode('in');

        if (!$direction instanceof \App\Domain\Communication\Direction) {
            $this->logger->error('[EntityReferenceResolver] Unknown direction');

            throw new \RuntimeException('Unknown direction');
        }

        return new ResolvedReferences($account, $channel, $direction);
    }

    /**
     * Resolve the mail account from an explicit id or, failing that, from the
     * inbound recipient addresses.
     *
     * @param list<string> $recipientEmails
     *
     * @throws \RuntimeException if no account can be resolved
     */
    private function resolveAccount(string $accountId, array $recipientEmails): MailAccount
    {
        $accountId = trim($accountId);

        if ($accountId !== '') {
            $account = $this->mailAccountRepo->findById($accountId);

            if ($account instanceof MailAccount) {
                return $account;
            }
        }

        foreach ($recipientEmails as $recipientEmail) {
            $recipientEmail = trim($recipientEmail);

            if ($recipientEmail === '') {
                continue;
            }

            $account = $this->mailAccountRepo->findByEmail($recipientEmail);

            if ($account instanceof MailAccount) {
                return $account;
            }
        }

        $this->logger->error('[EntityReferenceResolver] Unable to resolve mail account', [
            'account_id' => $accountId,
            'recipients' => $recipientEmails,
        ]);

        throw new \RuntimeException('Unknown account_id');
    }
}
