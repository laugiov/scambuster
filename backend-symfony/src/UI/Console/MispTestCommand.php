<?php

declare(strict_types=1);

namespace App\UI\Console;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Contracts\HttpClient\HttpClientInterface;

#[AsCommand(
    name: 'scambuster:misp:test',
    description: 'Test connectivity to a MISP instance'
)]
class MispTestCommand extends Command
{
    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly ?string $mispUrl = null,
        private readonly ?string $mispApiKey = null
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        /** @var string $envUrl */
        $envUrl = $_ENV['MISP_URL'] ?? '';
        /** @var string $envKey */
        $envKey = $_ENV['MISP_API_KEY'] ?? '';
        $url = $this->mispUrl ?: $envUrl;
        $apiKey = $this->mispApiKey ?: $envKey;

        if (empty($url) || empty($apiKey)) {
            $io->warning('MISP is not configured.');
            $io->text('Set MISP_URL and MISP_API_KEY in your .env file.');
            $io->text('');
            $io->text('Example:');
            $io->text('  MISP_URL=https://your-misp.example.com');
            $io->text('  MISP_API_KEY=your-api-key');

            return Command::SUCCESS;
        }

        $io->title('MISP Connection Test');
        $io->text("URL: {$url}");

        try {
            $response = $this->httpClient->request('GET', rtrim($url, '/') . '/servers/getVersion', [
                'headers' => [
                    'Authorization' => $apiKey,
                    'Accept' => 'application/json',
                ],
                'verify_peer' => ($_ENV['MISP_VERIFY_SSL'] ?? 'true') !== 'false',
                'timeout' => 10,
            ]);

            $statusCode = $response->getStatusCode();

            if ($statusCode === 200) {
                $data = $response->toArray();
                $version = $data['version'] ?? 'unknown';
                $io->text("Version: {$version}");
                $io->success('Connection successful.');

                return Command::SUCCESS;
            }

            $io->error("MISP returned HTTP {$statusCode}");

            if ($statusCode === 401 || $statusCode === 403) {
                $io->text('Check that your MISP_API_KEY is valid and has sufficient permissions.');
            }

            return Command::FAILURE;
        } catch (\Throwable $e) {
            $io->error('Connection failed: ' . $e->getMessage());
            $io->text('Verify that MISP_URL is correct and the instance is reachable.');

            return Command::FAILURE;
        }
    }
}
