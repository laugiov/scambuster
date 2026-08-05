<?php

declare(strict_types=1);

namespace App\Application\Prompt\Exception;

final class CanaryJobNotFoundException extends \RuntimeException
{
    public function __construct(int $jobId)
    {
        parent::__construct(sprintf('Canary job %d not found.', $jobId));
    }
}
