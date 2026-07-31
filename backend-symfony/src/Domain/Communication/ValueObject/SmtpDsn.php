<?php

declare(strict_types=1);

namespace App\Domain\Communication\ValueObject;

/**
 * Immutable Value Object representing a Symfony Mailer DSN.
 *
 * Validates that the DSN uses a recognized mailer scheme (smtp, smtps, sendmail, null).
 * Other schemes (http, file, etc.) are rejected.
 */
final readonly class SmtpDsn
{
    private const ALLOWED_SCHEMES = ['smtp', 'smtps', 'sendmail', 'null'];

    public function __construct(
        public string $value,
    ) {
        if ($value === '') {
            throw new \InvalidArgumentException('SMTP DSN cannot be empty');
        }

        $parts = parse_url($value);

        if ($parts === false || !isset($parts['scheme'])) {
            throw new \InvalidArgumentException(sprintf('SMTP DSN is malformed: %s', self::redact($value)));
        }

        if (!\in_array($parts['scheme'], self::ALLOWED_SCHEMES, true)) {
            throw new \InvalidArgumentException(sprintf(
                'SMTP DSN must use a valid mailer scheme (%s), got "%s"',
                implode(', ', self::ALLOWED_SCHEMES),
                $parts['scheme'],
            ));
        }

        if (!isset($parts['host']) || $parts['host'] === '') {
            throw new \InvalidArgumentException('SMTP DSN must include a host');
        }
    }

    public function __toString(): string
    {
        return $this->value;
    }

    public function equals(self $other): bool
    {
        return $this->value === $other->value;
    }

    /**
     * Returns the DSN with credentials redacted, safe for logs.
     */
    public function redacted(): string
    {
        return self::redact($this->value);
    }

    private static function redact(string $dsn): string
    {
        return (string) preg_replace('#://[^@]+@#', '://***:***@', $dsn);
    }
}
