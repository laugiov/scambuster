<?php

declare(strict_types=1);

namespace App\Application\Communication\Exception;

/**
 * A reply was refused because of what the inbound message is, not because
 * anything failed.
 *
 * This is distinct from the generic RuntimeException the reply path throws for
 * real errors, and the distinction is load-bearing: the intake workflow calls
 * reply generation inside a batch loop whose node has no error branch
 * (`Trigger Reply Generation` in WF-INTAKE-EMAIL-V2), so a non-2xx response
 * aborts the loop and the remaining IMAP items of that batch are never
 * ingested. A refusal is permanently unsatisfiable for this message — retrying
 * it is pointless and dropping the rest of the batch to say so is worse than
 * the refusal it reports.
 *
 * Callers should render this as a successful "skipped" outcome carrying
 * `getReason()`, not as an error.
 */
final class ReplyRefusedException extends \RuntimeException
{
    public function __construct(private readonly string $reason, string $message)
    {
        parent::__construct($message);
    }

    /**
     * Machine-readable refusal reason, e.g. `auto_submitted`, `self_addressed`.
     */
    public function getReason(): string
    {
        return $this->reason;
    }
}
