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

    /**
     * @return list<MailAccount>
     */
    public function findAll(): array
    {
        /** @var list<MailAccount> $accounts */
        $accounts = $this->em->getRepository(MailAccount::class)->findBy([], ['createdAt' => 'ASC']);

        return $accounts;
    }

    public function save(MailAccount $account): void
    {
        $this->em->persist($account);
        $this->em->flush();
    }
}
