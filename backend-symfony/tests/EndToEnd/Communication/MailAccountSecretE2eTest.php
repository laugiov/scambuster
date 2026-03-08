<?php

declare(strict_types=1);

namespace App\Tests\EndToEnd\Communication;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use App\Domain\Communication\MailAccount;
use Doctrine\ORM\EntityManagerInterface;

class MailAccountSecretE2eTest extends WebTestCase
{
    public function test_resolve_secret_e2e(): void
    {
        $loginHash = 'dummyhash';
        $accountId = uuid_create(UUID_TYPE_RANDOM);
        $mailAccount = new MailAccount(
            $accountId,
            '22222222-2222-2222-2222-222222222222',
            'IMAP',
            'imap.example.com',
            $loginHash,
            ['mail.read', 'mail.send'],
            true
        );
        $client = static::createClient();
        $em = $client->getContainer()->get(EntityManagerInterface::class);
        $em->persist($mailAccount);
        $em->flush();

        // Simuler la présence du secret dans Vault si besoin (à adapter selon l'environnement de test)
        // ...

        $client->request('GET', '/internal/mail-account/resolve-secret/' . $loginHash);
        $this->assertResponseIsSuccessful();
        $data = json_decode($client->getResponse()->getContent(), true);
        $this->assertSame('user@example.com', $data['login']);
        $this->assertSame('motdepasse123', $data['secret']);
        $this->assertSame('IMAP', $data['protocol']);
        $this->assertSame('imap.example.com', $data['endpoint']);
        $this->assertSame(['mail.read', 'mail.send'], $data['oauthScopes'] ?? $data['oauth_scopes']);

        // Nettoyage
        $em->remove($mailAccount);
        $em->flush();
    }

    public function test_resolve_secret_e2e_404(): void
    {
        $client = static::createClient();
        $client->request('GET', '/internal/mail-account/resolve-secret/unknownhash');
        $this->assertResponseStatusCodeSame(404);
        $data = json_decode($client->getResponse()->getContent(), true);
        $this->assertArrayHasKey('error', $data);
    }
} 