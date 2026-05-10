<?php

declare(strict_types=1);

namespace App\Application\Communication;

use App\Application\Communication\Smtp\SmtpDsnEncryptor;
use App\Domain\Communication\MailAccount;
use App\Domain\Communication\Repository\MailAccountRepositoryInterface;
use App\Domain\Communication\ValueObject\EmailAddress;
use App\Domain\Communication\ValueObject\SmtpDsn;

/**
 * Application service for the lifecycle of MailAccount entities.
 *
 * Encapsulates encryption, validation, and persistence behind a clean API
 * called by CLI commands.
 *
 * Security: NEVER returns the decrypted SMTP DSN. Only `has_custom_smtp: bool`.
 */
final readonly class MailAccountManager
{
    public function __construct(
        private MailAccountRepositoryInterface $repository,
        private SmtpDsnEncryptor $encryptor,
    ) {
    }

    /**
     * Create a new mail account.
     *
     * @param string      $ownerId  UUID of the owner (existing user/system identity)
     * @param string      $email    Reply-from email address
     * @param string|null $smtpDsn  Per-account SMTP DSN (encrypted before storage). Null = use global MAILER_DSN.
     * @param string|null $label    Operator-friendly internal name
     * @param string      $endpoint IMAP host (currently the legacy field, also used as fingerprint)
     *
     * @return string the new account_id (UUID)
     *
     * @throws \InvalidArgumentException on invalid email or DSN
     */
    public function addAccount(
        string $ownerId,
        string $email,
        ?string $smtpDsn,
        ?string $label,
        string $endpoint,
    ): string {
        $emailVo = new EmailAddress($email);

        $encryptedDsn = null;
        if ($smtpDsn !== null && $smtpDsn !== '') {
            // Validate DSN format before encrypting
            new SmtpDsn($smtpDsn);
            $encryptedDsn = $this->encryptor->encrypt($smtpDsn);
        }

        $accountId = uuid_create(\UUID_TYPE_RANDOM);

        $account = new MailAccount(
            $accountId,
            $ownerId,
            'IMAP',
            $endpoint,
            'login-hash-' . substr($accountId, 0, 8),
            [],
        );
        $account->setEmailAddress((string) $emailVo);
        $account->setSmtpDsnEncrypted($encryptedDsn);
        $account->setLabel($label);

        $this->repository->save($account);

        return $accountId;
    }

    /**
     * List all mail accounts as DTOs safe for display (NO decrypted DSN).
     *
     * @return list<array{account_id: string, email: ?string, label: ?string, has_custom_smtp: bool, is_active: bool, endpoint: string}>
     */
    public function listAccounts(): array
    {
        $rows = [];

        foreach ($this->repository->findAll() as $account) {
            $rows[] = [
                'account_id' => $account->getAccountId(),
                'email' => $account->getEmailAddress(),
                'label' => $account->getLabel(),
                'has_custom_smtp' => $account->hasCustomSmtp(),
                'is_active' => $account->isActive(),
                'endpoint' => $account->getEndpoint(),
            ];
        }

        return $rows;
    }

    /**
     * Soft-delete by setting is_active = false. Does not touch the encrypted DSN
     * so the account can be re-enabled later without re-providing credentials.
     *
     * @throws \RuntimeException if account does not exist
     */
    public function disableAccount(string $accountId): void
    {
        $account = $this->repository->findById($accountId);

        if (!$account instanceof MailAccount) {
            throw new \RuntimeException(sprintf('Mail account %s not found', $accountId));
        }

        // is_active is set via reflection since the entity has no setter (Spec 050: minimal changes)
        $reflection = new \ReflectionProperty(MailAccount::class, 'isActive');
        $reflection->setValue($account, false);

        $this->repository->save($account);
    }

    /**
     * Replace the SMTP DSN for an existing account. The new DSN is encrypted
     * with a fresh nonce, so the stored ciphertext changes even if the DSN is unchanged.
     *
     * @throws \InvalidArgumentException on invalid DSN
     * @throws \RuntimeException         if account does not exist
     */
    public function rotateSmtp(string $accountId, string $newSmtpDsn): void
    {
        $account = $this->repository->findById($accountId);

        if (!$account instanceof MailAccount) {
            throw new \RuntimeException(sprintf('Mail account %s not found', $accountId));
        }

        // Validate before encrypting
        new SmtpDsn($newSmtpDsn);

        $account->setSmtpDsnEncrypted($this->encryptor->encrypt($newSmtpDsn));
        $this->repository->save($account);
    }
}
