<?php

declare(strict_types=1);

namespace App\UI\Console;

use Doctrine\DBAL\Connection;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Spec 075c — Fix mislabeled semantic roles on existing IOC contexts.
 *
 * Finds ioc_context rows where semantic_role='MALWARE_DOWNLOAD_URL' but
 * the indicator type is a hash (sha256/sha1/md5). If the IOC value appears
 * in the last 20% of the source message body (footer position), the role
 * is corrected to 'IDENTITY_DOCUMENT'.
 */
#[AsCommand(
    name: 'app:fix:semantic-roles',
    description: 'Fix mislabeled semantic roles on existing IOC contexts',
)]
final class FixSemanticRolesCommand extends Command
{
    public function __construct(
        private readonly Connection $conn,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Preview changes without writing')
            ->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Max rows to process', '0');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $dryRun = (bool) $input->getOption('dry-run');
        $limitRaw = $input->getOption('limit');
        $limit = \is_numeric($limitRaw) ? (int) $limitRaw : 0;

        $io->title('Spec 075c — Fix mislabeled semantic roles');

        if ($dryRun) {
            $io->note('Dry-run mode: no data will be written.');
        }

        // Find mislabeled rows: MALWARE_DOWNLOAD_URL on hash indicator types
        $sql = "SELECT ic.id, ic.obs_id, i.value AS ioc_value, i.type AS ioc_type,
                       m.body_text, m.msg_id
                FROM ioc_context ic
                JOIN indicator i ON ic.indicator_id = i.indicator_id
                JOIN observed_ioc oi ON ic.obs_id = oi.obs_id
                JOIN message m ON oi.msg_id = m.msg_id
                WHERE ic.semantic_role = 'MALWARE_DOWNLOAD_URL'
                  AND i.type IN ('sha256', 'sha1', 'md5')
                ORDER BY ic.id";

        if ($limit > 0) {
            $sql .= ' LIMIT ' . $limit;
        }

        $rows = $this->conn->fetchAllAssociative($sql);

        if ($rows === []) {
            $io->success('No mislabeled semantic roles found.');

            return Command::SUCCESS;
        }

        $io->info(\sprintf('Found %d candidate rows.', \count($rows)));

        $updated = 0;
        $skipped = 0;

        foreach ($rows as $row) {
            $id = $row['id'] ?? null;
            $iocValue = \is_string($row['ioc_value'] ?? null) ? $row['ioc_value'] : '';
            $bodyText = \is_string($row['body_text'] ?? null) ? $row['body_text'] : '';

            if ($bodyText === '' || $iocValue === '') {
                ++$skipped;

                continue;
            }

            if ($this->isInFooter($iocValue, $bodyText)) {
                if (!$dryRun) {
                    $this->conn->executeStatement(
                        "UPDATE ioc_context SET semantic_role = 'IDENTITY_DOCUMENT', computed_at = :now WHERE id = :id",
                        ['now' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'), 'id' => $id]
                    );
                }

                ++$updated;
            } else {
                ++$skipped;
            }
        }

        $io->table(
            ['Metric', 'Count'],
            [
                ['Updated (MALWARE_DOWNLOAD_URL -> IDENTITY_DOCUMENT)', $updated],
                ['Skipped (not in footer)', $skipped],
            ]
        );

        if ($dryRun) {
            $io->success(\sprintf('Dry-run complete: %d would be updated, %d skipped.', $updated, $skipped));
        } else {
            $io->success(\sprintf('Done: %d updated, %d skipped.', $updated, $skipped));
        }

        return Command::SUCCESS;
    }

    /**
     * Check if the IOC value appears in the last 20% of the message body.
     */
    public static function isInFooter(string $iocValue, string $bodyText): bool
    {
        $bodyLen = \strlen($bodyText);

        if ($bodyLen === 0) {
            return false;
        }

        $pos = strrpos($bodyText, $iocValue);

        if ($pos === false) {
            return false;
        }

        $threshold = (int) floor($bodyLen * 0.80);

        return $pos >= $threshold;
    }
}
