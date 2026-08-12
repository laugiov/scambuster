<?php

declare(strict_types=1);

namespace App\Domain\Communication\Policy;

/**
 * MIME denylist for adversary attachments processed in the engagement zone.
 *
 * Attachment content is never persisted (only metadata + a sha256), so the
 * exposure is the parser reading the bytes. Executable and script payloads have
 * no analysis value as *content* here and carry the most parser/handler risk, so
 * they are skipped before their body is read. Documents, images and text — the
 * actual scam-analysis targets — are unaffected.
 *
 * Denylist, not allowlist: the platform must keep ingesting the open-ended set
 * of document/image types scammers use; only the small, well-known dangerous set
 * is blocked.
 *
 * Two signals, because adversaries routinely mislabel executables as
 * `application/octet-stream` (or send no Content-Type at all): the declared MIME
 * type AND the filename extension. Either one matching blocks the part.
 */
final class AttachmentMimePolicy
{
    /**
     * Blocked MIME types (lowercased, parameters stripped). Executables,
     * installers, native binaries and scripts across the common platforms.
     *
     * @var list<string>
     */
    private const BLOCKED_TYPES = [
        'application/x-msdownload',
        'application/x-dosexec',
        'application/x-msdos-program',
        'application/x-msi',
        'application/vnd.microsoft.portable-executable',
        'application/x-executable',
        'application/x-elf',
        'application/x-mach-binary',
        'application/x-sharedlib',
        'application/java-archive',
        'application/javascript',
        'text/javascript',
        'application/x-sh',
        'application/x-shellscript',
        'application/x-csh',
        'application/x-bat',
        'application/x-msdos-batch',
        'application/x-perl',
        'application/x-python',
    ];

    /**
     * Dangerous filename extensions — the second signal, for payloads that hide
     * behind a generic/absent Content-Type. Windows executables/scripts, native
     * binaries, and the classic double-extension tricks.
     *
     * @var list<string>
     */
    private const BLOCKED_EXTENSIONS = [
        'exe', 'dll', 'com', 'scr', 'pif', 'cpl', 'msi', 'msp', 'gadget',
        'bat', 'cmd', 'vbs', 'vbe', 'js', 'jse', 'wsf', 'wsh', 'ps1', 'psm1',
        'sh', 'bash', 'csh', 'jar', 'app', 'deb', 'rpm', 'run', 'bin',
        'lnk', 'reg', 'hta', 'msc', 'ade', 'adp', 'apk', 'dmg', 'iso', 'img',
    ];

    public static function isBlockedType(string $mimeType): bool
    {
        // Content-Type may carry parameters (e.g. `; name="x.exe"`) and arbitrary
        // case — normalise to the bare type/subtype.
        $bare = strtolower(trim(explode(';', $mimeType, 2)[0]));

        return \in_array($bare, self::BLOCKED_TYPES, true);
    }

    /**
     * Blocked by filename extension — catches mislabelled payloads
     * (application/octet-stream or no Content-Type at all).
     */
    public static function isBlockedFilename(?string $filename): bool
    {
        if (!\is_string($filename) || $filename === '') {
            return false;
        }

        $ext = strtolower(pathinfo(trim($filename), \PATHINFO_EXTENSION));

        return $ext !== '' && \in_array($ext, self::BLOCKED_EXTENSIONS, true);
    }

    /**
     * True when either the declared MIME type or the filename is dangerous.
     */
    public static function isBlocked(string $mimeType, ?string $filename): bool
    {
        return self::isBlockedType($mimeType) || self::isBlockedFilename($filename);
    }
}
