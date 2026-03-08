<?php

declare(strict_types=1);

namespace App\Command;

use App\Domain\Communication\MailAccount;
use App\Service\LoginHashGenerator;
use Doctrine\ORM\EntityManagerInterface;
use GuzzleHttp\Client as GuzzleClient;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'mail-account:onboard',
    description: 'Onboard a new IMAP mail account: create DB entry and Vault secret.'
)]
class MailAccountOnboardCommand extends Command
{
    public function __construct(
        private EntityManagerInterface $em,
        private LoginHashGenerator $loginHashGenerator
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('login', null, InputOption::VALUE_REQUIRED, 'IMAP login (email or username)')
            ->addOption('password', null, InputOption::VALUE_REQUIRED, 'IMAP password or token')
            ->addOption('endpoint', null, InputOption::VALUE_REQUIRED, 'IMAP server endpoint (host)')
            ->addOption('owner-id', null, InputOption::VALUE_REQUIRED, 'Owner UUID')
            ->addOption('protocol', null, InputOption::VALUE_OPTIONAL, 'Protocol', 'IMAP')
            ->addOption('scopes', null, InputOption::VALUE_OPTIONAL, 'OAuth scopes (JSON array)', '["mail.read"]')
            ->addOption('active', null, InputOption::VALUE_OPTIONAL, 'Is active', 'true')
            ->addOption('port', null, InputOption::VALUE_OPTIONAL, 'IMAP port (optional)', null)
            ->addOption('secure', null, InputOption::VALUE_OPTIONAL, 'IMAP secure (true/false, optional)', null);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $login = $input->getOption('login');
        $password = $input->getOption('password');
        $endpoint = $input->getOption('endpoint');
        $ownerId = $input->getOption('owner-id');
        $protocol = $input->getOption('protocol') ?? 'IMAP';
        $scopes = json_decode($input->getOption('scopes') ?? '["mail.read"]', true);
        $isActive = filter_var($input->getOption('active'), FILTER_VALIDATE_BOOLEAN);
        $port = $input->getOption('port') !== null ? (int)$input->getOption('port') : null;
        $secure = $input->getOption('secure') !== null ? filter_var($input->getOption('secure'), FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) : null;

        if (!$login || !$password || !$endpoint || !$ownerId) {
            $output->writeln('<error>login, password, endpoint, and owner-id are required.</error>');

            return Command::FAILURE;
        }

        $loginHash = $this->loginHashGenerator->generate($login);
        $accountId = uuid_create(UUID_TYPE_RANDOM);

        // Check if login_hash already exists
        $repo = $this->em->getRepository(MailAccount::class);

        if ($repo->findOneBy(['loginHash' => $loginHash])) {
            $output->writeln('<error>This login_hash already exists in DB.</error>');

            return Command::FAILURE;
        }

        $mailAccount = new MailAccount(
            $accountId,
            $ownerId,
            $protocol,
            $endpoint,
            $loginHash,
            $scopes,
            $isActive,
            null,
            null,
            $port,
            $secure
        );
        $this->em->persist($mailAccount);
        $this->em->flush();

        // Add secret to Vault (KV v2)
        $vaultAddr = $_ENV['VAULT_ADDR'] ?? 'http://vault:8200';
        $vaultToken = $_ENV['VAULT_TOKEN'] ?? 'root';
        $vaultPath = 'secret/data/scambuster/imap/' . $loginHash;
        $guzzle = new GuzzleClient(['base_uri' => $vaultAddr]);
        $payload = [
            'data' => [
                'login' => $login,
                'secret' => $password,
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

        if (!in_array($resp->getStatusCode(), [200, 204], true)) {
            $output->writeln('<error>Error while adding the secret to Vault</error>');

            return Command::FAILURE;
        }

        $output->writeln('<info>Mail account onboarded successfully!</info>');
        $output->writeln("Account ID: $accountId");
        $output->writeln("Login hash: $loginHash");
        $output->writeln("Endpoint: $endpoint");
        $output->writeln("Protocol: $protocol");
        $output->writeln('Scopes: ' . json_encode($scopes));
        $output->writeln('Active: ' . ($isActive ? 'true' : 'false'));
        $output->writeln("Secret stored in Vault at: secret/data/scambuster/imap/$loginHash");

        return Command::SUCCESS;
    }
}
