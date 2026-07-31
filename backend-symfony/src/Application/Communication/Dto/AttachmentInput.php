<?php

declare(strict_types=1);

namespace App\Application\Communication\Dto;

/**
 * Framework-agnostic attachment metadata, so the Application layer does not
 * depend on Symfony's HttpFoundation UploadedFile. The controller unwraps the
 * uploaded file into this DTO.
 */
final readonly class AttachmentInput
{
    public function __construct(
        public string $originalName,
        public ?string $mimeType,
        public int $size,
    ) {
    }
}
