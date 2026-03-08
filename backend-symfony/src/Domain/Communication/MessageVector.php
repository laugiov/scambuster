<?php

declare(strict_types=1);

namespace App\Domain\Communication;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'message_vector')]
class MessageVector
{
    #[ORM\Id]
    #[ORM\Column(name: 'vector_id', type: 'uuid', unique: true)]
    private string $vectorId;

    #[ORM\Column(type: 'json')]
    private array $embedding;

    #[ORM\Column(name: 'model_name', type: 'string', length: 64)]
    private string $modelName;

    #[ORM\Column(type: 'integer')]
    private int $dim;

    #[ORM\Column(name: 'ts_created', type: 'datetime_immutable')]
    private \DateTimeImmutable $tsCreated;

    public function __construct(
        string $vectorId,
        array $embedding,
        string $modelName,
        int $dim,
        ?\DateTimeImmutable $tsCreated = null
    ) {
        $this->vectorId = $vectorId;
        $this->embedding = $embedding;
        $this->modelName = $modelName;
        $this->dim = $dim;
        $this->tsCreated = $tsCreated ?? new \DateTimeImmutable();
    }

    public function getVectorId(): string
    {
        return $this->vectorId;
    }

    public function getEmbedding(): array
    {
        return $this->embedding;
    }

    public function getModelName(): string
    {
        return $this->modelName;
    }

    public function getDim(): int
    {
        return $this->dim;
    }

    public function getTsCreated(): \DateTimeImmutable
    {
        return $this->tsCreated;
    }
}
