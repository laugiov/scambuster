<?php

declare(strict_types=1);

namespace App\Domain\Prompt;

/**
 * Lifecycle of a prompt-canary validation job.
 *
 * SUCCEEDED means the canary RAN to completion and produced a verdict — the verdict itself may
 * still report a regression (`ok=false`). FAILED means the job could not run (an error before a
 * verdict was produced). The two are distinct so the UI can tell "the check ran and flagged a
 * regression" from "the check itself broke".
 */
enum CanaryJobStatus: string
{
    case PENDING = 'pending';
    case RUNNING = 'running';
    case SUCCEEDED = 'succeeded';
    case FAILED = 'failed';
}
