<?php

declare(strict_types=1);

namespace App\Domain\Communication\ValueObject;

/**
 * Immutable Value Object representing an RFC-compliant email address.
 *
 * Normalizes to lowercase and trims whitespace.
 * Validates via PHP's filter_var with FILTER_VALIDATE_EMAIL.
 */
final readonly class EmailAddress
{
    public string $value;

    public function __construct(string $value)
    {
        $normalized = strtolower(trim($value));

        if ($normalized === '') {
            throw new \InvalidArgumentException('Email address cannot be empty');
        }

        if (!filter_var($normalized, \FILTER_VALIDATE_EMAIL)) {
            throw new \InvalidArgumentException(sprintf('Invalid email address: "%s"', $value));
        }

        $this->value = $normalized;
    }

    public function __toString(): string
    {
        return $this->value;
    }

    public function getDomain(): string
    {
        $atPos = strrpos($this->value, '@');
        \assert($atPos !== false, 'Validated email always contains @');

        return substr($this->value, $atPos + 1);
    }

    public function equals(self $other): bool
    {
        return $this->value === $other->value;
    }
}
