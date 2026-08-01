<?php

declare(strict_types=1);

namespace Tests\Unit\Application\Communication;

use App\Application\Communication\ListMailAccountsForOperatorHandler;
use App\Domain\Communication\MailAccount;
use App\Application\Communication\Dto\MailAccountListItemDto;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;

class ListMailAccountsForOperatorHandlerTest extends TestCase
{
    public function testHandleReturnsActiveAccountsMappedToOperatorDto(): void
    {
        $a1 = $this->createMock(MailAccount::class);
        $a1->method('getAccountId')->willReturn('uuid-delta');
        $a1->method('getLabel')->willReturn('Delta Holdings');
        $a1->method('getEmailAddress')->willReturn('admin@delta-holdings.example');

        $a2 = $this->createMock(MailAccount::class);
        $a2->method('getAccountId')->willReturn('uuid-gamma');
        $a2->method('getLabel')->willReturn('Gamma Partners');
        $a2->method('getEmailAddress')->willReturn('admin@gamma-partners.example');

        $repo = $this->createMock(\Doctrine\ORM\EntityRepository::class);
        $repo->expects($this->once())
            ->method('findBy')
            ->with(['isActive' => true], ['label' => 'ASC'])
            ->willReturn([$a1, $a2]);

        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('getRepository')->willReturn($repo);

        $handler = new ListMailAccountsForOperatorHandler($em);
        $result = $handler->handle();

        $this->assertCount(2, $result);
        $this->assertInstanceOf(MailAccountListItemDto::class, $result[0]);
        $this->assertSame('uuid-delta', $result[0]->account_id);
        $this->assertSame('Delta Holdings', $result[0]->label);
        $this->assertSame('admin@delta-holdings.example', $result[0]->email);
        $this->assertSame('uuid-gamma', $result[1]->account_id);
        $this->assertSame('Gamma Partners', $result[1]->label);
        $this->assertSame('admin@gamma-partners.example', $result[1]->email);
    }

    public function testHandleHandlesNullLabelAndEmail(): void
    {
        $a = $this->createMock(MailAccount::class);
        $a->method('getAccountId')->willReturn('uuid-no-label');
        $a->method('getLabel')->willReturn(null);
        $a->method('getEmailAddress')->willReturn(null);

        $repo = $this->createMock(\Doctrine\ORM\EntityRepository::class);
        $repo->method('findBy')->willReturn([$a]);

        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('getRepository')->willReturn($repo);

        $handler = new ListMailAccountsForOperatorHandler($em);
        $result = $handler->handle();

        $this->assertCount(1, $result);
        $this->assertSame('uuid-no-label', $result[0]->account_id);
        $this->assertNull($result[0]->label);
        $this->assertNull($result[0]->email);
    }

    public function testHandleReturnsEmptyArrayWhenNoActiveAccounts(): void
    {
        $repo = $this->createMock(\Doctrine\ORM\EntityRepository::class);
        $repo->method('findBy')->willReturn([]);

        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('getRepository')->willReturn($repo);

        $handler = new ListMailAccountsForOperatorHandler($em);
        $result = $handler->handle();

        $this->assertSame([], $result);
    }
}
