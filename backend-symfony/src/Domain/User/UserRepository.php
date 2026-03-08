<?php

declare(strict_types=1);

namespace App\Domain\User;

use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<User>
 *
 * @method User|null find(mixed $id, ?int $lockMode = null, ?int $lockVersion = null)
 * @method User|null findOneBy(array $criteria, ?array $orderBy = null)
 * @method User[]    findAll()
 * @method User[]    findBy(array $criteria, ?array $orderBy = null, ?int $limit = null, ?int $offset = null)
 */
class UserRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, User::class);
    }

    /* ------------------------------------------------------------------ */
    /*  You can add all your business queries here                      */
    /* ------------------------------------------------------------------ */
}
