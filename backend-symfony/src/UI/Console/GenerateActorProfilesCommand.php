<?php

declare(strict_types=1);

namespace App\UI\Console;

use App\Application\Campaign\ActorProfileGenerator;
use Doctrine\DBAL\Connection;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:generate-actor-profiles',
    description: 'Generate actor fingerprints for campaigns with sufficient conversation data',
)]
final class GenerateActorProfilesCommand extends Command
{
    public function __construct(
        private readonly Connection $connection,
        private readonly ActorProfileGenerator $generator,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('min-conversations', null, InputOption::VALUE_REQUIRED, 'Minimum conversations per campaign', '3');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        /** @var string $rawMin */
        $rawMin = $input->getOption('min-conversations');
        $minConvs = (int) $rawMin;

        $io->title('Generate Actor Profiles');

        // Find campaigns with enough conversations that don't have a profile yet
        $campaigns = $this->connection->fetchAllAssociative("
            SELECT c.campaign_id, c.status, COUNT(DISTINCT mc.msg_id) as msg_count
            FROM campaign c
            JOIN message_campaign mc ON mc.campaign_id = c.campaign_id
            LEFT JOIN actor_profile ap ON ap.campaigns LIKE '%' || c.campaign_id::text || '%'
            WHERE ap.actor_id IS NULL
            GROUP BY c.campaign_id, c.status
            HAVING COUNT(DISTINCT mc.msg_id) >= :minMsgs
            ORDER BY msg_count DESC
        ", ['minMsgs' => $minConvs * 2]); // at least 2 messages per conversation

        if ($campaigns === []) {
            $io->success('No campaigns need actor profiling.');

            return Command::SUCCESS;
        }

        $io->text(sprintf('Found %d campaigns to profile', count($campaigns)));

        $created = 0;
        $skipped = 0;

        foreach ($campaigns as $campaign) {
            /** @var string $campaignId */
            $campaignId = $campaign['campaign_id'];

            $profile = $this->generator->generateForCampaign($campaignId);

            if ($profile === null) {
                ++$skipped;

                continue;
            }

            $actorId = \Symfony\Component\Uid\Uuid::v4()->toRfc4122();

            $this->connection->executeStatement(
                'INSERT INTO actor_profile (actor_id, style_dna, infra_dna, campaigns, created_at, updated_at)
                 VALUES (:actorId, :styleDna, :infraDna, :campaigns, NOW(), NOW())',
                [
                    'actorId' => $actorId,
                    'styleDna' => json_encode($profile['style_dna']),
                    'infraDna' => json_encode($profile['infra_dna']),
                    'campaigns' => $campaignId,
                ],
            );

            ++$created;
            $io->text(sprintf(
                '  Created profile for campaign %s (%d words, %d IOCs)',
                substr($campaignId, 0, 8),
                $profile['style_dna']['total_words'] ?? 0,
                $profile['infra_dna']['ioc_count'] ?? 0,
            ));
        }

        $io->success(sprintf('Created %d actor profiles, skipped %d', $created, $skipped));

        return Command::SUCCESS;
    }
}
