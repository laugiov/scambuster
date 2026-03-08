<?php

declare(strict_types=1);

namespace App\Domain\Exception;

/**
 * Exception levée lorsqu'une règle métier du domaine est violée.
 *
 * Utilisée dans les entités du domaine (Campaign, CampaignRule, etc.)
 * pour signaler des violations de contraintes métier.
 */
class DomainException extends \DomainException
{
}
