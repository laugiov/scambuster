<?php

declare(strict_types=1);

namespace Tests\Functional\Communication;

use App\Domain\Communication\Channel;
use App\Domain\Communication\Conversation;
use App\Domain\Communication\ConversationStatus;
use App\Domain\Communication\MailAccount;
use App\Domain\Communication\ScamType;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class ConversationDtoMailAccountFieldsTest extends WebTestCase
{
    private const FIXTURE_CONV_OPEN = '00000000-0000-0000-0000-000000000001';
    private const BASE_URL = '/api/v1/communication/conversation';

    private KernelBrowser $client;
    private EntityManagerInterface $em;

    /** @var list<string> Account ids inserted by this test */
    private array $insertedAccounts = [];

    /** @var list<string> Conversation ids inserted by this test */
    private array $insertedConvs = [];

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->em = $this->client->getContainer()->get(EntityManagerInterface::class);
    }

    protected function tearDown(): void
    {
        foreach ($this->insertedConvs as $convId) {
            $conv = $this->em->find(Conversation::class, $convId);

            if ($conv !== null) {
                $this->em->remove($conv);
            }
        }

        foreach ($this->insertedAccounts as $accountId) {
            $account = $this->em->find(MailAccount::class, $accountId);

            if ($account !== null) {
                $this->em->remove($account);
            }
        }

        if ($this->insertedConvs !== [] || $this->insertedAccounts !== []) {
            $this->em->flush();
        }
        parent::tearDown();
    }

    public function testListConversationsResponseIncludesAccountFieldKeys(): void
    {
        $this->client->request('GET', self::BASE_URL, [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer fake-jwt',
        ]);

        $this->assertResponseIsSuccessful();
        $data = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertIsArray($data);
        $this->assertNotEmpty($data);

        foreach ($data as $item) {
            $this->assertArrayHasKey('account_label', $item);
            $this->assertArrayHasKey('account_email', $item);
        }
    }

    public function testGetConversationResponseIncludesAccountFieldKeys(): void
    {
        $this->client->request('GET', self::BASE_URL . '/' . self::FIXTURE_CONV_OPEN, [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer fake-jwt',
        ]);

        $this->assertResponseIsSuccessful();
        $data = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertIsArray($data);
        $this->assertArrayHasKey('account_label', $data);
        $this->assertArrayHasKey('account_email', $data);
    }

    public function testListConversationsPopulatesLabelAndEmailWhenAccountHasThem(): void
    {
        $convId = $this->seedConversationWithLabeledMailbox(
            label: 'Delta Holdings',
            email: 'admin@delta-holdings.example',
        );

        // Use limit large enough to fit the seeded conversation plus existing fixtures.
        $this->client->request('GET', self::BASE_URL . '?limit=5000', [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer fake-jwt',
        ]);

        $this->assertResponseIsSuccessful();
        $data = json_decode($this->client->getResponse()->getContent(), true);
        $found = $this->findConvInList($data, $convId);
        $this->assertNotNull($found, 'Seeded conversation must appear in list');
        $this->assertSame('Delta Holdings', $found['account_label']);
        $this->assertSame('admin@delta-holdings.example', $found['account_email']);
    }

    public function testGetConversationPopulatesLabelAndEmailWhenAccountHasThem(): void
    {
        $convId = $this->seedConversationWithLabeledMailbox(
            label: 'Gamma Partners',
            email: 'admin@gamma-partners.example',
        );

        $this->client->request('GET', self::BASE_URL . '/' . $convId, [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer fake-jwt',
        ]);

        $this->assertResponseIsSuccessful();
        $data = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertSame('Gamma Partners', $data['account_label']);
        $this->assertSame('admin@gamma-partners.example', $data['account_email']);
    }

    public function testGetConversationHandlesNullLabelGracefully(): void
    {
        // Email present but label null (legacy data: email_address predates the label rollout)
        $convId = $this->seedConversationWithLabeledMailbox(
            label: null,
            email: 'admin@legacy.example.invalid',
        );

        $this->client->request('GET', self::BASE_URL . '/' . $convId, [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer fake-jwt',
        ]);

        $this->assertResponseIsSuccessful();
        $data = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertNull($data['account_label']);
        $this->assertSame('admin@legacy.example.invalid', $data['account_email']);
    }

    private function seedConversationWithLabeledMailbox(?string $label, ?string $email): string
    {
        $accountId = uuid_create(UUID_TYPE_RANDOM);
        $account = new MailAccount(
            $accountId,
            '22222222-2222-2222-2222-222222222222',
            'IMAP',
            'imap.example.invalid',
            'login-hash-' . substr($accountId, 0, 8),
            ['mail.read'],
            true,
            new \DateTimeImmutable(),
            new \DateTimeImmutable(),
            993,
            true,
            $email,
            null,
            $label,
        );
        $this->em->persist($account);
        $this->insertedAccounts[] = $accountId;

        $channel = $this->em->getRepository(Channel::class)->findOneBy([]);
        $scamType = $this->em->getRepository(ScamType::class)->findOneBy([]);
        $this->assertNotNull($channel, 'Test fixtures must provide at least one Channel');
        $this->assertNotNull($scamType, 'Test fixtures must provide at least one ScamType');

        $convId = uuid_create(UUID_TYPE_RANDOM);
        $conv = new Conversation(
            $convId,
            $channel,
            $scamType,
            $account,
            ConversationStatus::OPEN,
            42,
            new \DateTimeImmutable('-1 hour'),
            new \DateTimeImmutable('-10 minutes'),
            'stix-seeded-' . substr($convId, 0, 8),
        );
        $this->em->persist($conv);
        $this->em->flush();
        $this->insertedConvs[] = $convId;

        return $convId;
    }

    /**
     * @param array<int, array<string, mixed>> $list
     *
     * @return array<string, mixed>|null
     */
    private function findConvInList(array $list, string $convId): ?array
    {
        foreach ($list as $item) {
            if (($item['conv_id'] ?? null) === $convId) {
                return $item;
            }
        }

        return null;
    }
}
