<?php

declare(strict_types=1);

namespace App\Application\Communication;

/**
 * Service responsible for extracting IOCs from email headers.
 *
 * Extracts 5 additional IOC types:
 * - message_id: Email Message-ID header (RFC 5322)
 * - subject: Email subject line
 * - spf_result: SPF validation result (ENUM)
 * - dkim_result: DKIM validation result (ENUM)
 * - dmarc_result: DMARC validation result (ENUM)
 *
 * No external enrichment required - all data is already validated by receiving mail server.
 */
final class HeaderIocExtractor
{
    private const SPF_ENUM_VALUES = ['PASS', 'FAIL', 'SOFTFAIL', 'NEUTRAL', 'NONE', 'TEMPERROR', 'PERMERROR'];
    private const DKIM_ENUM_VALUES = ['PASS', 'FAIL', 'NEUTRAL', 'NONE', 'TEMPERROR', 'PERMERROR'];
    private const DMARC_ENUM_VALUES = ['PASS', 'FAIL', 'NONE', 'TEMPERROR', 'PERMERROR'];

    /**
     * Extract header-based IOCs from message headers.
     *
     * @param array<string, mixed> $headers Message headers (from message.headers JSONB field)
     * @param string               $subject Message subject (from message.subject column)
     *
     * @return array<array{type: string, value: string, value_norm: string, source: string}> Array of IOC data ready for upsertEnrichedIoc()
     */
    public function extractHeaderIocs(array $headers, string $subject): array
    {
        $iocs = [];

        // Extract Message-ID
        $messageId = $this->extractMessageId($headers);

        if ($messageId !== null) {
            $iocs[] = [
                'type' => 'message_id',
                'value' => $messageId,
                'value_norm' => $messageId,
                'source' => 'headers',
            ];
        }

        // Extract From email
        $fromEmail = $this->extractEmailFromHeader($headers, 'from');

        if ($fromEmail !== null) {
            $iocs[] = [
                'type' => 'email',
                'value' => $fromEmail,
                'value_norm' => strtolower(trim($fromEmail)),
                'source' => 'headers.from',
            ];
        }

        // Extract Reply-To email
        $replyToEmail = $this->extractEmailFromHeader($headers, 'reply-to');

        if ($replyToEmail !== null) {
            $iocs[] = [
                'type' => 'email',
                'value' => $replyToEmail,
                'value_norm' => strtolower(trim($replyToEmail)),
                'source' => 'headers.reply-to',
            ];
        }

        // Extract Return-Path email
        $returnPathEmail = $this->extractEmailFromHeader($headers, 'return-path');

        if ($returnPathEmail !== null) {
            $iocs[] = [
                'type' => 'email',
                'value' => $returnPathEmail,
                'value_norm' => strtolower(trim($returnPathEmail)),
                'source' => 'headers.return-path',
            ];
        }

        // Extract Subject
        if ($subject !== '') {
            $iocs[] = [
                'type' => 'subject',
                'value' => $subject,
                'value_norm' => trim($subject),
                'source' => 'headers',
            ];
        }

        // Extract authentication results (SPF, DKIM, DMARC)
        $authResults = $this->extractAuthenticationResults($headers);

        if ($authResults['spf'] !== null) {
            $iocs[] = [
                'type' => 'spf_result',
                'value' => $authResults['spf'],
                'value_norm' => $authResults['spf'],
                'source' => 'headers',
            ];
        }

        if ($authResults['dkim'] !== null) {
            $iocs[] = [
                'type' => 'dkim_result',
                'value' => $authResults['dkim'],
                'value_norm' => $authResults['dkim'],
                'source' => 'headers',
            ];
        }

        if ($authResults['dmarc'] !== null) {
            $iocs[] = [
                'type' => 'dmarc_result',
                'value' => $authResults['dmarc'],
                'value_norm' => $authResults['dmarc'],
                'source' => 'headers',
            ];
        }

        return $iocs;
    }

