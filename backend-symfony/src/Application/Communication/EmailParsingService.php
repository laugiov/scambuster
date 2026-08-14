<?php

declare(strict_types=1);

namespace App\Application\Communication;

use App\Application\LLM\LanguageDetector;
use App\Domain\Communication\Policy\AttachmentMimePolicy;
use Psr\Log\LoggerInterface;
use ZBateson\MailMimeParser\MailMimeParser;
use ZBateson\MailMimeParser\Message\IMessagePart;

/**
 * Parses RFC822 email messages and extracts structured data.
 *
 * Extracted from IngestHandler (decomposition).
 * Handles: base64 decoding, MIME parsing, header extraction, body extraction,
 * HTML-to-text conversion, and language detection.
 */
class EmailParsingService
{
    /**
     * Default maximum size (in bytes) of an attachment that the
     * parser fallback will persist. Larger attachments are skipped with a
     * WARNING. Default: 25 MB (Gmail's max attachment size — a sane upper
     * bound for email-borne content). Overridable via constructor for tests.
     */
    public const DEFAULT_MAX_ATTACHMENT_SIZE_BYTES = 25 * 1024 * 1024;

    /**
     * Key under which duplicated singleton header names are recorded. Not a
     * real header: it is our own metadata, and it is stripped from inbound mail
     * so a sender cannot forge it.
     */
    private const DUPLICATE_HEADERS_KEY = 'x-scambuster-duplicate-headers';

    /**
     * Headers RFC 5322 §3.6 allows at most once. A second occurrence is
     * malformed, so the first is kept and the duplication is recorded.
     *
     * @var list<string>
     */
    private const SINGLETON_HEADERS = [
        'from',
        'sender',
        'reply-to',
        'to',
        'cc',
        'bcc',
        'message-id',
        'in-reply-to',
        'references',
        'subject',
        'date',
    ];

    private readonly int $maxAttachmentSizeBytes;

    public function __construct(
        private readonly LoggerInterface $logger,
        private readonly ?LanguageDetector $languageDetector = null,
        ?int $maxAttachmentSizeBytes = null,
    ) {
        $this->maxAttachmentSizeBytes = $maxAttachmentSizeBytes ?? self::DEFAULT_MAX_ATTACHMENT_SIZE_BYTES;
    }

