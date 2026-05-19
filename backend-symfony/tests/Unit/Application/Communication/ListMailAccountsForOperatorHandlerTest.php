<?php

declare(strict_types=1);

namespace Tests\Unit\Application\Communication;

use App\Application\Communication\ListMailAccountsForOperatorHandler;
use App\Domain\Communication\MailAccount;
use App\UI\Http\Dto\MailAccountListItemDto;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;

class ListMailAccountsForOperatorHandlerTest extends TestCase
{
    public function testHandleReturnsActiveAccountsMappedToOperatorDto(): void
    {
        $a1 = $this->createMock(MailAccount::class);
        $a1->method('getAccountId')->willReturn('uuid-tarrowby');
        $a1->method('getLabel')->willReturn('Tarrowby Holdings');
        $a1->method('getEmailAddress')->willReturn('admin@tarrowbyholdings.com');

        $a2 = $this->createMock(MailAccount::class);
        $a2->method('getAccountId')->willReturn('uuid-calverton');
        $a2->method('getLabel')->willReturn('Calverton Partners');
        $a2->method('getEmailAddress')->willReturn('admin@calvertonpartners.com');

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
        $this->assertSame('uuid-tarrowby', $result[0]->account_id);
        $this->assertSame('Tarrowby Holdings', $result[0]->label);
        $this->assertSame('admin@tarrowbyholdings.com', $result[0]->email);
        $this->assertSame('uuid-calverton', $result[1]->account_id);
        $this->assertSame('Calverton Partners', $result[1]->label);
        $this->assertSame('admin@calvertonpartners.com', $result[1]->email);
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
