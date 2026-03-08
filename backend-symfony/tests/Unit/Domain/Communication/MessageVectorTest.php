<?php

declare(strict_types=1);

namespace App\Tests\Unit\Domain\Communication;

use App\Domain\Communication\MessageVector;
use PHPUnit\Framework\TestCase;

class MessageVectorTest extends TestCase
{
    public function testMessageVectorProperties(): void
    {
        $vectorId = '00000000-0000-0000-0000-000000000001';
        $embedding = array_fill(0, 512, 0.123);
        $modelName = 'test-model';
        $dim = 512;
        $tsCreated = new \DateTimeImmutable('-1 day');

        $vector = new MessageVector($vectorId, $embedding, $modelName, $dim, $tsCreated);

        $this->assertSame($vectorId, $vector->getVectorId());
        $this->assertSame($embedding, $vector->getEmbedding());
        $this->assertSame($modelName, $vector->getModelName());
        $this->assertSame($dim, $vector->getDim());
        $this->assertEquals($tsCreated, $vector->getTsCreated());
    }
} 