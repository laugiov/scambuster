<?php

declare(strict_types=1);

namespace App\Tests\Unit\UI\Dto;

use App\UI\Http\Dto\MailAccountListItemDto;
use PHPUnit\Framework\TestCase;

class MailAccountListItemDtoTest extends TestCase
{
    public function testConstructorExposesPublicFields(): void
    {
        $dto = new MailAccountListItemDto(
            'acc-uuid-123',
            'Tarrowby Holdings',
            'admin@tarrowbyholdings.com',
        );

        $this->assertSame('acc-uuid-123', $dto->account_id);
        $this->assertSame('Tarrowby Holdings', $dto->label);
        $this->assertSame('admin@tarrowbyholdings.com', $dto->email);
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
            'Calverton Partners',
            'admin@calvertonpartners.com',
        );

        $this->assertSame([
            'account_id' => 'acc-uuid-789',
            'label' => 'Calverton Partners',
            'email' => 'admin@calvertonpartners.com',
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
