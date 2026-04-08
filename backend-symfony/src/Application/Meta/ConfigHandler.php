<?php

declare(strict_types=1);

namespace App\Application\Meta;

use App\Application\Communication\IocExtractor;
use App\Application\Scambaiting\PersonaOptimizer;
use App\Domain\Communication\Persona;
use App\Domain\Communication\ScamType;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

final class ConfigHandler
{
    private const CACHE_KEY = 'meta_config';
    private const CACHE_TTL = 300; // 5 minutes

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly PersonaOptimizer $personaOptimizer,
        private readonly CacheInterface $cache,
        private readonly string $llmProvider = 'openai',
        private readonly string $llmModel = 'gpt-4o-mini',
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function getConfig(): array
    {
        /** @var array<string, mixed> */
        return $this->cache->get(self::CACHE_KEY, function (ItemInterface $item): array {
            $item->expiresAfter(self::CACHE_TTL);

            return $this->buildConfig();
        });
    }

    public function invalidateCache(): void
    {
        $this->cache->delete(self::CACHE_KEY);
    }

    /**
     * @return array<string, mixed>
     */
    private function buildConfig(): array
    {
        $personas = $this->em->getRepository(Persona::class)->findAll();
        $scamTypes = $this->em->getRepository(ScamType::class)->findAll();

        return [
            'personas' => array_map(
                static fn (Persona $p): array => [
                    'code' => $p->getPersonaCode(),
                    'label' => $p->getPersonaLabel(),
                    'tone' => $p->getPersonaTone(),
                    'active' => $p->isActive(),
                ],
                $personas,
            ),
            'scam_types' => array_map(
                static fn (ScamType $st): array => [
                    'code' => $st->getCode(),
                    'label' => $st->getLabel(),
                    'description' => $st->getDescription(),
                    'active' => $st->isActive(),
                ],
                $scamTypes,
            ),
            'ioc_types' => IocExtractor::getSupportedTypes(),
            'bandit' => $this->personaOptimizer->getBanditConfig(),
            'llm_provider' => $this->llmProvider,
            'llm_model' => $this->llmModel,
        ];
    }
}
