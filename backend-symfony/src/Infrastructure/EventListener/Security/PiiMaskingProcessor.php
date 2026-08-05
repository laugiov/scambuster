<?php

declare(strict_types=1);

namespace App\Infrastructure\EventListener\Security;

use Monolog\LogRecord;
use Monolog\Processor\ProcessorInterface;

/**
 * Monolog processor that masks PII / financial identifiers (emails, IPv4
 * addresses, IBANs, payment-card numbers, crypto wallets) in log records.
 *
 * This is a defense-in-depth backstop: production code must not log raw user
 * content in the first place. The processor limits the blast radius of an
 * accidental leak — over-masking a log line is always safe.
 *
 * Applies to message and context fields. Does NOT affect the audit_log
 * database table (separate persistence mechanism via AuditLogger).
 *
 * OWASP Logging Cheat Sheet: never log PII in plain text.
 */
class PiiMaskingProcessor implements ProcessorInterface
{
    private const EMAIL_PATTERN = '/\b([a-zA-Z0-9._%+\-]{1,3})[a-zA-Z0-9._%+\-]*@([a-zA-Z0-9.\-]+\.[a-zA-Z]{2,})\b/';
    private const IPV4_PATTERN = '/\b(\d{1,3}\.\d{1,3}\.\d{1,3})\.\d{1,3}\b/';
    // IBAN: 2-letter country + 2 check digits + up to 30 alnum. Keep the
    // country/check prefix, redact the account-identifying remainder.
    private const IBAN_PATTERN = '/\b([A-Z]{2}\d{2})[A-Za-z0-9]{10,30}\b/';
    // Ethereum-style wallet (0x + 40 hex). Keep the 0x tag only.
    private const ETH_WALLET_PATTERN = '/\b0x[a-fA-F0-9]{40}\b/';
    // Payment-card number written as four groups of four digits. Mirrors the
    // app's own credit_card IOC shape; the 4x4 grouping avoids masking bare
    // 13-digit epoch-millis timestamps that a looser \d{13,19} would swallow.
    private const CARD_PATTERN = '/\b(\d{4})[\s\-]?\d{4}[\s\-]?\d{4}[\s\-]?\d{4}\b/';

    public function __invoke(LogRecord $record): LogRecord
    {
        $maskedMessage = $this->maskString($record->message);
        $maskedContext = $this->maskArray($record->context);

        if ($maskedMessage === $record->message && $maskedContext === $record->context) {
            return $record;
        }

        return new LogRecord(
            datetime: $record->datetime,
            channel: $record->channel,
            level: $record->level,
            message: $maskedMessage,
            context: $maskedContext,
            extra: $record->extra,
        );
    }

    private function maskString(string $value): string
    {
        $value = (string) preg_replace(self::EMAIL_PATTERN, '$1***@$2', $value);
        $value = (string) preg_replace(self::IPV4_PATTERN, '$1.***', $value);
        // Card before IBAN: a 16-digit card must not be mistaken for anything
        // else, and neither pattern's match set overlaps the other in practice.
        $value = (string) preg_replace(self::CARD_PATTERN, '$1-****-****-****', $value);
        $value = (string) preg_replace(self::IBAN_PATTERN, '$1****', $value);

        return (string) preg_replace(self::ETH_WALLET_PATTERN, '0x****', $value);
    }

    /**
     * @param array<string, mixed> $data
     *
     * @return array<string, mixed>
     */
    private function maskArray(array $data): array
    {
        $result = [];

        foreach ($data as $key => $value) {
            if (\is_string($value)) {
                $result[$key] = $this->maskString($value);
            } elseif (\is_array($value)) {
                /** @var array<string, mixed> $value */
                $result[$key] = $this->maskArray($value);
            } else {
                $result[$key] = $value;
            }
        }

        return $result;
    }
}
