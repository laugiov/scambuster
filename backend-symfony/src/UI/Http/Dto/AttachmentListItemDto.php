<?php

declare(strict_types=1);

namespace App\UI\Http\Dto;

final class AttachmentListItemDto
{
    public function __construct(
        public string $attachment_id,
        public string $filename,
        public string $mime_type,
        public int $size_bytes,
        public ?string $deleted_at = null
    ) {
    }

    public function toArray(): array
    {
        return [
            'attachment_id' => $this->attachment_id,
            'filename' => $this->filename,
            'mime_type' => $this->mime_type,
            'size_bytes' => $this->size_bytes,
            'deleted_at' => $this->deleted_at,
        ];
    }
}
