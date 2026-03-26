<?php

declare(strict_types=1);

namespace App\Tests\Unit\Infrastructure\Siem;

use App\Application\Audit\Port\SiemEventFormatterInterface;
use App\Application\Audit\Port\SiemExporterInterface;
use App\Infrastructure\Siem\Adapter\FileSiemExporter;
use App\Infrastructure\Siem\Adapter\NullSiemExporter;
use App\Infrastructure\Siem\Adapter\SyslogSiemExporter;
use App\Infrastructure\Siem\Formatter\CefFormatter;
use App\Infrastructure\Siem\Formatter\JsonFormatter;
use App\Infrastructure\Siem\SiemCompilerPass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
class SiemCompilerPassTest extends TestCase
{
    private ?string $originalProvider = null;
    private ?string $originalEndpoint = null;
    private ?string $originalFormat = null;

    protected function setUp(): void
    {
        $this->originalProvider = $_ENV['SIEM_PROVIDER'] ?? null;
        $this->originalEndpoint = $_ENV['SIEM_ENDPOINT'] ?? null;
        $this->originalFormat = $_ENV['SIEM_FORMAT'] ?? null;
    }

    protected function tearDown(): void
    {
        $this->restoreEnv('SIEM_PROVIDER', $this->originalProvider);
        $this->restoreEnv('SIEM_ENDPOINT', $this->originalEndpoint);
        $this->restoreEnv('SIEM_FORMAT', $this->originalFormat);
    }

    private function restoreEnv(string $key, ?string $original): void
    {
        if ($original !== null) {
            $_ENV[$key] = $original;
        } else {
            unset($_ENV[$key]);
        }
    }

    public function testNoneProviderRegistersNullExporter(): void
    {
        $_ENV['SIEM_PROVIDER'] = 'none';
        unset($_ENV['SIEM_ENDPOINT'], $_ENV['SIEM_FORMAT']);

        $container = new ContainerBuilder();
        $pass = new SiemCompilerPass();
        $pass->process($container);

        $this->assertTrue($container->hasDefinition(SiemExporterInterface::class));
        $definition = $container->getDefinition(SiemExporterInterface::class);
        $this->assertSame(NullSiemExporter::class, $definition->getClass());
    }

    public function testEmptyProviderDefaultsToNullExporter(): void
    {
        $_ENV['SIEM_PROVIDER'] = '';
        unset($_ENV['SIEM_ENDPOINT'], $_ENV['SIEM_FORMAT']);

        $container = new ContainerBuilder();
        $pass = new SiemCompilerPass();
        $pass->process($container);

        $definition = $container->getDefinition(SiemExporterInterface::class);
        $this->assertSame(NullSiemExporter::class, $definition->getClass());
    }

    public function testMissingProviderEnvDefaultsToNullExporter(): void
    {
        unset($_ENV['SIEM_PROVIDER'], $_ENV['SIEM_ENDPOINT'], $_ENV['SIEM_FORMAT']);

        $container = new ContainerBuilder();
        $pass = new SiemCompilerPass();
        $pass->process($container);

        $definition = $container->getDefinition(SiemExporterInterface::class);
        $this->assertSame(NullSiemExporter::class, $definition->getClass());
    }

    public function testFileProviderWithEndpointRegistersFileSiemExporter(): void
    {
        $_ENV['SIEM_PROVIDER'] = 'file';
        $_ENV['SIEM_ENDPOINT'] = '/var/log/siem/scambuster.ndjson';
        unset($_ENV['SIEM_FORMAT']);

        $container = new ContainerBuilder();
        $container->register('logger');

        $pass = new SiemCompilerPass();
        $pass->process($container);

        $definition = $container->getDefinition(SiemExporterInterface::class);
        $this->assertSame(FileSiemExporter::class, $definition->getClass());

        // Verify endpoint is passed as argument
        $args = $definition->getArguments();
        $this->assertSame('/var/log/siem/scambuster.ndjson', $args[2]);
    }

    public function testFileProviderWithoutEndpointFallsBackToNull(): void
    {
        $_ENV['SIEM_PROVIDER'] = 'file';
        $_ENV['SIEM_ENDPOINT'] = '';
        unset($_ENV['SIEM_FORMAT']);

        $container = new ContainerBuilder();
        $pass = new SiemCompilerPass();
        $pass->process($container);

        $definition = $container->getDefinition(SiemExporterInterface::class);
        $this->assertSame(NullSiemExporter::class, $definition->getClass());
    }

