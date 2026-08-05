<?php

declare(strict_types=1);

namespace App;

use App\Infrastructure\LLM\LLMProviderCompilerPass;
use App\Infrastructure\Siem\SiemCompilerPass;
use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\HttpKernel\Kernel as BaseKernel;

class Kernel extends BaseKernel
{
    use MicroKernelTrait;

    protected function build(ContainerBuilder $container): void
    {
        $container->addCompilerPass(new LLMProviderCompilerPass());
        $container->addCompilerPass(new SiemCompilerPass());
    }
}
