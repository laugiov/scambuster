<?php

declare(strict_types=1);

namespace Tests\Unit\Application\Communication;

use App\Application\Communication\ListActiveMailAccountsHandler;
use App\Application\Communication\Dto\MailAccountActiveDto;
use App\Domain\Communication\MailAccount;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;

class ListActiveMailAccountsHandlerTest extends TestCase
{
    public function test_handle_returns_active_imap_accounts(): void
    {
        $mailAccount = $this->createMock(MailAccount::class);
        $mailAccount->method('getAccountId')->willReturn('uuid-1');
        $mailAccount->method('getProtocol')->willReturn('IMAP');
        $mailAccount->method('getEndpoint')->willReturn('imap.example.com');
        $mailAccount->method('getLoginHash')->willReturn('hash1');
        $mailAccount->method('getOauthScopes')->willReturn(['mail.read']);
        $mailAccount->method('getPort')->willReturn(993);
        $mailAccount->method('getSecure')->willReturn(true);

        $repo = $this->createMock(\Doctrine\ORM\EntityRepository::class);
        $repo->expects($this->once())
            ->method('findBy')
            ->with(['isActive' => true, 'protocol' => 'IMAP'])
            ->willReturn([$mailAccount]);

        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('getRepository')->willReturn($repo);

        $handler = new ListActiveMailAccountsHandler($em);
        $result = $handler->handle();

        $this->assertCount(1, $result);
        $dto = $result[0];
        $this->assertInstanceOf(MailAccountActiveDto::class, $dto);
        $this->assertSame('uuid-1', $dto->account_id);
        $this->assertSame('IMAP', $dto->protocol);
        $this->assertSame('imap.example.com', $dto->endpoint);
        $this->assertSame('hash1', $dto->login_hash);
        $this->assertSame(['mail.read'], $dto->oauth_scopes);
        $this->assertSame(993, $dto->port);
        $this->assertTrue($dto->secure);
    }
} 