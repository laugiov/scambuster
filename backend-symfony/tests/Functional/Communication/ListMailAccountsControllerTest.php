<?php

declare(strict_types=1);

namespace Tests\Functional\Communication;

use App\Domain\Communication\MailAccount;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;

final class ListMailAccountsControllerTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $em;

    /** @var list<string> Account ids inserted by this test (for cleanup) */
    private array $inserted = [];

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->em = $this->client->getContainer()->get(EntityManagerInterface::class);
    }

    protected function tearDown(): void
    {
        foreach ($this->inserted as $accountId) {
            $managed = $this->em->find(MailAccount::class, $accountId);
            if ($managed !== null) {
                $this->em->remove($managed);
            }
        }
        if ($this->inserted !== []) {
            $this->em->flush();
        }
        parent::tearDown();
    }

    public function testRequiresAuthentication(): void
    {
        $this->client->request('GET', '/api/v1/communication/mail-accounts');

        $this->assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);
    }

    public function testReturnsJsonArrayWithOperatorAuth(): void
    {
        $this->client->request('GET', '/api/v1/communication/mail-accounts', [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer fake-jwt',
        ]);

        $this->assertResponseIsSuccessful();
        $this->assertResponseHeaderSame('content-type', 'application/json');

        $data = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertIsArray($data);
    }

    public function testResponseItemsHaveExpectedOperatorFields(): void
    {
        $this->seedAccount('Delta Holdings', 'admin@delta-holdings.example', isActive: true);

        $this->client->request('GET', '/api/v1/communication/mail-accounts', [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer fake-jwt',
        ]);

        $this->assertResponseIsSuccessful();

        $data = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertNotEmpty($data, 'Expected at least the seeded mailbox');

        $first = $data[0];
        $this->assertArrayHasKey('account_id', $first);
        $this->assertArrayHasKey('label', $first);
        $this->assertArrayHasKey('email', $first);
        // Operator-friendly DTO must NOT leak technical fields exposed by the
        // admin endpoint (login_hash, oauth_scopes, endpoint).
        $this->assertArrayNotHasKey('login_hash', $first);
        $this->assertArrayNotHasKey('oauth_scopes', $first);
        $this->assertArrayNotHasKey('endpoint', $first);
    }

    public function testIncludesSeededActiveAccount(): void
    {
        $accountId = $this->seedAccount('Sentinel Test Mailbox', 'sentinel-test@example.invalid', isActive: true);

        $this->client->request('GET', '/api/v1/communication/mail-accounts', [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer fake-jwt',
        ]);

        $this->assertResponseIsSuccessful();

        $data = json_decode($this->client->getResponse()->getContent(), true);
        $ids = array_column($data, 'account_id');
        $this->assertContains($accountId, $ids, 'Active seeded mailbox must appear in operator list');

        $found = null;
        foreach ($data as $row) {
            if ($row['account_id'] === $accountId) {
                $found = $row;

                break;
            }
        }
        $this->assertNotNull($found);
        $this->assertSame('Sentinel Test Mailbox', $found['label']);
        $this->assertSame('sentinel-test@example.invalid', $found['email']);
    }

    public function testExcludesInactiveAccounts(): void
    {
        $inactiveId = $this->seedAccount('Inactive Mailbox', 'inactive@example.invalid', isActive: false);

        $this->client->request('GET', '/api/v1/communication/mail-accounts', [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer fake-jwt',
        ]);

        $this->assertResponseIsSuccessful();

        $data = json_decode($this->client->getResponse()->getContent(), true);
        $ids = array_column($data, 'account_id');
        $this->assertNotContains($inactiveId, $ids, 'Inactive mailbox must NOT appear in operator list');
    }

    private function seedAccount(?string $label, ?string $email, bool $isActive): string
    {
        $accountId = uuid_create(UUID_TYPE_RANDOM);
        $account = new MailAccount(
            $accountId,
            '11111111-1111-1111-1111-111111111111',
            'IMAP',
            'imap.example.invalid',
            'login-hash-' . substr($accountId, 0, 8),
            ['mail.read'],
            $isActive,
            new \DateTimeImmutable(),
            new \DateTimeImmutable(),
            993,
            true,
            $email,
            null,
            $label,
        );
        $this->em->persist($account);
        $this->em->flush();
        $this->inserted[] = $accountId;

        return $accountId;
    }
}
