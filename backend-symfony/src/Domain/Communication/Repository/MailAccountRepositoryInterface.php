<?php

declare(strict_types=1);

namespace App\Domain\Communication\Repository;

use App\Domain\Communication\MailAccount;

interface MailAccountRepositoryInterface
{
    public function findById(string $id): ?MailAccount;

    /**
     * Find the active mail account whose email address matches (case-insensitive).
     * Lets an operator add mailboxes by configuration alone: the ingest pipeline
     * derives the account from the inbound message's recipient address instead of
     * a per-mailbox account id hardcoded in the workflow.
     */
    public function findByEmail(string $email): ?MailAccount;

    /**
     * @return list<MailAccount>
     */
    public function findAll(): array;

    public function save(MailAccount $account): void;
}
