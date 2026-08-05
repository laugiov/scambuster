<?php

declare(strict_types=1);

namespace App\Application\Prompt\Exception;

/**
 * Thrown when an operation targets a prompt key that is not in the PromptCatalog.
 * The HTTP layer maps this to 404.
 */
final class UnknownPromptKeyException extends \RuntimeException
{
    public function __construct(string $key)
    {
        parent::__construct(sprintf("Unknown prompt key '%s'.", $key));
    }
}
