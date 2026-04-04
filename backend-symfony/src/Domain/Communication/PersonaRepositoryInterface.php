<?php

declare(strict_types=1);

namespace App\Domain\Communication;

interface PersonaRepositoryInterface
{
    public function findByCode(string $code): ?Persona;

    /** @return array<Persona> */
    public function findActive(): array;

    /** @return array<Persona> */
    public function findAll(): array;
}
