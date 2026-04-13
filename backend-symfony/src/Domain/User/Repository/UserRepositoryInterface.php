<?php

declare(strict_types=1);

namespace App\Domain\User\Repository;

use App\Domain\User\User;

interface UserRepositoryInterface
{
    public function findByEmail(string $email): ?User;

    public function save(User $user): void;
}
