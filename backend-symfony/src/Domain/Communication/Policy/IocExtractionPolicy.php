<?php

declare(strict_types=1);

namespace App\Domain\Communication\Policy;

use App\Domain\Communication\Message;

/**
 * IOC extraction policy.
 *
 * Extracted from Message::canExtractIocs() to decouple the policy
 * decision from the entity. The entity should hold state; the policy
 * should hold business rules.
 *
 * Rule: IOC extraction is allowed only on incoming messages.
 * Outgoing messages are ScamBuster-generated replies with zero
 * attacker-controlled IOCs.
 *
 * Stateless service: safe to inject as a singleton.
 */
final class IocExtractionPolicy
{
    public function allows(Message $message): bool
    {
        return $message->getDirection()->getCode() === 'in';
    }
}
