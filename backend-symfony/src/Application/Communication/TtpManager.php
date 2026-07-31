<?php

declare(strict_types=1);

namespace App\Application\Communication;

use App\Domain\Communication\Ttp;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Manage read operations for Ttp taxonomy entities
 */
class TtpManager
{
    public function __construct(
        private readonly EntityManagerInterface $em
    ) {
    }

    /**
     * Find TTP by code (case-insensitive)
     */
    public function findByCode(string $code): ?Ttp
    {
        // Normalize code to uppercase to match database format (SB-T001, SB-T002, etc.)
        $normalizedCode = strtoupper($code);

        return $this->em->getRepository(Ttp::class)->findOneBy([
            'code' => $normalizedCode,
        ]);
    }

    /**
     * Get all active TTPs ordered by code
     *
     * @return list<Ttp>
     */
    public function allActive(): array
    {
        /** @var list<Ttp> $result */
        $result = $this->em->createQueryBuilder()
            ->select('t')
            ->from(Ttp::class, 't')
            ->where('t.active = true')
            ->orderBy('t.code', 'ASC')
            ->getQuery()
            ->getResult();

        return $result;
    }

    /**
     * Get all TTP codes
     *
     * @return string[]
     */
    public function getAllCodes(): array
    {
        /** @var array<int, array{code: string}> $result */
        $result = $this->em->createQueryBuilder()
            ->select('t.code')
            ->from(Ttp::class, 't')
            ->orderBy('t.code', 'ASC')
            ->getQuery()
            ->getResult();

        return array_column($result, 'code');
    }
}
