<?php

declare(strict_types=1);

namespace App\DataFixtures\Communication;

use App\Domain\Communication\Channel;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Bundle\FixturesBundle\FixtureGroupInterface;
use Doctrine\Persistence\ObjectManager;

class ChannelFixtures extends Fixture implements FixtureGroupInterface
{
    /** Reference/lookup data — loadable on its own for the lightweight demo seed. */
    public static function getGroups(): array
    {
        return ['reference'];
    }

    public function load(ObjectManager $manager): void
    {
        $channels = [
            ['code' => 'email', 'label_en' => 'Email', 'label_fr' => 'Courriel'],
            ['code' => 'sms', 'label_en' => 'SMS', 'label_fr' => 'SMS'],
            ['code' => 'whatsapp', 'label_en' => 'WhatsApp', 'label_fr' => 'WhatsApp'],
            ['code' => 'telegram', 'label_en' => 'Telegram', 'label_fr' => 'Telegram'],
            ['code' => 'phone', 'label_en' => 'Phone', 'label_fr' => 'Téléphone'],
        ];

        foreach ($channels as $data) {
            $channel = new Channel($data['code'], $data['label_en'], $data['label_fr']);
            $manager->persist($channel);
        }

        $manager->flush();
    }
}
