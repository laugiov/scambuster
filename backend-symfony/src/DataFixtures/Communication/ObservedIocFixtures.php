<?php

declare(strict_types=1);

namespace App\DataFixtures\Communication;

use App\Domain\Communication\Message;
use App\Domain\Communication\ObservedIoc;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Persistence\ObjectManager;

class ObservedIocFixtures extends Fixture
{
    public function load(ObjectManager $manager): void
    {
        // An existing message is required for the FK
        $message = $manager->getRepository(Message::class)->findOneBy([]);

        if (!$message) {
            // No message in the database, cannot create the ObservedIoc fixture
            return;
        }
        $ioc = new ObservedIoc(
            '00000000-0000-0000-0000-000000000002',
            $message,
            '11111111-1111-1111-1111-111111111111', // example ioc_id
            ['context' => 'found in body'],
            new \DateTimeImmutable('-2 hours')
        );
        $manager->persist($ioc);
        $manager->flush();
    }
}