    /**
     * Parse a base64-encoded RFC822 email and return structured data.
     *
     * @return array{
     *     from: ?string,
     *     messageId: ?string,
     *     inReplyTo: ?string,
     *     references: list<string>,
     *     to: ?string,
     *     subject: ?string,
     *     date: ?string,
     *     bodyText: string,
     *     bodyHtml: ?string,
     *     headers: array<string, string>,
     *     langDetect: string,
     *     contentType: ?string,
     *     rawSource: string,
     *     messageIdRaw: ?string,
     *     inReplyToRaw: ?string,
     *     referencesRaw: ?string
     * }
     */
    public function parseEmail(string $rawRfc822Base64): array
    {
        $rawSource = base64_decode($rawRfc822Base64, true);

        if ($rawSource === false) {
            throw new \RuntimeException('Invalid base64 in raw_source');
        }

        $parser = new MailMimeParser();

        try {
            $message = $parser->parse($rawSource, false);
        } catch (\Throwable $e) {
            throw new \RuntimeException('Mail parse error: ' . $e->getMessage(), $e->getCode(), $e);
        }

        // Extract raw header values
        $fromHeader = $message->getHeaderValue('from');
        $messageIdHeader = $message->getHeaderValue('message-id');
        $inReplyToHeader = $message->getHeaderValue('in-reply-to');
        $referencesHeader = $message->getHeaderValue('references');

        $this->logger->info('[EmailParsingService] Headers extracted', [
            'from' => $fromHeader,
            'message-id' => $messageIdHeader,
            'in-reply-to' => $inReplyToHeader,
            'references' => $referencesHeader,
        ]);

        // Normalize Message-IDs (strip chevrons)
        $normalizeMessageId = static function (?string $id): ?string {
            if (!$id) {
                return null;
            }

            return trim($id, '<>');
        };

        $messageId = $normalizeMessageId($messageIdHeader);
        $inReplyTo = $normalizeMessageId($inReplyToHeader);

        // Parse References header (may contain multiple Message-IDs separated by whitespace)
        $referencesArray = [];

        if (is_string($referencesHeader) && $referencesHeader !== '') {
            $split = preg_split('/[\s\r\n]+/', trim($referencesHeader));
            $referencesArray = $split !== false ? $split : [];
        }
        /** @var list<string> $references */
        $references = array_values(array_filter(array_map($normalizeMessageId, $referencesArray)));

        $this->logger->info('[EmailParsingService] Parsed headers', [
            'messageId' => $messageId,
            'inReplyTo' => $inReplyTo,
            'references' => $references,
        ]);

        // Extract other headers and content
        $toRaw = $message->getHeaderValue('to');
        $to = ($toRaw !== null && $toRaw !== '') ? $toRaw : null;
        $subjectRaw = $message->getHeaderValue('subject');
        $subject = ($subjectRaw !== null && $subjectRaw !== '') ? $subjectRaw : null;
        $dateRaw = $message->getHeaderValue('date');
        $date = ($dateRaw !== null && $dateRaw !== '') ? $dateRaw : null;
        $contentType = $message->getHeaderValue('content-type') ?: null;
        $bodyText = $message->getTextContent();
        $bodyHtml = $message->getHtmlContent();

        // Normalize content
        if ($bodyText !== null) {
            $bodyText = trim($bodyText);
        }

        if ($bodyHtml !== null) {
            $bodyHtml = trim($bodyHtml);
        }

        if ($bodyText === null || $bodyText === '') {
            $parts = preg_split("/\R\R/", $rawSource, 2);
            $bodyText = isset($parts[1]) ? ltrim($parts[1]) : '';
        }

        // If no body_text but body_html exists, convert HTML to text
        if ($bodyHtml && (!$bodyText || $bodyText === $bodyHtml || (stripos((string) $contentType, 'text/html') !== false && !$message->getTextContent()))) {
            $bodyText = $this->convertHtmlToText($bodyHtml);
        }

        // Collect all headers.
        //
        // Two things here are deliberate.
        //
        // 1. Header names are lowercased but NOT normalised, because the parser
        //    keeps whatever the sender wrote (`HeaderParserService` splits on
        //    the first `:` and validates nothing). So `Reply_To:` with an
        //    underscore becomes the key `reply_to`. Consumers must treat every
        //    key here as attacker-controlled — see ReplyHandler::resolveRecipient.
        //
        // 2. Duplicates resolve to the FIRST occurrence, not the last. RFC 5322
        //    §3.6 allows at most one of the headers below, so a second one is
        //    malformed and usually forged; MTAs and most parsers read the
        //    first, and a backend that read the last would disagree with them
        //    about who sent the mail. Which names were duplicated is recorded
        //    rather than dropped: it is a signal, not noise.
        $allHeaders = [];
        $duplicated = [];

        foreach ($message->getAllHeaders() as $header) {
            $name = strtolower($header->getName());

            // Our own marker, written below. A sender who puts this header in
            // their mail would otherwise manufacture a forgery signal on a mail
            // with no duplicates at all — an analyst-facing "this was forged"
            // flag that the forger sets is worse than no flag.
            if ($name === self::DUPLICATE_HEADERS_KEY) {
                continue;
            }

            if (array_key_exists($name, $allHeaders)) {
                if (in_array($name, self::SINGLETON_HEADERS, true)) {
                    $duplicated[$name] = true;

                    continue;
                }
            }

            $allHeaders[$name] = $header->getValue() ?? '';
        }

        if ($duplicated !== []) {
            $names = array_keys($duplicated);
            sort($names);
            $allHeaders[self::DUPLICATE_HEADERS_KEY] = implode(',', $names);
            $this->logger->warning('[EmailParsingService] Duplicate singleton headers, kept the first of each', [
                'headers' => $names,
            ]);
        }

        // Detect language
        $detectedLang = 'en';

        if ($this->languageDetector instanceof \App\Application\LLM\LanguageDetector && ($bodyText !== '' && $bodyText !== '0') && mb_strlen($bodyText) >= 50) {
            $detectedLang = $this->languageDetector->detect($bodyText);
            $this->logger->info('[EmailParsingService] Language detected', ['lang' => $detectedLang, 'body_length' => mb_strlen($bodyText)]);
        }

        return [
            'from' => $fromHeader,
            'messageId' => $messageId,
            'inReplyTo' => $inReplyTo,
            'references' => $references,
            'to' => $to,
            'subject' => $subject,
            'date' => $date,
            'bodyText' => $bodyText,
            'bodyHtml' => $bodyHtml,
            'headers' => $allHeaders,
            'langDetect' => $detectedLang,
            'contentType' => $contentType,
            'rawSource' => $rawSource,
            'messageIdRaw' => $messageIdHeader,
            'inReplyToRaw' => $inReplyToHeader,
            'referencesRaw' => $referencesHeader,
        ];
    }

