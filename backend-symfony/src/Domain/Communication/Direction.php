<?php

declare(strict_types=1);

namespace App\Domain\Communication;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'lkp_direction')]
#[ORM\UniqueConstraint(columns: ['code'])]
class Direction
{
    #[ORM\Id]
    #[ORM\Column(name: 'dir_id', type: 'integer')]
    #[ORM\GeneratedValue(strategy: 'SEQUENCE')]
    private int $directionId; // @phpstan-ignore-line

    #[ORM\Column(type: 'string', length: 16, unique: true)]
    private string $code;

    #[ORM\Column(name: 'label_en', type: 'string', length: 32)]
    private string $labelEn;

    #[ORM\Column(name: 'label_fr', type: 'string', length: 32)]
    private string $labelFr;

    public function __construct(string $code, string $labelEn, string $labelFr)
    {
        $this->code = $code;
        $this->labelEn = $labelEn;
        $this->labelFr = $labelFr;
    }

    public function getDirectionId(): int
    {
        return $this->directionId;
    }

    public function getCode(): string
    {
        return $this->code;
    }

    public function getLabelEn(): string
    {
        return $this->labelEn;
    }

    public function getLabelFr(): string
    {
        return $this->labelFr;
    }
}
