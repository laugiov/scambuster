<?php

declare(strict_types=1);

namespace App\Tests\Integration\Communication;

use App\Domain\Communication\MailAccount;
use App\Domain\Communication\Repository\MailAccountRepositoryInterface;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Integration tests for account lookup by recipient email — the mechanism that
 * lets an operator plug in a new mailbox by configuration alone.
 */
final class DoctrineMailAccountRepositoryTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private MailAccountRepositoryInterface $repo;

    /** @var list<string> */
    private array $createdIds = [];

    protected function setUp(): void
    {
        self::bootKernel();
        $container = static::getContainer();
        $this->em = $container->get('doctrine')->getManager();
        $this->repo = $container->get(MailAccountRepositoryInterface::class);
    }

    protected function tearDown(): void
    {
        foreach ($this->createdIds as $id) {
            $account = $this->em->getRepository(MailAccount::class)->find($id);

            if ($account !== null) {
                $this->em->remove($account);
            }
        }

        $this->em->flush();
        parent::tearDown();
    }

    private function persistAccount(string $id, string $email, bool $active): void
    {
        $account = new MailAccount(
            accountId: $id,
            ownerId: '22222222-2222-2222-2222-222222222222',
            protocol: 'IMAP',
            endpoint: 'imap.example.com',
            loginHash: 'hash',
            oauthScopes: ['mail.read'],
            isActive: $active,
            emailAddress: $email,
        );
        $this->em->persist($account);
        $this->em->flush();
        $this->createdIds[] = $id;
    }

    public function testFindByEmailReturnsActiveAccount(): void
    {
        $id = 'aaaaaaaa-0000-0000-0000-00000000f001';
        $this->persistAccount($id, 'trap@findbyemail.test', true);

        $found = $this->repo->findByEmail('trap@findbyemail.test');

        self::assertNotNull($found);
        self::assertSame($id, $found->getAccountId());
    }

    public function testFindByEmailIsCaseInsensitive(): void
    {
        $id = 'aaaaaaaa-0000-0000-0000-00000000f002';
        $this->persistAccount($id, 'trap@findbyemail.test', true);

        $found = $this->repo->findByEmail('TRAP@FindByEmail.TEST');

        self::assertNotNull($found);
        self::assertSame($id, $found->getAccountId());
    }

    public function testFindByEmailIgnoresInactiveAccounts(): void
    {
        $id = 'aaaaaaaa-0000-0000-0000-00000000f003';
        $this->persistAccount($id, 'inactive@findbyemail.test', false);

        self::assertNull($this->repo->findByEmail('inactive@findbyemail.test'));
    }

    public function testFindByEmailReturnsNullWhenUnknown(): void
    {
        self::assertNull($this->repo->findByEmail('nobody@findbyemail.test'));
    }
}
