<?php

declare(strict_types=1);

namespace App\Application\Communication;

use App\Domain\Communication\ObservedIoc;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Fetches all ObservedIoc entities and flushes after enrichment.
 */
final class IocExportMetadataMigrationService
{
    public function __construct(
        private readonly EntityManagerInterface $em,
    ) {
    }

    /**
     * @return list<ObservedIoc>
     */
    public function findAllIocs(): array
    {
        /** @var list<ObservedIoc> $iocs */
        $iocs = $this->em->getRepository(ObservedIoc::class)->findAll();

        return $iocs;
    }

    public function flush(): void
    {
        $this->em->flush();
    }
}
