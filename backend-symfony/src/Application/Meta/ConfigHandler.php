<?php

declare(strict_types=1);

namespace App\Application\Meta;

use App\Application\Communication\IocExtractor;
use App\Application\Scambaiting\PersonaOptimizer;
use App\Domain\Communication\Persona;
use App\Domain\Communication\ScamType;
use Doctrine\ORM\EntityManagerInterface;

final class ConfigHandler
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly PersonaOptimizer $personaOptimizer,
    ) {
    }

    /**
     * @return array{
     *     personas: list<array{code: string, label: string, tone: string, active: bool}>,
     *     scam_types: list<array{code: string, label: string, description: string|null, active: bool}>,
     *     ioc_types: list<string>,
     *     bandit: array{strategy: string, epsilon: float, cold_start_threshold: int, convergence_threshold: float, min_sessions_for_convergence: int, converged_epsilon: float}
     * }
     */
    public function getConfig(): array
    {
        $personas = $this->em->getRepository(Persona::class)->findAll();
        $scamTypes = $this->em->getRepository(ScamType::class)->findAll();

        return [
            'personas' => array_values(array_map(
                static fn(Persona $p): array => [
                    'code' => $p->getPersonaCode(),
                    'label' => $p->getPersonaLabel(),
                    'tone' => $p->getPersonaTone(),
                    'active' => $p->isActive(),
                ],
                $personas,
            )),
            'scam_types' => array_values(array_map(
                static fn(ScamType $st): array => [
                    'code' => $st->getCode(),
                    'label' => $st->getLabel(),
                    'description' => $st->getDescription(),
                    'active' => $st->isActive(),
                ],
                $scamTypes,
            )),
            'ioc_types' => IocExtractor::getSupportedTypes(),
            'bandit' => $this->personaOptimizer->getBanditConfig(),
        ];
    }
}
