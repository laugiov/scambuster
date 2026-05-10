<?php

declare(strict_types=1);

namespace App\Infrastructure\Mailer;

use Symfony\Component\Mailer\Transport;
use Symfony\Component\Mailer\Transport\TransportInterface;

/**
 * Wraps Symfony Mailer's static Transport::fromDsn() factory.
 *
 * Exists to:
 * - allow dependency injection / mocking in tests
 * - centralize DSN validation
 * - normalize error messages
 */
final class TransportFactory
{
    /**
     * Create a TransportInterface from a DSN string.
     *
     * @throws \InvalidArgumentException on invalid or empty DSN
     */
    public function fromDsn(string $dsn): TransportInterface
    {
        if ($dsn === '') {
            throw new \InvalidArgumentException('Cannot create transport from empty DSN');
        }

        try {
            return Transport::fromDsn($dsn);
        } catch (\Throwable $e) {
            throw new \InvalidArgumentException(
                sprintf('Invalid mailer DSN: %s', $e->getMessage()),
                previous: $e,
            );
        }
    }
}
