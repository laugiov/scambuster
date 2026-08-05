<?php

declare(strict_types=1);

namespace App\Tests\Unit\Application\Communication\Dto;

use App\Application\Communication\Dto\MailAccountListItemDto;
use PHPUnit\Framework\TestCase;

class MailAccountListItemDtoTest extends TestCase
{
    public function testConstructorExposesPublicFields(): void
    {
        $dto = new MailAccountListItemDto(
            'acc-uuid-123',
            'Delta Holdings',
            'admin@delta-holdings.example',
        );

        $this->assertSame('acc-uuid-123', $dto->account_id);
        $this->assertSame('Delta Holdings', $dto->label);
        $this->assertSame('admin@delta-holdings.example', $dto->email);
    }

    public function testLabelAndEmailDefaultToNull(): void
    {
        $dto = new MailAccountListItemDto('acc-uuid-only');

        $this->assertSame('acc-uuid-only', $dto->account_id);
        $this->assertNull($dto->label);
        $this->assertNull($dto->email);
    }

    public function testToArrayContainsAllFields(): void
    {
        $dto = new MailAccountListItemDto(
            'acc-uuid-789',
            'Gamma Partners',
            'admin@gamma-partners.example',
        );

        $this->assertSame([
            'account_id' => 'acc-uuid-789',
            'label' => 'Gamma Partners',
            'email' => 'admin@gamma-partners.example',
        ], $dto->toArray());
    }

    public function testToArrayKeepsNullValues(): void
    {
        $dto = new MailAccountListItemDto('acc-uuid-null-fields');

        $this->assertSame([
            'account_id' => 'acc-uuid-null-fields',
            'label' => null,
            'email' => null,
        ], $dto->toArray());
    }
}
