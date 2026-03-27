<?php

declare(strict_types=1);

namespace App\Tests\EndToEnd\Communication;

use App\Domain\Communication\MailAccount;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * @group ci-skip
 */
class MailAccountSecretEndToEndTest extends WebTestCase
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

        $jwt = $this->getAdminJwt($client);

        $client->request('GET', '/api/v1/internal/mail-account/resolve-secret/' . $loginHash, [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer ' . $jwt,
        ]);
        $this->assertResponseIsSuccessful();
        $data = json_decode($client->getResponse()->getContent(), true);
        $this->assertSame('user@example.com', $data['login']);
        $this->assertSame('motdepasse123', $data['secret']);
        $this->assertSame('IMAP', $data['protocol']);
        $this->assertSame('imap.example.com', $data['endpoint']);
        $this->assertSame(['mail.read', 'mail.send'], $data['oauth_scopes']);

        $em = $client->getContainer()->get(EntityManagerInterface::class);
        $managed = $em->find(MailAccount::class, $accountId);
        if ($managed !== null) {
            $em->remove($managed);
            $em->flush();
        }
    }

    public function test_resolve_secret_e2e_404(): void
    {
        $client = static::createClient();
        $jwt = $this->getAdminJwt($client);

        $client->request('GET', '/api/v1/internal/mail-account/resolve-secret/unknownhash', [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer ' . $jwt,
        ]);
        $this->assertResponseStatusCodeSame(404);
        $data = json_decode($client->getResponse()->getContent(), true);
        $this->assertArrayHasKey('error', $data);
    }

    private function getAdminJwt($client): string
    {
        $client->request('POST', '/api/v1/auth/login', [], [], ['CONTENT_TYPE' => 'application/json'], json_encode([
            'email' => 'admin@example.com',
            'password' => 'Un1que$trongPassword2024',
        ]));
        $data = json_decode($client->getResponse()->getContent(), true);

        return $data['access_token'] ?? '';
    }
}
