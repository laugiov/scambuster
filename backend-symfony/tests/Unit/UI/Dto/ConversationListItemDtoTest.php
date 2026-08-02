<?php

declare(strict_types=1);

namespace App\Tests\Unit\UI\Dto;

use App\UI\Http\Dto\ConversationListItemDto;
use PHPUnit\Framework\TestCase;

class ConversationListItemDtoTest extends TestCase
{
    public function testAccountFieldsDefaultToNull(): void
    {
        $dto = new ConversationListItemDto(
            'conv-uuid',
            'open',
            42,
            '2026-05-19T10:00:00+00:00',
            '2026-05-19T11:00:00+00:00',
            'stix-id-123',
        );

        $this->assertNull($dto->account_label);
        $this->assertNull($dto->account_email);

        $array = $dto->toArray();
        $this->assertArrayHasKey('account_label', $array);
        $this->assertArrayHasKey('account_email', $array);
        $this->assertNull($array['account_label']);
        $this->assertNull($array['account_email']);
    }

    public function testAccountFieldsPopulatedWhenProvided(): void
    {
        $dto = new ConversationListItemDto(
            'conv-uuid',
            'open',
            42,
            '2026-05-19T10:00:00+00:00',
            '2026-05-19T11:00:00+00:00',
            'stix-id-123',
            null,
            null,
            0,
            0,
            null,
            0,
            null,
            'Delta Holdings',
            'admin@delta-holdings.example',
        );

        $this->assertSame('Delta Holdings', $dto->account_label);
        $this->assertSame('admin@delta-holdings.example', $dto->account_email);

        $array = $dto->toArray();
        $this->assertSame('Delta Holdings', $array['account_label']);
        $this->assertSame('admin@delta-holdings.example', $array['account_email']);
    }
}
