<?php

declare(strict_types=1);

namespace App\Application\Prompt\Exception;

/**
 * Thrown when an operator override is invalid (empty, or missing a required
 * placeholder for its key). The HTTP layer maps this to 422.
 */
final class InvalidPromptOverrideException extends \RuntimeException
{
}
