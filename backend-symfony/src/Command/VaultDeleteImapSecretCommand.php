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
    name: 'vault:imap-secret:delete',
    description: 'Delete an IMAP secret from Vault'
)]
class VaultDeleteImapSecretCommand extends Command
{
    public function __construct()
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('login_hash', InputArgument::REQUIRED, 'The login_hash of the account');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $vaultAddr = $_ENV['VAULT_ADDR'] ?? 'http://vault:8200';
        $vaultToken = $_ENV['VAULT_TOKEN'] ?? 'root';
        /** @var string $loginHash */
        $loginHash = $input->getArgument('login_hash');
        $vaultPath = 'secret/data/scambuster/imap/' . $loginHash;

        $guzzle = new GuzzleClient(['base_uri' => $vaultAddr]);

        try {
            $resp = $guzzle->delete('/v1/' . $vaultPath, [
                'headers' => [
                    'X-Vault-Token' => $vaultToken,
                ],
            ]);
        } catch (\Throwable $e) {
            $output->writeln('<error>Error while calling Vault: ' . $e->getMessage() . '</error>');

            return Command::FAILURE;
        }

        if (in_array($resp->getStatusCode(), [200, 204], true)) {
            $output->writeln('<info>Secret successfully deleted from Vault!</info>');

            return Command::SUCCESS;
        }

        $output->writeln('<error>Error while deleting the secret from Vault</error>');

        return Command::FAILURE;
    }
}
