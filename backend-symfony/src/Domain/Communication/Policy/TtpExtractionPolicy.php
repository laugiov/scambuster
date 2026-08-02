<?php

declare(strict_types=1);

namespace App\Domain\Communication\Policy;

use App\Domain\Communication\Message;

/**
 * TTP extraction policy.
 *
 * Rule: TTP extraction is allowed only on incoming messages.
 * TTPs describe the scammer's behaviour; outgoing messages are our own
 * generated replies and must never be tagged.
 *
 * Stateless service: safe to inject as a singleton.
 */
final class TtpExtractionPolicy
{
    public function allows(Message $message): bool
    {
        return $message->getDirection()->getCode() === 'in';
    }
}
