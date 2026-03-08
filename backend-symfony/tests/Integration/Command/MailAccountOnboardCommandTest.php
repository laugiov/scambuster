<?php

declare(strict_types=1);

namespace App\Tests\Integration\Command;

use App\Domain\Communication\MailAccount;
use Doctrine\ORM\EntityManagerInterface;
use GuzzleHttp\Client as GuzzleClient;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Console\Application;
use App\Command\MailAccountOnboardCommand;

class MailAccountOnboardCommandTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private GuzzleClient $guzzle;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        $this->guzzle = new GuzzleClient(['base_uri' => $_ENV['VAULT_ADDR'] ?? 'http://vault:8200']);
    }

    public function test_onboard_creates_mail_account_and_vault_secret(): void
    {
        $login = 'integration-test@example.com';
        $password = 'integration-password';
        $endpoint = 'imap.integration-test.com';
        $ownerId = '11111111-1111-1111-1111-111111111111';
        $scopes = '["mail.read"]';
        $protocol = 'IMAP';
        $isActive = 'true';

        $application = new Application();
        $command = self::getContainer()->get(MailAccountOnboardCommand::class);
        $application->add($command);
        $commandTester = new CommandTester($command);

        $commandTester->execute([
            '--login' => $login,
            '--password' => $password,
            '--endpoint' => $endpoint,
            '--owner-id' => $ownerId,
            '--protocol' => $protocol,
            '--scopes' => $scopes,
            '--active' => $isActive,
        ]);

        $output = $commandTester->getDisplay();
        $this->assertStringContainsString('Mail account onboarded successfully!', $output);

        // Vérifier la présence en BDD
        $repo = $this->em->getRepository(MailAccount::class);
        $mailAccount = $repo->findOneBy(['endpoint' => $endpoint]);
        $this->assertNotNull($mailAccount);
        $loginHash = $mailAccount->getLoginHash();

        // Vérifier la présence dans Vault (KV v2)
        $vaultToken = $_ENV['VAULT_TOKEN'] ?? 'root';
        $vaultPath = '/v1/secret/data/scambuster/imap/' . $loginHash;
        $resp = $this->guzzle->get($vaultPath, [
            'headers' => [
                'X-Vault-Token' => $vaultToken,
            ],
        ]);
        $data = json_decode($resp->getBody()->getContents(), true);
        $this->assertSame($login, $data['data']['data']['login']);
        $this->assertSame($password, $data['data']['data']['secret']);

        // Nettoyage
        $this->em->remove($mailAccount);
        $this->em->flush();
        $this->guzzle->delete('/v1/secret/data/scambuster/imap/' . $loginHash, [
            'headers' => [
                'X-Vault-Token' => $vaultToken,
            ],
        ]);
    }
} 