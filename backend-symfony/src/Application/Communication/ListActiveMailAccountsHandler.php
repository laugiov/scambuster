<?php

declare(strict_types=1);

namespace App\Application\Communication;

use App\Application\Communication\Dto\MailAccountActiveDto;
use App\Domain\Communication\MailAccount;
use Doctrine\ORM\EntityManagerInterface;

final class ListActiveMailAccountsHandler
{
    public function __construct(private EntityManagerInterface $em)
    {
    }

    /**
     * @return MailAccountActiveDto[]
     */
    public function handle(): array
    {
        $repo = $this->em->getRepository(MailAccount::class);
        $accounts = $repo->findBy([
            'isActive' => true,
            'protocol' => 'IMAP',
        ]);

        return array_map(function (MailAccount $acc) {
            return new MailAccountActiveDto(
                $acc->getAccountId(),
                $acc->getProtocol(),
                $acc->getEndpoint(),
                $acc->getLoginHash(),
                $acc->getOauthScopes(),
                $acc->getPort(),
                $acc->getSecure()
            );
        }, $accounts);
    }
}
