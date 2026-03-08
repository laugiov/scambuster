<?php

declare(strict_types=1);

namespace App\DataFixtures\Communication;

use App\Domain\Communication\Direction;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Persistence\ObjectManager;

class DirectionFixtures extends Fixture
{
    public function load(ObjectManager $manager): void
    {
        $directions = [
            ['code' => 'in', 'label_en' => 'Inbound', 'label_fr' => 'Entrant'],
            ['code' => 'out', 'label_en' => 'Outbound', 'label_fr' => 'Sortant'],
        ];

        foreach ($directions as $data) {
            $direction = new Direction($data['code'], $data['label_en'], $data['label_fr']);
            $manager->persist($direction);
        }

        $manager->flush();
    }
}
