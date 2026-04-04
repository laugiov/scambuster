<?php

declare(strict_types=1);

namespace App\Application\Communication;

use App\Application\LLM\LanguageDetector;
use Psr\Log\LoggerInterface;
use ZBateson\MailMimeParser\MailMimeParser;

/**
 * Parses RFC822 email messages and extracts structured data.
 *
 * Extracted from IngestHandler (decomposition).
 * Handles: base64 decoding, MIME parsing, header extraction, body extraction,
 * HTML-to-text conversion, and language detection.
 */
class EmailParsingService
{
    public function __construct(
        private readonly LoggerInterface $logger,
        private readonly ?LanguageDetector $languageDetector = null,
    ) {
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
     *     bodyText: ?string,
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
            throw new \RuntimeException('Mail parse error: ' . $e->getMessage());
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
        $references = array_values(array_filter(array_map($normalizeMessageId, $referencesArray)));

        $this->logger->info('[EmailParsingService] Parsed headers', [
            'messageId' => $messageId,
            'inReplyTo' => $inReplyTo,
            'references' => $references,
        ]);

        // Extract other headers and content
        $to = $message->getHeaderValue('to') ?: null;
        $subject = $message->getHeaderValue('subject') ?: null;
        $date = $message->getHeaderValue('date') ?: null;
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

        // Collect all headers
        $allHeaders = [];

        foreach ($message->getAllHeaders() as $header) {
            $allHeaders[strtolower($header->getName())] = $header->getValue();
        }

        // Detect language
        $detectedLang = 'en';

        if ($this->languageDetector !== null && !empty($bodyText) && mb_strlen($bodyText) >= 50) {
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
     * Convert HTML to plain text for IOC extraction.
     * Preserves line breaks and removes HTML tags.
     */
    public function convertHtmlToText(string $html): string
    {
        // Remove script and style tags with their content (security)
        $text = preg_replace('/<script\b[^>]*>.*?<\/script>/is', '', $html);
        $text = preg_replace('/<style\b[^>]*>.*?<\/style>/is', '', $text);

        // Decode HTML entities
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        // Replace common block elements with newlines
        $text = preg_replace('/<\/(div|p|br|h[1-6]|li|tr)>/i', "\n", $text);
        $text = preg_replace('/<(br|hr)\s*\/?>/i', "\n", $text);

        // Replace list items with newlines and bullets
        $text = preg_replace('/<li[^>]*>/i', "\n• ", $text);

        // Remove remaining HTML tags
        $text = strip_tags($text);

        // Normalize whitespace
        $text = preg_replace('/[ \t]+/', ' ', $text); // Multiple spaces -> single space
        $text = preg_replace('/\n\s*\n+/', "\n\n", $text); // Multiple newlines -> double newline

        // Trim each line
        $lines = explode("\n", $text);
        $lines = array_map('trim', $lines);
        $text = implode("\n", $lines);

        return trim($text);
    }
}
