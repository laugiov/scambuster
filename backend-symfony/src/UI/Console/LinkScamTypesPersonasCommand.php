<?php

declare(strict_types=1);

namespace App\UI\Console;

use App\Domain\Communication\Persona;
use App\Domain\Communication\ScamType;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:link-scam-types-personas',
    description: 'Link existing ScamTypes to their appropriate Personas'
)]
class LinkScamTypesPersonasCommand extends Command
{
    /**
     * Mapping of scam_type code → array of persona codes (ManyToMany)
     */
    private const SCAM_TYPE_TO_PERSONAS = [
        'invoice' => ['small_business_owner', 'entrepreneur_rushed', 'accountant_meticulous', 'freelance_cautious', 'admin_assistant'],
        'phishing' => ['bank_customer', 'worried_customer', 'tech_newbie', 'tech_intermediate', 'senior_trusting'],
        'lottery' => ['lottery_skeptic', 'lottery_believer', 'elderly_person', 'investor_greedy', 'debtor_desperate'],
        'romance' => ['lonely_person', 'lonely_divorcee', 'hopeless_romantic', 'widow_grieving', 'senior_isolated'],
        'techsupport' => ['confused_user', 'tech_newbie', 'tech_intermediate', 'senior_trusting', 'senior_suspicious'],
        'UNKNOWN' => ['generic_user'],
    ];

    public function __construct(
        private readonly EntityManagerInterface $em
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $linked = 0;
        $skipped = 0;

        foreach (self::SCAM_TYPE_TO_PERSONAS as $scamTypeCode => $personaCodes) {
            $scamType = $this->em->getRepository(ScamType::class)->findOneBy(['code' => $scamTypeCode]);

            if (!$scamType) {
                $io->note("ScamType '{$scamTypeCode}' not found, skipping");
                $skipped++;

                continue;
            }

            // Clear existing personas for this scam type
            foreach ($scamType->getPersonas() as $persona) {
                $scamType->removePersona($persona);
            }

            // Add all configured personas
            foreach ($personaCodes as $personaCode) {
                $persona = $this->em->getRepository(Persona::class)->findOneBy(['personaCode' => $personaCode]);

                if (!$persona) {
                    $io->warning("Persona '{$personaCode}' not found for scam type '{$scamTypeCode}', skipping this persona");

                    continue;
                }

                $scamType->addPersona($persona);
                $linked++;

                $io->writeln("  → Linked '{$scamTypeCode}' → '{$personaCode}'");
            }

            $this->em->persist($scamType);
        }

        $this->em->flush();

        $io->success("Total persona links created: {$linked}, scam types skipped: {$skipped}");

        return Command::SUCCESS;
    }
}
