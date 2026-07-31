<?php

declare(strict_types=1);

namespace App\Tests\Unit\UI\Dto;

use App\UI\Http\Dto\MessageAttachmentResponseDto;
use PHPUnit\Framework\TestCase;

class MessageAttachmentResponseDtoTest extends TestCase
{
    public function testConstructorAndToArray(): void
    {
        $dto = new MessageAttachmentResponseDto('att-123');

        $this->assertSame('att-123', $dto->attachment_id);
        $this->assertSame(['attachment_id' => 'att-123'], $dto->toArray());
    }
}
