<?php

declare(strict_types=1);

namespace App\Application\Communication;

use App\Application\Communication\Dto\MailAccountSecretDto;
use App\Domain\Communication\MailAccount;
use Doctrine\ORM\EntityManagerInterface;
use GuzzleHttp\Client as GuzzleClient;

class MailAccountSecretResolver
{
    public function __construct(private EntityManagerInterface $em)
    {
    }

    /**
     * Résout le login et le secret IMAP à partir d'un login_hash via Vault
     */
    public function resolveSecret(string $loginHash): MailAccountSecretDto
    {
        /** @var MailAccount|null $account */
        $account = $this->em->getRepository(MailAccount::class)
            ->findOneBy(['loginHash' => $loginHash, 'isActive' => true]);

        if (!$account) {
            throw new \RuntimeException('MailAccount not found or inactive');
        }

        // --- Vault integration ---
        $vaultAddr = $_ENV['VAULT_ADDR'] ?? 'http://vault:8200';
        $vaultToken = $_ENV['VAULT_TOKEN'] ?? '';
        $vaultPath = 'secret/data/scambuster/imap/' . $loginHash;

        $guzzle = new GuzzleClient(['base_uri' => $vaultAddr]);
        $resp = $guzzle->get('/v1/' . $vaultPath, [
            'headers' => [
                'X-Vault-Token' => $vaultToken,
            ],
        ]);
        /** @var array{data?: array{data?: array{login?: string, secret?: string}}} $data */
        $data = json_decode($resp->getBody()->getContents(), true);
        $secrets = $data['data']['data'] ?? null;

        if (!is_array($secrets) || !isset($secrets['login'], $secrets['secret'])) {
            throw new \RuntimeException('Vault secret missing login or secret');
        }

        return new MailAccountSecretDto(
            $secrets['login'],
            $secrets['secret'],
            $account->getProtocol(),
            $account->getEndpoint(),
            $account->getOauthScopes()
        );
    }
}
