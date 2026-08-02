<?php

declare(strict_types=1);

namespace App\DataFixtures\Communication;

use App\Domain\Communication\MailAccount;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Bundle\FixturesBundle\FixtureGroupInterface;
use Doctrine\Persistence\ObjectManager;

class MailAccountFixtures extends Fixture implements FixtureGroupInterface
{
    /** Reference/lookup data - loadable on its own for the lightweight demo seed. */
    public static function getGroups(): array
    {
        return ['reference'];
    }

    public function load(ObjectManager $manager): void
    {
        // Bind the active honeypot mailbox to its reply-from address so inbound
        // mail resolves to it out of the box (the backend derives the account
        // from each message's recipient). Sourced from the operator's .env; when
        // unset (test/e2e/demo) the address stays null and behaviour is unchanged.
        $honeypotUser = $_ENV['HONEYPOT_IMAP_USER'] ?? getenv('HONEYPOT_IMAP_USER');
        $honeypotEmail = \is_string($honeypotUser) && $honeypotUser !== '' ? $honeypotUser : null;

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

            if ($data['is_active'] && $honeypotEmail !== null) {
                $mailAccount->setEmailAddress($honeypotEmail);
            }
            $manager->persist($mailAccount);
        }

        $manager->flush();
    }
}
