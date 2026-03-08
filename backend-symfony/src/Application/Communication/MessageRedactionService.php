<?php

declare(strict_types=1);

namespace App\Application\Communication;

class MessageRedactionService
{
    /**
     * Redact sensitive headers for monitoring/export.
     *
     * @param array $headers Original headers array
     *
     * @return array Redacted headers array
     */
    public function redactHeaders(array $headers): array
    {
        $redacted = $headers;

        foreach (['From', 'To', 'X-Originating-IP', 'Received'] as $key) {
            if (isset($redacted[$key])) {
                $redacted[$key] = '[REDACTED]';
            }
        }

        return $redacted;
    }
}
