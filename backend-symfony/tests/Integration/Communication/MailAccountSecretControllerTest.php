<?php

declare(strict_types=1);

namespace App\Tests\Integration\Communication;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use App\Domain\Communication\MailAccount;
use Doctrine\ORM\EntityManagerInterface;
use GuzzleHttp\Client;

class MailAccountSecretControllerTest extends WebTestCase
{
    public function test_resolve_secret_returns_dto_for_existing_account(): void
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

        // Create the secret in Vault before calling the API, with the correct KV v2 structure
        $guzzle = new Client(['base_uri' => $_ENV['VAULT_ADDR'] ?? 'http://vault:8200']);
        $guzzle->post('/v1/secret/data/scambuster/imap/dummyhash', [
            'headers' => ['X-Vault-Token' => $_ENV['VAULT_TOKEN'] ?? 'root'],
            'json' => ['data' => ['login' => 'user@example.com', 'secret' => 'motdepasse123']]
        ]);

        $client->request('GET', '/api/v1/internal/mail-account/resolve-secret/' . $loginHash, [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer fake-admin-jwt',
        ]);
        $this->assertResponseIsSuccessful();
        $data = json_decode($client->getResponse()->getContent(), true);
        $this->assertSame('user@example.com', $data['login']);
        $this->assertSame('motdepasse123', $data['secret']);
        $this->assertSame('IMAP', $data['protocol']);
        $this->assertSame('imap.example.com', $data['endpoint']);
        $this->assertSame(['mail.read', 'mail.send'], $data['oauth_scopes']);

        // Nettoyage
        $em->remove($mailAccount);
        $em->flush();
    }

    public function test_resolve_secret_returns_404_for_unknown_hash(): void
    {
        $client = static::createClient();
        $client->request('GET', '/api/v1/internal/mail-account/resolve-secret/unknownhash', [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer fake-admin-jwt',
        ]);
        $this->assertResponseStatusCodeSame(404);
        $data = json_decode($client->getResponse()->getContent(), true);
        $this->assertArrayHasKey('error', $data);
    }
} 