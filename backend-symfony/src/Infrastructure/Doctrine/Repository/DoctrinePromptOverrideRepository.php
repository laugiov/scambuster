<?php

declare(strict_types=1);

namespace App\Infrastructure\Doctrine\Repository;

use App\Domain\Prompt\PromptOverride;
use App\Domain\Prompt\PromptOverrideRepositoryInterface;
use Doctrine\ORM\EntityManagerInterface;

final readonly class DoctrinePromptOverrideRepository implements PromptOverrideRepositoryInterface
{
    public function __construct(
        private EntityManagerInterface $em,
    ) {
    }

    public function findByKey(string $promptKey): ?PromptOverride
    {
        return $this->em->getRepository(PromptOverride::class)->findOneBy(['promptKey' => $promptKey]);
    }

    public function findAll(): array
    {
        return $this->em->getRepository(PromptOverride::class)->findBy([], ['promptKey' => 'ASC']);
    }

    public function findAllEnabled(): array
    {
        return $this->em->getRepository(PromptOverride::class)->findBy(['enabled' => true]);
    }

    public function save(PromptOverride $override): void
    {
        $this->em->persist($override);
        $this->em->flush();
    }

    public function delete(PromptOverride $override): void
    {
        $this->em->remove($override);
        $this->em->flush();
    }
}
