<?php

declare(strict_types=1);

namespace App\Infrastructure\Doctrine\Repository;

use App\Domain\Communication\Persona;
use App\Domain\Communication\PersonaRepositoryInterface;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<Persona> */
class PersonaRepository extends ServiceEntityRepository implements PersonaRepositoryInterface
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Persona::class);
    }

    public function findByCode(string $code): ?Persona
    {
        return $this->findOneBy(['personaCode' => $code]);
    }

    /** @return array<Persona> */
    public function findActive(): array
    {
        return $this->findBy(['isActive' => true]);
    }

    /** @return array<Persona> */
    public function findAll(): array
    {
        return parent::findAll();
    }
}
