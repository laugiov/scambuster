<?php

declare(strict_types=1);

namespace App\Application\Communication;

use App\Domain\Communication\Attachment;
use App\Domain\Communication\Channel;
use App\Domain\Communication\Conversation;
use App\Domain\Communication\Direction;
use App\Domain\Communication\Message;
use App\Domain\Communication\ObservedIoc;
use Doctrine\ORM\EntityManagerInterface;

class MessageHandler
{
    public function __construct(private EntityManagerInterface $em)
    {
    }

    /** @param array<string, mixed> $data */
    public function createMessage(array $data): ?Message
    {
        $conversation = $this->em->getRepository(Conversation::class)->find($data['conv_id']);
        $channel = $this->em->getRepository(Channel::class)->find($data['channel_id']);
        $direction = $this->em->getRepository(Direction::class)->findOneBy(['code' => $data['direction']]);

        if (!$conversation || !$channel || !$direction) {
            return null;
        }

        if ($conversation->getStatus()->value === 'closed') {
            throw new \RuntimeException('Cannot add message to closed conversation');
        }
        $msgId = uuid_create(UUID_TYPE_RANDOM);
        $tsMsg = new \DateTimeImmutable($data['ts_msg']);
        $message = new Message(
            $msgId,
            $conversation,
            $channel,
            $direction,
            $data['lang_detect'] ?? 'en',
            $data['subject'] ?? null,
            $data['body_text'],
            $data['body_html'] ?? null,
            $data['headers'],
            bin2hex(random_bytes(32)),
            null, // vector_id
            null, // reply_to
            $tsMsg,
            $tsMsg,
            null // deleted_at
        );
        $this->em->persist($message);
        $this->em->flush();

        return $message;
    }

    public function getMessage(string $msgId): ?Message
    {
        return $this->em->getRepository(Message::class)->find($msgId);
    }

    public function deleteMessage(string $msgId): bool
    {
        $message = $this->em->getRepository(Message::class)->find($msgId);

        if (!$message) {
            return false;
        }
        $vector = $this->em->getRepository('App\\Domain\\Communication\\MessageVector')->findOneBy(['vectorId' => $msgId]);

        if ($vector) {
            $this->em->remove($vector);
        }
        $this->em->remove($message);
        $this->em->flush();

        return true;
    }

    /** @param array<string, mixed> $data */
    public function patchMessage(string $msgId, array $data): Message|null|false
    {
        $message = $this->em->getRepository(Message::class)->find($msgId);

        if (!$message) {
            return null;
        }

        $updated = false;

        if (array_key_exists('body_text', $data)) {
            $message->setBodyText($data['body_text']);
            $updated = true;
        }

        if (array_key_exists('subject', $data)) {
            $message->setSubject($data['subject']);
            $updated = true;
        }

        if (array_key_exists('headers', $data)) {
            $message->setHeaders($data['headers']);
            $updated = true;
        }

        if (array_key_exists('body_html', $data)) {
            $message->setBodyHtml($data['body_html']);
            $updated = true;
        }

        if (array_key_exists('ts_msg', $data)) {
            $message->setTsMsg(new \DateTimeImmutable($data['ts_msg']));
            $updated = true;
        }

        if (array_key_exists('direction', $data)) {
            $dir = $this->em->getRepository(Direction::class)->findOneBy(['code' => $data['direction']]);

            if (!$dir) {
                throw new \RuntimeException('Invalid direction');
            }
            $message->setDirection($dir);
            $updated = true;
        }

        if ($updated) {
            $this->em->flush();

            return $message;
        }

        return false;
    }

    /** @return array<int, Attachment> */
    public function getMessageAttachments(string $msgId): array
    {
        $message = $this->em->getRepository(Message::class)->find($msgId);

        if (!$message) {
            return [];
        }

        return $this->em->getRepository(Attachment::class)->findBy(['message' => $message]);
    }

    /** @return array<int, ObservedIoc> */
    public function getMessageIocs(string $msgId): array
    {
        $message = $this->em->getRepository(Message::class)->find($msgId);

        if (!$message) {
            return [];
        }

        return $this->em->getRepository(ObservedIoc::class)->findBy(['message' => $message]);
    }

    public function addAttachmentToMessage(Message $message, \Symfony\Component\HttpFoundation\File\UploadedFile $file): Attachment
    {
        $attachment = new Attachment(
            uuid_create(UUID_TYPE_RANDOM),
            $message,
            $file->getClientOriginalName(),
            $file->getMimeType(),
            $file->getSize(),
            bin2hex(random_bytes(32)), // content_hash placeholder
            null, // s3Key
            null, // encKeyId
            'pending', // avStatus
            null, // ocrText
            null, // vectorId
            new \DateTimeImmutable(), // tsIngest
            null // deletedAt
        );
        $message->addAttachment($attachment);
        $this->em->persist($attachment);
        $this->em->flush();

        return $attachment;
    }

    public function getMessageByMessageId(string $messageId): ?Message
    {
        // On cherche dans les headers car le message-id est stocké là
        $qb = $this->em->createQueryBuilder();
        $qb->select('m')
            ->from(Message::class, 'm')
            ->where('JSONB_EXTRACT_PATH_TEXT(m.headers, \'message-id\') = :messageId')
            ->andWhere('m.deletedAt IS NULL')
            ->setParameter('messageId', $messageId)
            ->setMaxResults(1);

        return $qb->getQuery()->getOneOrNullResult();
    }
}
