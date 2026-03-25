<?php

declare(strict_types=1);

namespace Tests\Integration\Application\Communication;

use App\Application\Communication\MailAccountSecretResolver;
use App\Application\Communication\Dto\MailAccountSecretDto;
use App\Domain\Communication\MailAccount;
use Doctrine\ORM\EntityManagerInterface;
use GuzzleHttp\Client;
use PHPUnit\Framework\TestCase;

class MailAccountSecretResolverTest extends TestCase
{
    public function test_resolve_secret_returns_dto_for_existing_account(): void
    {
        $loginHash = 'dummyhash';

        // Create the secret in Vault before testing
        $guzzle = new Client(['base_uri' => $_ENV['VAULT_ADDR'] ?? 'http://vault:8200']);
        $guzzle->post('/v1/secret/data/scambuster/imap/dummyhash', [
            'headers' => ['X-Vault-Token' => $_ENV['VAULT_TOKEN'] ?? 'root'],
            'json' => ['data' => ['login' => 'user@example.com', 'secret' => 'motdepasse123']]
        ]);
        $mailAccount = new MailAccount(
            '11111111-1111-1111-1111-111111111111',
            '22222222-2222-2222-2222-222222222222',
            'IMAP',
            'imap.example.com',
            $loginHash,
            ['mail.read', 'mail.send'],
            true
        );

        $repo = $this->createMock(\Doctrine\ORM\EntityRepository::class);
        $repo->expects($this->once())
            ->method('findOneBy')
            ->with(['loginHash' => $loginHash, 'isActive' => true])
            ->willReturn($mailAccount);

        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects($this->once())
            ->method('getRepository')
            ->with(MailAccount::class)
            ->willReturn($repo);

        $resolver = new MailAccountSecretResolver($em);
        $dto = $resolver->resolveSecret($loginHash);

        $this->assertInstanceOf(MailAccountSecretDto::class, $dto);
        $this->assertSame('user@example.com', $dto->login);
        $this->assertSame('motdepasse123', $dto->secret);
        $this->assertSame('IMAP', $dto->protocol);
        $this->assertSame('imap.example.com', $dto->endpoint);
        $this->assertSame(['mail.read', 'mail.send'], $dto->oauthScopes);
    }

    public function test_resolve_secret_throws_if_account_not_found(): void
    {
        $repo = $this->createMock(\Doctrine\ORM\EntityRepository::class);
        $repo->expects($this->once())
            ->method('findOneBy')
            ->willReturn(null);

        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects($this->once())
            ->method('getRepository')
            ->willReturn($repo);

        $resolver = new MailAccountSecretResolver($em);
        $this->expectException(\RuntimeException::class);
        $resolver->resolveSecret('notfound');
    }
} 