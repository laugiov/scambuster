<?php

declare(strict_types=1);

namespace App\DataFixtures\Communication;

use App\Domain\Communication\MessageVector;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Persistence\ObjectManager;

class MessageVectorFixtures extends Fixture
{
    public function load(ObjectManager $manager): void
    {
        $vector = new MessageVector(
            '00000000-0000-0000-0000-000000000001',
            array_fill(0, 512, 0.123), // exemple d'embedding
            'test-model',
            512,
            new \DateTimeImmutable('-1 day')
        );
        $manager->persist($vector);
        $manager->flush();
    }
}
