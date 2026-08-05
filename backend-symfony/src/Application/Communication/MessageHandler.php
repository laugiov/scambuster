<?php

declare(strict_types=1);

namespace App\Application\Communication;

use App\Application\Communication\Dto\AttachmentInput;
use App\Domain\Communication\Attachment;
use App\Domain\Communication\Channel;
use App\Domain\Communication\Conversation;
use App\Domain\Communication\Direction;
use App\Domain\Communication\Message;
use App\Domain\Communication\ObservedIoc;
use Doctrine\ORM\EntityManagerInterface;

class MessageHandler
{
    public function __construct(private readonly EntityManagerInterface $em)
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
        /** @var string $tsMsgStr */
        $tsMsgStr = $data['ts_msg'] ?? 'now';
        $tsMsg = new \DateTimeImmutable($tsMsgStr);
        /** @var array<string, mixed> $headers */
        $headers = $data['headers'] ?? [];
        /** @var string $langDetect */
        $langDetect = $data['lang_detect'] ?? 'en';
        /** @var string|null $subjectVal */
        $subjectVal = $data['subject'] ?? null;
        /** @var string $bodyText */
        $bodyText = $data['body_text'] ?? '';
        /** @var string|null $bodyHtml */
        $bodyHtml = $data['body_html'] ?? null;
        $message = new Message(
            $msgId,
            $conversation,
            $channel,
            $direction,
            $langDetect,
            $subjectVal,
            $bodyText,
            $bodyHtml,
            $headers,
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

        if ($message === null) {
            return false;
        }
        $vector = $this->em->getRepository(\App\Domain\Communication\MessageVector::class)->findOneBy(['vectorId' => $msgId]);

        if ($vector !== null) {
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

        if ($message === null) {
            return null;
        }

        $updated = false;

        if (array_key_exists('body_text', $data)) {
            /** @var string $pBodyText */
            $pBodyText = $data['body_text'];
            $message->setBodyText($pBodyText);
            $updated = true;
        }

        if (array_key_exists('subject', $data)) {
            /** @var string|null $pSubject */
            $pSubject = $data['subject'];
            $message->setSubject($pSubject);
            $updated = true;
        }

        if (array_key_exists('headers', $data)) {
            /** @var array<string, mixed> $patchHeaders */
            $patchHeaders = $data['headers'];
            $message->setHeaders($patchHeaders);
            $updated = true;
        }

        if (array_key_exists('body_html', $data)) {
            /** @var string|null $pBodyHtml */
            $pBodyHtml = $data['body_html'];
            $message->setBodyHtml($pBodyHtml);
            $updated = true;
        }

        if (array_key_exists('ts_msg', $data)) {
            /** @var string $pTsMsg */
            $pTsMsg = $data['ts_msg'];
            $message->setTsMsg(new \DateTimeImmutable($pTsMsg));
            $updated = true;
        }

        if (array_key_exists('direction', $data)) {
            $dir = $this->em->getRepository(Direction::class)->findOneBy(['code' => $data['direction']]);

            if ($dir === null) {
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

        if ($message === null) {
            return [];
        }

        return $this->em->getRepository(Attachment::class)->findBy(['message' => $message]);
    }

    /** @return array<int, ObservedIoc> */
    public function getMessageIocs(string $msgId): array
    {
        $message = $this->em->getRepository(Message::class)->find($msgId);

        if ($message === null) {
            return [];
        }

        return $this->em->getRepository(ObservedIoc::class)->findBy(['message' => $message]);
    }

    public function addAttachmentToMessage(Message $message, AttachmentInput $input): Attachment
    {
        $attachment = new Attachment(
            uuid_create(UUID_TYPE_RANDOM),
            $message,
            $input->originalName,
            $input->mimeType ?? 'application/octet-stream',
            $input->size,
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
        // We search in headers because the message-id is stored there
        $qb = $this->em->createQueryBuilder();
        $qb->select('m')
            ->from(Message::class, 'm')
            ->where('JSONB_EXTRACT_PATH_TEXT(m.headers, \'message-id\') = :messageId')
            ->andWhere('m.deletedAt IS NULL')
            ->setParameter('messageId', $messageId)
            ->setMaxResults(1);

        /** @var Message|null */
        return $qb->getQuery()->getOneOrNullResult();
    }
}
