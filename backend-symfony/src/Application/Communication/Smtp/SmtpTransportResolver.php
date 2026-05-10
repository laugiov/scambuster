<?php

declare(strict_types=1);

namespace App\Application\Communication\Smtp;

use App\Domain\Communication\MailAccount;
use App\Infrastructure\Mailer\TransportFactory;
use Symfony\Component\Mailer\Mailer;
use Symfony\Component\Mailer\MailerInterface;

/**
 * Resolves the correct outbound mailer for a given mail account.
 *
 * Strategy:
 * - Account has a custom encrypted SMTP DSN → decrypt + create dedicated transport
 * - Account has no custom DSN → return the default MailerInterface (global MAILER_DSN)
 *
 * Caches resolved mailers by account_id within the request lifecycle to avoid
 * recreating SMTP connections for multiple replies on the same account.
 *
 * Security:
 * - Decryption failure throws RuntimeException — NEVER falls back silently to global SMTP
 * - This prevents leaking outbound traffic to the wrong relay if the encrypted DSN is corrupted
 */
final class SmtpTransportResolver
{
    /** @var array<string, MailerInterface> */
    private array $cache = [];

    public function __construct(
        private readonly SmtpDsnEncryptor $encryptor,
        private readonly TransportFactory $transportFactory,
        private readonly MailerInterface $defaultMailer,
    ) {
    }

    /**
     * Returns the MailerInterface to use for sending messages on this account.
     *
     * @throws \RuntimeException if the encrypted DSN cannot be decrypted or is invalid
     */
    public function resolveForAccount(MailAccount $account): MailerInterface
    {
        if (!$account->hasCustomSmtp()) {
            return $this->defaultMailer;
        }

        $accountId = $account->getAccountId();

        if (isset($this->cache[$accountId])) {
            return $this->cache[$accountId];
        }

        $encrypted = $account->getSmtpDsnEncrypted();
        \assert($encrypted !== null);

        try {
            $dsn = $this->encryptor->decrypt($encrypted);
        } catch (\RuntimeException $e) {
            throw new \RuntimeException(
                sprintf('Failed to decrypt SMTP DSN for account %s', $accountId),
                previous: $e,
            );
        }

        try {
            $transport = $this->transportFactory->fromDsn($dsn);
        } catch (\InvalidArgumentException $e) {
            throw new \RuntimeException(
                sprintf('Invalid decrypted SMTP DSN for account %s', $accountId),
                previous: $e,
            );
        }

        $mailer = new Mailer($transport);
        $this->cache[$accountId] = $mailer;

        return $mailer;
    }
}
