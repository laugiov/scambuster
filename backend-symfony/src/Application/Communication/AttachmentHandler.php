<?php

declare(strict_types=1);

namespace App\Application\Communication;

use App\Domain\Communication\Attachment;
use App\Domain\Communication\Conversation;
use Doctrine\ORM\EntityManagerInterface;

class AttachmentHandler
{
    public function __construct(private EntityManagerInterface $em)
    {
    }

    public function deleteAttachment(string $attachmentId): bool
    {
        $attachment = $this->em->getRepository(Attachment::class)->find($attachmentId);

        if (!$attachment) {
            return false;
        }
        $this->em->remove($attachment);
        $this->em->flush();

        return true;
    }

    /**
     * @return Attachment[]
     */
    public function listConversationAttachments(string $convId): array
    {
        $conversation = $this->em->getRepository(Conversation::class)->find($convId);

        if (!$conversation) {
            return [];
        }
        $messages = $this->em->getRepository('App\\Domain\\Communication\\Message')->findBy(['conversation' => $conversation]);

        if (!$messages) {
            return [];
        }

        return $this->em->getRepository(Attachment::class)->findBy(['message' => $messages]);
    }

    public function getAttachment(string $attachmentId): ?Attachment
    {
        return $this->em->getRepository(Attachment::class)->find($attachmentId);
    }

    public function getConversation(string $convId): ?Conversation
    {
        return $this->em->getRepository(Conversation::class)->find($convId);
    }
}
