<?php

declare(strict_types=1);

namespace App\Tests\EndToEnd\Internal;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use App\Domain\Communication\MailAccount;
use Doctrine\ORM\EntityManagerInterface;

class MailAccountActiveE2eTest extends WebTestCase
{
    public function test_active_accounts_endpoint_e2e(): void
    {
        $accountId = uuid_create(UUID_TYPE_RANDOM);
        $loginHash = 'dummyhash-e2e';
        $mailAccount = new MailAccount(
            $accountId,
            '22222222-2222-2222-2222-222222222222',
            'IMAP',
            'imap.e2e.com',
            $loginHash,
            ['mail.read'],
            true,
            null,
            null,
            993,
            true
        );
        $client = static::createClient();
        $em = $client->getContainer()->get(EntityManagerInterface::class);
        $em->persist($mailAccount);
        $em->flush();

        $client->request('GET', '/internal/mail-account/active');
        $this->assertResponseIsSuccessful();
        $data = json_decode($client->getResponse()->getContent(), true);
        $this->assertIsArray($data);
        $found = false;
        foreach ($data as $item) {
            if ($item['login_hash'] === $loginHash) {
                $found = true;
                $this->assertSame('imap.e2e.com', $item['endpoint']);
                $this->assertSame('IMAP', $item['protocol']);
                $this->assertSame(['mail.read'], $item['oauth_scopes']);
                $this->assertSame(993, $item['port']);
                $this->assertTrue($item['secure']);
            }
        }
        $this->assertTrue($found, 'Inserted account should be present in the response');

        // Nettoyage
        $em->remove($mailAccount);
        $em->flush();
    }
} 