<?php

declare(strict_types=1);

namespace App\Infrastructure\EventListener\Security;

use Monolog\LogRecord;
use Monolog\Processor\ProcessorInterface;

/**
 * Monolog processor that masks PII (emails, IPv4 addresses) in log records.
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

        return (string) preg_replace(self::IPV4_PATTERN, '$1.***', $value);
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
