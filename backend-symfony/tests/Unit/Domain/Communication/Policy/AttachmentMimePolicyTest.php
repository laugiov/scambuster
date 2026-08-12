<?php

declare(strict_types=1);

namespace App\Tests\Unit\Domain\Communication\Policy;

use App\Domain\Communication\Policy\AttachmentMimePolicy;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class AttachmentMimePolicyTest extends TestCase
{
    /**
     * @return iterable<string, array{string, bool}>
     */
    public static function cases(): iterable
    {
        // Dangerous executable / script types → blocked (not read in the
        // engagement zone).
        yield 'windows exe' => ['application/x-msdownload', true];
        yield 'pe binary' => ['application/vnd.microsoft.portable-executable', true];
        yield 'dos exec' => ['application/x-dosexec', true];
        yield 'msi installer' => ['application/x-msi', true];
        yield 'elf binary' => ['application/x-executable', true];
        yield 'shell script' => ['application/x-sh', true];
        yield 'shellscript alt' => ['application/x-shellscript', true];
        yield 'bat' => ['application/x-bat', true];
        yield 'js attachment' => ['application/javascript', true];
        yield 'jar' => ['application/java-archive', true];
        yield 'case + params ignored' => ['Application/X-MSDownload; name="x.exe"', true];

        // Analysis targets → allowed.
        yield 'pdf' => ['application/pdf', false];
        yield 'png' => ['image/png', false];
        yield 'jpeg' => ['image/jpeg', false];
        yield 'docx' => ['application/vnd.openxmlformats-officedocument.wordprocessingml.document', false];
        yield 'xls' => ['application/vnd.ms-excel', false];
        yield 'plain text' => ['text/plain', false];
        yield 'octet-stream (unknown)' => ['application/octet-stream', false];
    }

    #[DataProvider('cases')]
    public function testIsBlockedType(string $mime, bool $expected): void
    {
        self::assertSame($expected, AttachmentMimePolicy::isBlockedType($mime));
    }

    /**
     * @return iterable<string, array{?string, bool}>
     */
    public static function filenames(): iterable
    {
        yield 'exe' => ['update.exe', true];
        yield 'double extension' => ['invoice.pdf.exe', true];
        yield 'uppercase' => ['SETUP.EXE', true];
        yield 'js' => ['payload.js', true];
        yield 'bat' => ['run.bat', true];
        yield 'jar' => ['tool.jar', true];
        yield 'iso' => ['disk.iso', true];
        yield 'pdf' => ['invoice.pdf', false];
        yield 'docx' => ['contract.docx', false];
        yield 'png' => ['photo.png', false];
        yield 'no extension' => ['README', false];
        yield 'empty' => ['', false];
        yield 'null' => [null, false];
    }

    #[DataProvider('filenames')]
    public function testIsBlockedFilename(?string $filename, bool $expected): void
    {
        self::assertSame($expected, AttachmentMimePolicy::isBlockedFilename($filename));
    }

    public function testIsBlockedCatchesMislabelledExecutable(): void
    {
        // The realistic bypass: an .exe sent as application/octet-stream.
        self::assertTrue(AttachmentMimePolicy::isBlocked('application/octet-stream', 'update.exe'));
        self::assertFalse(AttachmentMimePolicy::isBlocked('application/pdf', 'invoice.pdf'));
    }
}
