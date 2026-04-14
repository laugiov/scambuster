<?php

declare(strict_types=1);

namespace App\Infrastructure\Doctrine\Repository;

use App\Domain\Communication\MailAccount;
use App\Domain\Communication\Repository\MailAccountRepositoryInterface;
use Doctrine\ORM\EntityManagerInterface;

final readonly class DoctrineMailAccountRepository implements MailAccountRepositoryInterface
{
    public function __construct(
        private EntityManagerInterface $em,
    ) {
    }

    public function findById(string $id): ?MailAccount
    {
        return $this->em->getRepository(MailAccount::class)->find($id);
    }
}
