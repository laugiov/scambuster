<?php

declare(strict_types=1);

namespace App\Application\Communication;

use App\Domain\Communication\MailAccount;
use App\UI\Http\Dto\MailAccountListItemDto;
use Doctrine\ORM\EntityManagerInterface;

final readonly class ListMailAccountsForOperatorHandler
{
    public function __construct(private EntityManagerInterface $em)
    {
    }

    /**
     * @return list<MailAccountListItemDto>
     */
    public function handle(): array
    {
        $repo = $this->em->getRepository(MailAccount::class);
        /** @var list<MailAccount> $accounts */
        $accounts = $repo->findBy(['isActive' => true], ['label' => 'ASC']);

        return array_map(
            static fn (MailAccount $a): MailAccountListItemDto => new MailAccountListItemDto(
                $a->getAccountId(),
                $a->getLabel(),
                $a->getEmailAddress(),
            ),
            $accounts,
        );
    }
}