    /**
     * Extract Message-ID from headers.
     *
     * Tries multiple paths:
     * - headers['message-id']
     * - headers['parsed']['headers']['message-id']
     *
     * Removes angle brackets < > if present.
     *
     * @param array<string, mixed> $headers
     */
    private function extractMessageId(array $headers): ?string
    {
        // Try direct message-id field
        $messageId = $headers['message-id'] ?? null;

        // Try parsed headers
        // @phpstan-ignore-next-line - isset() is safe for nested array access
        if ($messageId === null && isset($headers['parsed']['headers']['message-id'])) {
            $parsed = $headers['parsed'];

            if (is_array($parsed) && isset($parsed['headers'])) {
                $parsedHeaders = $parsed['headers'];

                if (is_array($parsedHeaders) && isset($parsedHeaders['message-id'])) {
                    $messageId = $parsedHeaders['message-id'];
                }
            }
        }

        if ($messageId === null || $messageId === '' || !is_string($messageId)) {
            return null;
        }

        // Remove angle brackets if present
        $messageId = trim($messageId);

        if (str_starts_with($messageId, '<') && str_ends_with($messageId, '>')) {
            $messageId = substr($messageId, 1, -1);
        }

        return $messageId;
    }

    /**
     * Extract SPF, DKIM, DMARC results from Authentication-Results header.
     *
     * Parses the arc-authentication-results or authentication-results header.
     * Example:
     * "dkim=pass header.i=@gmail.com; spf=pass smtp.mailfrom=...; dmarc=pass (p=NONE)"
     *
     * @param array<string, mixed> $headers
     *
     * @return array{spf: ?string, dkim: ?string, dmarc: ?string}
     */
    private function extractAuthenticationResults(array $headers): array
    {
        $authHeader = null;

        // Try arc-authentication-results first (Gmail uses this)
        if (isset($headers['arc-authentication-results'])) {
            $authHeader = $headers['arc-authentication-results'];
        } elseif (isset($headers['parsed'])) {
            $parsed = $headers['parsed'];

            if (is_array($parsed) && isset($parsed['headers'])) {
                $parsedHeaders = $parsed['headers'];

                if (is_array($parsedHeaders)) {
                    if (isset($parsedHeaders['arc-authentication-results'])) {
                        $authHeader = $parsedHeaders['arc-authentication-results'];
                    } elseif (isset($parsedHeaders['authentication-results'])) {
                        $authHeader = $parsedHeaders['authentication-results'];
                    }
                }
            }
        }

        if ($authHeader === null && isset($headers['authentication-results'])) {
            $authHeader = $headers['authentication-results'];
        }

        if ($authHeader === null || !is_string($authHeader)) {
            return ['spf' => null, 'dkim' => null, 'dmarc' => null];
        }

        return [
            'spf' => $this->extractAuthResult($authHeader, 'spf', self::SPF_ENUM_VALUES),
            'dkim' => $this->extractAuthResult($authHeader, 'dkim', self::DKIM_ENUM_VALUES),
            'dmarc' => $this->extractAuthResult($authHeader, 'dmarc', self::DMARC_ENUM_VALUES),
        ];
    }

    /**
     * Extract a specific auth result (spf/dkim/dmarc) from authentication header.
     *
     * Uses regex to find patterns like "spf=pass" or "dkim=fail".
     *
     * @param array<string> $allowedValues
     */
    private function extractAuthResult(string $authHeader, string $type, array $allowedValues): ?string
    {
        // Pattern: "spf=pass" or "spf=fail (" or "spf=softfail;"
        $pattern = '/' . preg_quote($type, '/') . '=([a-z]+)/i';

        if (preg_match($pattern, $authHeader, $matches)) {
            $result = strtoupper($matches[1]);

            // Validate against allowed ENUM values
            if (in_array($result, $allowedValues, true)) {
                return $result;
            }
        }

        return null;
    }

    /**
     * Extract email address from a specific header field.
     *
     * Handles formats like:
     * - "email@domain.com"
     * - "Name <email@domain.com>"
     * - "<email@domain.com>"
     *
     * @param array<string, mixed> $headers    Message headers
     * @param string               $headerName Header field name (from, reply-to, return-path)
     *
     * @return ?string Email address (lowercase) or null if not found/invalid
     */
    private function extractEmailFromHeader(array $headers, string $headerName): ?string
    {
        $value = $headers[$headerName] ?? null;

        if ($value === null || $value === '' || !is_string($value)) {
            return null;
        }

        // Clean email: "Name <email@domain.com>" → "email@domain.com"
        if (preg_match('/<([^>]+)>/', $value, $matches)) {
            $email = $matches[1];
        } else {
            $email = $value;
        }

        $email = trim($email);

        // Validate email format (basic)
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return null;
        }

        return strtolower($email);
    }
}
