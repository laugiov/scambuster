<?php

declare(strict_types=1);

namespace App\Tests\Unit\Domain\Communication;

use App\Domain\Communication\Attachment;
use PHPUnit\Framework\TestCase;

class AttachmentTest extends TestCase
{
    public function test_it_creates_attachment_with_valid_data(): void
    {
        $message = $this->createMock(\App\Domain\Communication\Message::class);
        $attachmentId = 'c1c2c3c4-c5c6-c7c8-c9c0-c1c2c3c4c5c6';
        $filename = 'document.pdf';
        $mimeType = 'application/pdf';
        $sizeBytes = 123456;
        $contentHash = bin2hex(random_bytes(32));
        $s3Key = 'attachments/c1c2c3c4-c5c6-c7c8-c9c0-c1c2c3c4c5c6';
        $encKeyId = null;
        $avStatus = 'pending';
        $ocrText = null;
        $vectorId = 'd1d2d3d4-d5d6-d7d8-d9d0-d1d2d3d4d5d6';
        $tsIngest = new \DateTimeImmutable('now');
        $deletedAt = null;

        $metadata = [
            'strelka' => [
                'yara_hits' => ['Phishing_PDF'],
                'ssdeep' => '3:abcdef:ghijkl',
                'tlsh' => 'T1234567890abcdef'
            ],
            'sandbox' => [
                'score' => 3,
                'network' => ['185.220.101.4:443'],
                'dropped' => ['c0ffee...sha256']
            ]
        ];

        $attachment = new Attachment(
            $attachmentId,
            $message,
            $filename,
            $mimeType,
            $sizeBytes,
            $contentHash,
            $s3Key,
            $encKeyId,
            $avStatus,
            $ocrText,
            $vectorId,
            $tsIngest,
            $deletedAt
        );
        $attachment->setMetadata($metadata);

        $this->assertInstanceOf(Attachment::class, $attachment);
        $this->assertSame($attachmentId, $attachment->getAttachmentId());
        $this->assertSame($message, $attachment->getMessage());
        $this->assertSame($filename, $attachment->getFilename());
        $this->assertSame($mimeType, $attachment->getMimeType());
        $this->assertSame($sizeBytes, $attachment->getSizeBytes());
        $this->assertSame($contentHash, $attachment->getContentHash());
        $this->assertSame($s3Key, $attachment->getS3Key());
        $this->assertSame($encKeyId, $attachment->getEncKeyId());
        $this->assertSame($avStatus, $attachment->getAvStatus());
        $this->assertSame($ocrText, $attachment->getOcrText());
        $this->assertSame($vectorId, $attachment->getVectorId());
        $this->assertSame($tsIngest, $attachment->getTsIngest());
        $this->assertSame($deletedAt, $attachment->getDeletedAt());
        
        // Vérifier les métadonnées
        $storedMetadata = $attachment->getMetadata();
        $this->assertArrayHasKey('strelka', $storedMetadata);
        $this->assertArrayHasKey('sandbox', $storedMetadata);
        $this->assertSame(['Phishing_PDF'], $storedMetadata['strelka']['yara_hits']);
        $this->assertSame(3, $storedMetadata['sandbox']['score']);

        // Tester les setters
        $newS3Key = 'attachments/new-key';
        $newEncKeyId = 'key-123';
        $newAvStatus = 'clean';
        $newOcrText = 'Extracted text from PDF';
        $newVectorId = 'v1v2v3v4-v5v6-v7v8-v9v0-v1v2v3v4v5v6';
        $newMetadata = ['new' => 'metadata'];

        $attachment->setS3Key($newS3Key);
        $attachment->setEncKeyId($newEncKeyId);
        $attachment->setAvStatus($newAvStatus);
        $attachment->setOcrText($newOcrText);
        $attachment->setVectorId($newVectorId);
        $attachment->setMetadata($newMetadata);

        $this->assertSame($newS3Key, $attachment->getS3Key());
        $this->assertSame($newEncKeyId, $attachment->getEncKeyId());
        $this->assertSame($newAvStatus, $attachment->getAvStatus());
        $this->assertSame($newOcrText, $attachment->getOcrText());
        $this->assertSame($newVectorId, $attachment->getVectorId());
        $this->assertSame($newMetadata, $attachment->getMetadata());
    }

    public function test_it_handles_empty_metadata(): void
    {
        $message = $this->createMock(\App\Domain\Communication\Message::class);
        $attachment = new Attachment(
            'c1c2c3c4-c5c6-c7c8-c9c0-c1c2c3c4c5c6',
            $message,
            'test.pdf',
            'application/pdf',
            1024,
            bin2hex(random_bytes(32))
        );

        $this->assertNull($attachment->getMetadata());
        $this->assertNull($attachment->getS3Key());
        $this->assertNull($attachment->getEncKeyId());
        $this->assertSame('pending', $attachment->getAvStatus());
        $this->assertNull($attachment->getOcrText());
        $this->assertNull($attachment->getVectorId());
    }
} 