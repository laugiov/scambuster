<?php

declare(strict_types=1);

namespace App\DataFixtures\Communication;

use App\Domain\Communication\MailAccount;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Persistence\ObjectManager;

class MailAccountFixtures extends Fixture
{
    public function load(ObjectManager $manager): void
    {
        $mailAccounts = [
            [
                'account_id' => '11111111-1111-1111-1111-111111111111',
                'owner_id' => '22222222-2222-2222-2222-222222222222',
                'protocol' => 'IMAP',
                'endpoint' => 'imap.example.com',
                'login_hash' => 'dummyhash',
                'oauth_scopes' => ['mail.read', 'mail.send'],
                'is_active' => false,
            ],
            [
                'account_id' => '12b3f7b4-8fb1-4830-82d5-58d7fd874d2a',
                'owner_id' => '22222222-2222-2222-2222-222222222222',
                'protocol' => 'IMAP',
                'endpoint' => 'imap.gmail.com',
                'login_hash' => 'n8n-gmail-account',
                'oauth_scopes' => ['mail.read', 'mail.send'],
                'is_active' => true,
            ],
        ];

        foreach ($mailAccounts as $data) {
            $mailAccount = new MailAccount(
                $data['account_id'],
                $data['owner_id'],
                $data['protocol'],
                $data['endpoint'],
                $data['login_hash'],
                $data['oauth_scopes'],
                $data['is_active']
            );
            $manager->persist($mailAccount);
        }

        $manager->flush();
    }
}
