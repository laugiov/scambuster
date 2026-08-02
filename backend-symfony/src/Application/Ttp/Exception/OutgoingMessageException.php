<?php

declare(strict_types=1);

namespace App\Application\Ttp\Exception;

/**
 * Raised when TTP extraction is requested on an outgoing message.
 *
 * TTPs describe the scammer's behaviour; outgoing messages are our own
 * generated replies and must never be tagged. The HTTP layer maps this
 * to a 400 response mirroring the IOC extraction refusal shape.
 */
final class OutgoingMessageException extends \RuntimeException
{
    public function __construct(
        private readonly string $msgId,
        private readonly string $direction,
    ) {
        parent::__construct(sprintf(
            'TTP extraction is not allowed on outgoing messages (msg_id=%s, direction=%s)',
            $msgId,
            $direction,
        ));
    }

    public function getMsgId(): string
    {
        return $this->msgId;
    }

    public function getDirection(): string
    {
        return $this->direction;
    }
}
