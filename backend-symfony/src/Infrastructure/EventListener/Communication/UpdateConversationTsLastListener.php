<?php

declare(strict_types=1);

namespace App\Infrastructure\EventListener\Communication;

use App\Domain\Communication\Message;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\Event\LifecycleEventArgs;

class UpdateConversationTsLastListener
{
    /**
     * After a Message is persisted, update the parent Conversation's tsLast field if the message timestamp is newer.
     *
     * @param LifecycleEventArgs<EntityManagerInterface> $args
     */
    public function postPersist(Message $message, LifecycleEventArgs $args): void
    {
        $conversation = $message->getConversation();

        if ($message->getTsMsg() > $conversation->getTsLast()) {
            $conversation->setTsLast($message->getTsMsg());
            $em = $args->getObjectManager();
            $em->persist($conversation);
            $em->flush();
        }
    }
}