    /**
     * Extract attachments from a base64-encoded RFC822 mail.
     *
     * Returns an array of attachment metadata in the shape expected by
     * `IngestHandler::processAttachments()`. Inline parts, multipart
     * containers, text/plain and text/html parts (when not flagged as
     * attachment) are excluded. The method is defensive: any parser failure
     * is caught, logged as a warning, and an empty array is returned —
     * never an exception.
     *
     * Used as a fallback by `IngestHandler::ingest()` when the upstream
     * collector (n8n) does not pre-populate `dto.attachments`.
     *
     * @return list<array{filename: string, mime_type: string, size_bytes: int, sha256: string}>
     */
    public function extractAttachments(string $rawRfc822Base64): array
    {
        if ($rawRfc822Base64 === '') {
            return [];
        }

        $rawSource = base64_decode($rawRfc822Base64, true);

        if ($rawSource === false) {
            $this->logger->warning('[EmailParsingService] extractAttachments: invalid base64, returning empty');

            return [];
        }

        try {
            $parser = new MailMimeParser();
            $message = $parser->parse($rawSource, false);
        } catch (\Throwable $e) {
            $this->logger->warning('[EmailParsingService] extractAttachments: parser failed', [
                'exception' => $e::class,
                'message' => $e->getMessage(),
            ]);

            return [];
        }

        $result = [];

        foreach ($message->getAllAttachmentParts() as $index => $part) {
            try {
                if (!$this->isExtractableAttachment($part)) {
                    continue;
                }

                // Skip dangerous executable/script attachments BEFORE reading the
                // body: their content has no analysis value here and carries the
                // most parser/handler risk in the engagement zone. Checked on both
                // the declared MIME type AND the filename extension, because
                // adversaries routinely mislabel executables as octet-stream
                // (AttachmentMimePolicy).
                $declaredMime = $part->getContentType() ?? 'application/octet-stream';
                $filename = $part->getFilename();

                if (AttachmentMimePolicy::isBlocked($declaredMime, $filename)) {
                    $this->logger->warning('[EmailParsingService] extractAttachments: blocked attachment, skipping without reading', [
                        'part_index' => $index,
                        'mime_type' => $declaredMime,
                        'filename' => $filename,
                    ]);

                    continue;
                }

                $stream = $part->getBinaryContentStream();

                if ($stream === null) {
                    continue;
                }

                // Read the stream in chunks and bail out as soon as we exceed
                // the size limit. The MailMimeParser stream is lazy
                // (getSize() returns null), so we cannot pre-check.
                $content = '';
                $oversized = false;
                $chunkSize = 65536; // 64 KB

                while (!$stream->eof()) {
                    $content .= $stream->read($chunkSize);

                    if (strlen($content) > $this->maxAttachmentSizeBytes) {
                        $oversized = true;

                        break;
                    }
                }

                if ($oversized) {
                    $this->logger->warning('[EmailParsingService] extractAttachments: attachment exceeds size limit, skipping', [
                        'part_index' => $index,
                        'size_bytes_read_so_far' => strlen($content),
                        'limit_bytes' => $this->maxAttachmentSizeBytes,
                        'filename' => $part->getFilename(),
                    ]);

                    unset($content);

                    continue;
                }

                $size = strlen($content);

                if ($size === 0) {
                    continue;
                }

                $filename = $part->getFilename() ?? sprintf('attachment-%d.bin', $index);
                $mimeType = $part->getContentType() ?? 'application/octet-stream';

                $result[] = [
                    'filename' => $filename,
                    'mime_type' => $mimeType,
                    'size_bytes' => $size,
                    'sha256' => hash('sha256', $content),
                ];
            } catch (\Throwable $e) {
                $this->logger->warning('[EmailParsingService] extractAttachments: part failed, skipping', [
                    'part_index' => $index,
                    'exception' => $e::class,
                    'message' => $e->getMessage(),
                ]);

                continue;
            }
        }

        return $result;
    }

    /**
     * Decide whether a MailMimeParser attachment part should be
     * persisted as an Attachment entity.
     *
     * Excludes inline images (Content-Disposition: inline). Includes
     * `text/calendar` and any non-inline part with a filename or attachment
     * disposition. The library's `getAllAttachmentParts()` already filters
     * out multipart containers and text/plain|text/html parts that are not
     * flagged as attachments — this method only adds the inline-image
     * exclusion on top.
     */
    private function isExtractableAttachment(IMessagePart $part): bool
    {
        $disposition = strtolower($part->getContentDisposition() ?? '');

        // Inline parts are display assets (embedded HTML images, etc.), not threat intel.
        return !str_starts_with($disposition, 'inline');
    }

    /**
     * Convert HTML to plain text for IOC extraction.
     * Preserves line breaks and removes HTML tags.
     */
    public function convertHtmlToText(string $html): string
    {
        // Remove script and style tags with their content (security)
        $text = preg_replace('/<script\b[^>]*>.*?<\/script>/is', '', $html);
        $text = preg_replace('/<style\b[^>]*>.*?<\/style>/is', '', (string) $text);

        // Decode HTML entities
        $text = html_entity_decode((string) $text, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        // Replace common block elements with newlines
        $text = preg_replace('/<\/(div|p|br|h[1-6]|li|tr)>/i', "\n", $text);
        $text = preg_replace('/<(br|hr)\s*\/?>/i', "\n", (string) $text);

        // Replace list items with newlines and bullets
        $text = preg_replace('/<li[^>]*>/i', "\n• ", (string) $text);

        // Remove remaining HTML tags
        $text = strip_tags((string) $text);

        // Normalize whitespace
        $text = preg_replace('/[ \t]+/', ' ', $text); // Multiple spaces -> single space
        $text = preg_replace('/\n\s*\n+/', "\n\n", (string) $text); // Multiple newlines -> double newline

        // Trim each line
        $lines = explode("\n", (string) $text);
        $lines = array_map('trim', $lines);
        $text = implode("\n", $lines);

        return trim($text);
    }
}
