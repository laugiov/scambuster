<?php

declare(strict_types=1);

namespace App\Domain\Exception;

/**
 * Exception thrown when a domain business rule is violated.
 *
 * Used in domain entities (Campaign, CampaignRule, etc.)
 * to signal business constraint violations.
 */
class DomainException extends \DomainException
{
}