    public function testSyslogProviderWithEndpointRegistersSyslogExporter(): void
    {
        $_ENV['SIEM_PROVIDER'] = 'syslog';
        $_ENV['SIEM_ENDPOINT'] = 'udp://siem.example.com:514';
        unset($_ENV['SIEM_FORMAT']);

        $container = new ContainerBuilder();
        $container->register('logger');

        $pass = new SiemCompilerPass();
        $pass->process($container);

        $definition = $container->getDefinition(SiemExporterInterface::class);
        $this->assertSame(SyslogSiemExporter::class, $definition->getClass());

        $args = $definition->getArguments();
        $this->assertSame('udp://siem.example.com:514', $args[2]);
    }

    public function testSyslogProviderWithoutEndpointFallsBackToNull(): void
    {
        $_ENV['SIEM_PROVIDER'] = 'syslog';
        $_ENV['SIEM_ENDPOINT'] = '';
        unset($_ENV['SIEM_FORMAT']);

        $container = new ContainerBuilder();
        $pass = new SiemCompilerPass();
        $pass->process($container);

        $definition = $container->getDefinition(SiemExporterInterface::class);
        $this->assertSame(NullSiemExporter::class, $definition->getClass());
    }

    public function testUnknownProviderWithEndpointFallsBackToNull(): void
    {
        $_ENV['SIEM_PROVIDER'] = 'splunk';
        $_ENV['SIEM_ENDPOINT'] = 'https://splunk.example.com:8088';
        unset($_ENV['SIEM_FORMAT']);

        $container = new ContainerBuilder();
        $pass = new SiemCompilerPass();
        $pass->process($container);

        $definition = $container->getDefinition(SiemExporterInterface::class);
        $this->assertSame(NullSiemExporter::class, $definition->getClass());
    }

    public function testFileProviderUsesJsonFormatterByDefault(): void
    {
        $_ENV['SIEM_PROVIDER'] = 'file';
        $_ENV['SIEM_ENDPOINT'] = '/var/log/siem/output.ndjson';
        unset($_ENV['SIEM_FORMAT']);

        $container = new ContainerBuilder();
        $container->register('logger');

        $pass = new SiemCompilerPass();
        $pass->process($container);

        $formatterDef = $container->getDefinition(SiemEventFormatterInterface::class);
        $this->assertSame(JsonFormatter::class, $formatterDef->getClass());
    }

    public function testSyslogProviderUsesCefFormatterByDefault(): void
    {
        $_ENV['SIEM_PROVIDER'] = 'syslog';
        $_ENV['SIEM_ENDPOINT'] = 'udp://siem.example.com:514';
        unset($_ENV['SIEM_FORMAT']);

        $container = new ContainerBuilder();
        $container->register('logger');

        $pass = new SiemCompilerPass();
        $pass->process($container);

        $formatterDef = $container->getDefinition(SiemEventFormatterInterface::class);
        $this->assertSame(CefFormatter::class, $formatterDef->getClass());
    }

    public function testFormatOverrideViaSiemFormatEnv(): void
    {
        $_ENV['SIEM_PROVIDER'] = 'file';
        $_ENV['SIEM_ENDPOINT'] = '/var/log/siem/output.ndjson';
        $_ENV['SIEM_FORMAT'] = 'cef';

        $container = new ContainerBuilder();
        $container->register('logger');

        $pass = new SiemCompilerPass();
        $pass->process($container);

        $formatterDef = $container->getDefinition(SiemEventFormatterInterface::class);
        $this->assertSame(CefFormatter::class, $formatterDef->getClass());
    }

    public function testNoneProviderRegistersJsonFormatterByDefault(): void
    {
        $_ENV['SIEM_PROVIDER'] = 'none';
        unset($_ENV['SIEM_ENDPOINT'], $_ENV['SIEM_FORMAT']);

        $container = new ContainerBuilder();
        $pass = new SiemCompilerPass();
        $pass->process($container);

        $formatterDef = $container->getDefinition(SiemEventFormatterInterface::class);
        $this->assertSame(JsonFormatter::class, $formatterDef->getClass());
    }
}
