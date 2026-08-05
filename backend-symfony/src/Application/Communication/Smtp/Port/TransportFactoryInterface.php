<?php

declare(strict_types=1);

namespace App\Application\Communication\Smtp\Port;

use Symfony\Component\Mailer\Transport\TransportInterface;

/**
 * Port for building a mail transport from a DSN, so the Application layer no
 * longer depends on the concrete Infrastructure mailer factory.
 */
interface TransportFactoryInterface
{
    public function fromDsn(string $dsn): TransportInterface;
}
