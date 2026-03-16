<?php

declare(strict_types=1);

namespace App\Domain\Communication;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'attachment')]
class Attachment
{
    #[ORM\Id]
    #[ORM\Column(name: 'attachment_id', type: 'uuid', unique: true)]
    private string $attachmentId;

    #[ORM\ManyToOne(targetEntity: Message::class, inversedBy: 'attachments')]
    #[ORM\JoinColumn(name: 'msg_id', referencedColumnName: 'msg_id', nullable: false, onDelete: 'CASCADE')]
    private Message $message;

    #[ORM\Column(type: 'string', length: 255)]
    private string $filename;

    #[ORM\Column(name: 'mime_type', type: 'string', length: 128)]
    private string $mimeType;

    #[ORM\Column(name: 'size_bytes', type: 'integer')]
    private int $sizeBytes;

    #[ORM\Column(name: 'content_hash', type: 'binary', length: 32, unique: true)]
    private mixed $contentHash; // @phpstan-ignore property.unusedType

    #[ORM\Column(name: 's3_key', type: 'string', length: 255, nullable: true)]
    private ?string $s3Key = null;

    #[ORM\Column(name: 'enc_key_id', type: 'string', length: 64, nullable: true)]
    private ?string $encKeyId = null;

    #[ORM\Column(name: 'av_status', type: 'string', length: 16, options: ['default' => 'pending'])]
    private string $avStatus = 'pending';

    #[ORM\Column(name: 'ocr_text', type: 'text', nullable: true)]
    private ?string $ocrText = null;

    #[ORM\Column(name: 'vector_id', type: 'uuid', nullable: true)]
    private ?string $vectorId = null;

    #[ORM\Column(name: 'ts_ingest', type: 'datetime_immutable')]
    private \DateTimeImmutable $tsIngest;

    #[ORM\Column(name: 'deleted_at', type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $deletedAt = null;

    /** @var array<string, mixed>|null */
    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $metadata = null;

    public function __construct(
        string $attachmentId,
        Message $message,
        string $filename,
        string $mimeType,
        int $sizeBytes,
        string $contentHash,
        ?string $s3Key = null,
        ?string $encKeyId = null,
        string $avStatus = 'pending',
        ?string $ocrText = null,
        ?string $vectorId = null,
        \DateTimeImmutable $tsIngest = null,
        ?\DateTimeImmutable $deletedAt = null
    ) {
        $this->attachmentId = $attachmentId;
        $this->message = $message;
        $this->filename = $filename;
        $this->mimeType = $mimeType;
        $this->sizeBytes = $sizeBytes;
        $this->contentHash = $contentHash;
        $this->s3Key = $s3Key;
        $this->encKeyId = $encKeyId;
        $this->avStatus = $avStatus;
        $this->ocrText = $ocrText;
        $this->vectorId = $vectorId;
        $this->tsIngest = $tsIngest ?? new \DateTimeImmutable();
        $this->deletedAt = $deletedAt;
    }

    public function getAttachmentId(): string
    {
        return $this->attachmentId;
    }

    public function getMessage(): Message
    {
        return $this->message;
    }

    public function getFilename(): string
    {
        return $this->filename;
    }

    public function getMimeType(): string
    {
        return $this->mimeType;
    }

    public function getSizeBytes(): int
    {
        return $this->sizeBytes;
    }

    public function getContentHash(): string
    {
        if (is_resource($this->contentHash)) {
            return stream_get_contents($this->contentHash) ?: '';
        }

        if (is_string($this->contentHash)) {
            return $this->contentHash;
        }

        return '';
    }

    public function getS3Key(): ?string
    {
        return $this->s3Key;
    }

    public function getEncKeyId(): ?string
    {
        return $this->encKeyId;
    }

    public function getAvStatus(): string
    {
        return $this->avStatus;
    }

    public function getOcrText(): ?string
    {
        return $this->ocrText;
    }

    public function getVectorId(): ?string
    {
        return $this->vectorId;
    }

    public function getTsIngest(): \DateTimeImmutable
    {
        return $this->tsIngest;
    }

    public function getDeletedAt(): ?\DateTimeImmutable
    {
        return $this->deletedAt;
    }

    /** @return array<string, mixed>|null */
    public function getMetadata(): ?array
    {
        return $this->metadata;
    }

    /** @param array<string, mixed>|null $metadata */
    public function setMetadata(?array $metadata): void
    {
        $this->metadata = $metadata;
    }

    public function setS3Key(?string $s3Key): void
    {
        $this->s3Key = $s3Key;
    }

    public function setEncKeyId(?string $encKeyId): void
    {
        $this->encKeyId = $encKeyId;
    }

    public function setAvStatus(string $avStatus): void
    {
        $this->avStatus = $avStatus;
    }

    public function setOcrText(?string $ocrText): void
    {
        $this->ocrText = $ocrText;
    }

    public function setVectorId(?string $vectorId): void
    {
        $this->vectorId = $vectorId;
    }

    public function setMessage(?Message $message): void
    {
        $this->message = $message;
    }
}
