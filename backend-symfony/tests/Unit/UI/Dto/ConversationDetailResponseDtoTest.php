<?php

declare(strict_types=1);

namespace App\Tests\Unit\UI\Dto;

use App\UI\Http\Dto\ConversationDetailResponseDto;
use PHPUnit\Framework\TestCase;

class ConversationDetailResponseDtoTest extends TestCase
{
    public function testAccountFieldsDefaultToNull(): void
    {
        $dto = new ConversationDetailResponseDto(
            'conv-uuid',
            'open',
            42,
            '2026-05-19T10:00:00+00:00',
            '2026-05-19T11:00:00+00:00',
            'stix-id-123',
            [],
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
        $dto = new ConversationDetailResponseDto(
            'conv-uuid',
            'open',
            42,
            '2026-05-19T10:00:00+00:00',
            '2026-05-19T11:00:00+00:00',
            'stix-id-123',
            [],
            null,
            'Gamma Partners',
            'admin@gamma-partners.example',
        );

        $this->assertSame('Gamma Partners', $dto->account_label);
        $this->assertSame('admin@gamma-partners.example', $dto->account_email);

        $array = $dto->toArray();
        $this->assertSame('Gamma Partners', $array['account_label']);
        $this->assertSame('admin@gamma-partners.example', $array['account_email']);
    }
}
