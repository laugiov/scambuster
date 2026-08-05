<?php

declare(strict_types=1);

namespace App\Application\Communication\Dto;

use App\Domain\Communication\Channel;
use App\Domain\Communication\Direction;
use App\Domain\Communication\MailAccount;

/**
 * Value object returned by EntityReferenceResolver.
 *
 * Holds the 3 reference entities needed to create a Message entity:
 * MailAccount, Channel, Direction.
 */
final readonly class ResolvedReferences
{
    public function __construct(
        public MailAccount $account,
        public Channel $channel,
        public Direction $direction,
    ) {
    }
}
