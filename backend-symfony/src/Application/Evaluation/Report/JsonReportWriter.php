<?php

declare(strict_types=1);

namespace App\Application\Evaluation\Report;

/**
 * Writes evaluation reports as JSON files.
 */
final class JsonReportWriter
{
    /**
     * Write data to a JSON file with pretty printing.
     *
     * @param array<string, mixed> $data
     */
    public function write(array $data, string $outputPath): void
    {
        $dir = dirname($outputPath);

        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        if ($json === false) {
            throw new \RuntimeException('Failed to encode report as JSON: ' . json_last_error_msg());
        }

        file_put_contents($outputPath, $json . "\n");
    }
}
