<?php

declare(strict_types=1);

namespace App\Application\Communication\Exception;

/**
 * Spec 082 §US2.2 — thrown by ReplyCompositionService::markAsSent when a
 * caller asserts a provider_msg_id that does not match the one already
 * recorded for an already-sent message.
 *
 * The controller MarkReplySentController catches this specific type to
 * return HTTP 400, while the same-id idempotent case returns 204.
 */
final class MarkAsSentConflictException extends \RuntimeException
{
    public function __construct(
        private readonly string $msgId,
        private readonly string $expectedProviderMsgId,
        private readonly string $actualProviderMsgId,
    ) {
        parent::__construct(sprintf(
            'provider_msg_id conflict for message %s: stored=%s, requested=%s',
            $msgId,
            $expectedProviderMsgId,
            $actualProviderMsgId,
        ));
    }

    public function getMsgId(): string
    {
        return $this->msgId;
    }

    public function getExpectedProviderMsgId(): string
    {
        return $this->expectedProviderMsgId;
    }

    public function getActualProviderMsgId(): string
    {
        return $this->actualProviderMsgId;
    }
}
