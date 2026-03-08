<?php

declare(strict_types=1);

namespace App\DataFixtures\Communication;

use App\Domain\Communication\Attachment;
use App\Domain\Communication\Message;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Common\DataFixtures\DependentFixtureInterface;
use Doctrine\Persistence\ObjectManager;

class AttachmentFixtures extends Fixture implements DependentFixtureInterface
{
    public function load(ObjectManager $manager): void
    {
        $messages = $manager->getRepository(Message::class)->findAll();

        if (!$messages) {
            return;
        }

        foreach ($messages as $i => $message) {
            $attachment = new Attachment(
                sprintf('00000000-0000-0000-0000-%012d', $i + 1),
                $message,
                'test.pdf',
                'application/pdf',
                12345,
                bin2hex(random_bytes(32)),
                'attachments/test.pdf',
                null,
                'pending',
                null,
                null,
                new \DateTimeImmutable('-1 hour'),
                null
            );
            $manager->persist($attachment);
            $attachment2 = new Attachment(
                sprintf('00000000-0000-0000-0000-%012d', $i + 101),
                $message,
                'image.png',
                'image/png',
                54321,
                bin2hex(random_bytes(32)),
                'attachments/image.png',
                null,
                'clean',
                'Extracted text from image',
                null,
                new \DateTimeImmutable('-30 minutes'),
                null
            );
            $manager->persist($attachment2);
        }
        // Ajoute un attachment soft-deleted
        $firstMessage = $messages[0];
        $attachmentSoft = new Attachment(
            '00000000-0000-0000-0000-999999999999',
            $firstMessage,
            'deleted.txt',
            'text/plain',
            100,
            bin2hex(random_bytes(32)),
            'attachments/deleted.txt',
            'key-123',
            'malicious',
            null,
            null,
            new \DateTimeImmutable('-5 minutes'),
            new \DateTimeImmutable('-1 minute')
        );
        $manager->persist($attachmentSoft);
        $manager->flush();
    }

    public function getDependencies(): array
    {
        return [
            MessageFixtures::class,
        ];
    }
}
