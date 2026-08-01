<?php

declare(strict_types=1);

namespace App\Tests\Unit\Domain\Communication;

use App\Domain\Communication\Direction;
use PHPUnit\Framework\TestCase;

class DirectionTest extends TestCase
{
    public function test_it_creates_direction_with_labels(): void
    {
        $direction = new Direction('in', 'Incoming (attacker ➜ platform)', 'Entrant');

        $this->assertSame('in', $direction->getCode());
        $this->assertSame('Incoming (attacker ➜ platform)', $direction->getLabelEn());
        $this->assertSame('Entrant', $direction->getLabelFr());
    }
} 