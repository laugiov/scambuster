<?php

declare(strict_types=1);

namespace App\Domain\Communication\Repository;

use App\Domain\Communication\MailAccount;

interface MailAccountRepositoryInterface
{
    public function findById(string $id): ?MailAccount;
}
