<?php

declare(strict_types=1);

namespace App\Command;

use GuzzleHttp\Client as GuzzleClient;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'vault:imap-secret:add',
    description: 'Add or update an IMAP secret in Vault'
)]
class VaultAddImapSecretCommand extends Command
{
    public function __construct()
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('login_hash', InputArgument::REQUIRED, 'The login_hash of the account')
            ->addArgument('login', InputArgument::REQUIRED, 'The IMAP login')
            ->addArgument('secret', InputArgument::REQUIRED, 'The IMAP password or token');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $vaultAddr = $_ENV['VAULT_ADDR'] ?? 'http://vault:8200';
        $vaultToken = $_ENV['VAULT_TOKEN'] ?? 'root';
        $vaultPath = 'secret/data/scambuster/imap/' . $input->getArgument('login_hash');

        $guzzle = new GuzzleClient(['base_uri' => $vaultAddr]);
        $payload = [
            'data' => [
                'login' => $input->getArgument('login'),
                'secret' => $input->getArgument('secret'),
            ]
        ];

        try {
            $resp = $guzzle->post('/v1/' . $vaultPath, [
                'headers' => [
                    'X-Vault-Token' => $vaultToken,
                ],
                'json' => $payload,
            ]);
        } catch (\Throwable $e) {
            $output->writeln('<error>Error while calling Vault: ' . $e->getMessage() . '</error>');

            return Command::FAILURE;
        }

        if (in_array($resp->getStatusCode(), [200, 204], true)) {
            $output->writeln('<info>Secret successfully added/updated in Vault!</info>');

            return Command::SUCCESS;
        }

        $output->writeln('<error>Error while adding the secret to Vault</error>');

        return Command::FAILURE;
    }
}
