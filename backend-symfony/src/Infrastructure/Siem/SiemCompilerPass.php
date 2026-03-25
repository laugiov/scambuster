<?php

declare(strict_types=1);

namespace App\Infrastructure\Siem;

use App\Application\Audit\Port\SiemEventFormatterInterface;
use App\Application\Audit\Port\SiemExporterInterface;
use App\Infrastructure\Siem\Adapter\FileSiemExporter;
use App\Infrastructure\Siem\Adapter\NullSiemExporter;
use App\Infrastructure\Siem\Adapter\SyslogSiemExporter;
use App\Infrastructure\Siem\Formatter\CefFormatter;
use App\Infrastructure\Siem\Formatter\EcsFormatter;
use App\Infrastructure\Siem\Formatter\JsonFormatter;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Reference;

/**
 * Selects the SIEM exporter + formatter based on SIEM_PROVIDER env var.
 *
 * Supported providers:
 * - none    : NullSiemExporter (default, zero overhead)
 * - file    : FileSiemExporter (NDJSON output)
 * - syslog  : SyslogSiemExporter (RFC 5424 UDP/TCP)
 *
 * Format auto-detection:
 * - file    → json
 * - syslog  → cef
 * Override with SIEM_FORMAT env var.
 */
final class SiemCompilerPass implements CompilerPassInterface
{
    /** @var array<string, string> Provider → default format */
    private const FORMAT_DEFAULTS = [
        'none' => 'json',
        'file' => 'json',
        'syslog' => 'cef',
    ];

    /** @var array<string, class-string<SiemEventFormatterInterface>> */
    private const FORMATTER_MAP = [
        'cef' => CefFormatter::class,
        'ecs' => EcsFormatter::class,
        'json' => JsonFormatter::class,
    ];

    public function process(ContainerBuilder $container): void
    {
        $provider = $_ENV['SIEM_PROVIDER'] ?? 'none';

        // Register formatter
        $format = $_ENV['SIEM_FORMAT'] ?? (self::FORMAT_DEFAULTS[$provider] ?? 'json');
        $formatterClass = self::FORMATTER_MAP[$format] ?? JsonFormatter::class;

        $container->setDefinition(SiemEventFormatterInterface::class, new Definition($formatterClass));

        // Register exporter
        if ($provider === 'none' || $provider === '') {
            $container->setDefinition(SiemExporterInterface::class, new Definition(NullSiemExporter::class));

            return;
        }

        $endpoint = $_ENV['SIEM_ENDPOINT'] ?? '';

        if ($endpoint === '') {
            // No endpoint configured — fall back to null
            $container->setDefinition(SiemExporterInterface::class, new Definition(NullSiemExporter::class));

            return;
        }

        match ($provider) {
            'file' => $this->registerFileExporter($container, $endpoint),
            'syslog' => $this->registerSyslogExporter($container, $endpoint),
            default => $container->setDefinition(SiemExporterInterface::class, new Definition(NullSiemExporter::class)),
        };
    }

    private function registerFileExporter(ContainerBuilder $container, string $endpoint): void
    {
        $definition = new Definition(FileSiemExporter::class, [
            new Reference(SiemEventFormatterInterface::class),
            new Reference('logger'),
            $endpoint,
        ]);

        $container->setDefinition(SiemExporterInterface::class, $definition);
    }

    private function registerSyslogExporter(ContainerBuilder $container, string $endpoint): void
    {
        $definition = new Definition(SyslogSiemExporter::class, [
            new Reference(SiemEventFormatterInterface::class),
            new Reference('logger'),
            $endpoint,
        ]);

        $container->setDefinition(SiemExporterInterface::class, $definition);
    }
}
