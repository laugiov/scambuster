<?php

declare(strict_types=1);

namespace App\UI\Console;

use App\Application\Stix\ClusteredThreatActorStixBuilder;
use Doctrine\DBAL\Connection;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Export active threat-actor clusters as STIX 2.1 bundles.
 *
 * Supports filtering by cluster ID, date, and output to file.
 */
#[AsCommand(
    name: 'app:clustering:export-stix',
    description: 'Export threat-actor clusters as STIX 2.1 bundles',
)]
final class ClusterExportStixCommand extends Command
{
    public function __construct(
        private readonly Connection $conn,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('cluster-id', null, InputOption::VALUE_REQUIRED, 'Export a single cluster by UUID')
            ->addOption('since', null, InputOption::VALUE_REQUIRED, 'Export clusters updated since (ISO 8601)')
            ->addOption('output', 'o', InputOption::VALUE_REQUIRED, 'Output file path (default: stdout)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $builder = new ClusteredThreatActorStixBuilder();

        $clusterIdFilter = $input->getOption('cluster-id');
        $sinceFilter = $input->getOption('since');
        $outputPath = $input->getOption('output');

        // Build query
        $sql = "SELECT tac.cluster_id, tac.stix_id, tac.name, tac.status,
                       tac.conversation_count, tac.anchor_ioc_count, tac.sophistication,
                       tac.primary_scam_types, tac.goals, tac.first_seen, tac.last_seen,
                       tac.algorithm_version
                FROM threat_actor_cluster tac
                WHERE tac.status = 'active' AND tac.conversation_count >= 2";
        $params = [];

        if (\is_string($clusterIdFilter) && $clusterIdFilter !== '') {
            $sql .= ' AND tac.cluster_id = :clusterId';
            $params['clusterId'] = $clusterIdFilter;
        }

        if (\is_string($sinceFilter) && $sinceFilter !== '') {
            try {
                $since = new \DateTimeImmutable($sinceFilter);
                $sql .= ' AND tac.updated_at > :since';
                $params['since'] = $since->format('Y-m-d H:i:s');
            } catch (\Exception) {
                $io->error("Invalid --since date: {$sinceFilter}");

                return Command::FAILURE;
            }
        }

        $sql .= ' ORDER BY tac.last_seen DESC';
        $rows = $this->conn->fetchAllAssociative($sql, $params);

        if (empty($rows)) {
            $io->warning('No clusters found matching the criteria.');

            return Command::SUCCESS;
        }

        $allObjects = [];

        foreach ($rows as $row) {
            /** @var string $clusterId */
            $clusterId = $row['cluster_id'] ?? '';

            // Parse scam types
            $scamTypesRaw = \is_string($row['primary_scam_types'] ?? null) ? $row['primary_scam_types'] : '{}';
            $scamTypes = $this->parsePostgresArray($scamTypesRaw);

            // Get anchor IOC types
            $anchorIocTypes = $this->conn->fetchFirstColumn(
                'SELECT DISTINCT ioc_type FROM threat_actor_cluster_ioc WHERE cluster_id = :id',
                ['id' => $clusterId]
            );

            // Get ATT&CK techniques
            $attckTechniques = $this->conn->fetchFirstColumn(
                'SELECT DISTINCT st.attck_technique FROM lkp_scam_type st WHERE st.code = ANY(:codes) AND st.attck_technique IS NOT NULL',
                ['codes' => '{' . implode(',', $scamTypes) . '}']
            );

            // Get indicator STIX IDs
            $indicatorIds = $this->conn->fetchFirstColumn(
                'SELECT indicator_id FROM threat_actor_cluster_ioc WHERE cluster_id = :id',
                ['id' => $clusterId]
            );

            $clusterData = [
                'cluster_id' => $clusterId,
                'stix_id' => \is_string($row['stix_id'] ?? null) ? $row['stix_id'] : '',
                'name' => \is_string($row['name'] ?? null) ? $row['name'] : '',
                'status' => 'active',
                'conversation_count' => \is_numeric($row['conversation_count'] ?? null) ? (int) $row['conversation_count'] : 0,
                'anchor_ioc_count' => \is_numeric($row['anchor_ioc_count'] ?? null) ? (int) $row['anchor_ioc_count'] : 0,
                'sophistication' => \is_string($row['sophistication'] ?? null) ? $row['sophistication'] : 'none',
                'primary_scam_types' => $scamTypes,
                'goals' => ['financial-theft'],
                'first_seen' => \is_string($row['first_seen'] ?? null) ? $row['first_seen'] : '',
                'last_seen' => \is_string($row['last_seen'] ?? null) ? $row['last_seen'] : '',
                'algorithm_version' => \is_string($row['algorithm_version'] ?? null) ? $row['algorithm_version'] : '1.0',
                'anchor_ioc_types' => array_map(fn (mixed $v) => \is_string($v) ? $v : '', $anchorIocTypes),
                'attck_techniques' => array_map(fn (mixed $v) => \is_string($v) ? $v : '', $attckTechniques),
                'indicator_stix_ids' => array_map(fn (mixed $id) => 'indicator--' . (\is_string($id) ? $id : ''), $indicatorIds),
            ];

            $bundle = $builder->buildBundle($clusterData);

            foreach ($bundle as $obj) {
                $allObjects[] = $obj;
            }
        }

        $stixBundle = [
            'type' => 'bundle',
            'id' => 'bundle--' . \Symfony\Component\Uid\Uuid::v4()->toRfc4122(),
            'objects' => $allObjects,
        ];

        $json = json_encode($stixBundle, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES);

        if (\is_string($outputPath) && $outputPath !== '') {
            file_put_contents($outputPath, $json);
            $io->success("Exported {$this->countType($allObjects, 'threat-actor')} clusters to {$outputPath}");
        } else {
            $output->writeln(\is_string($json) ? $json : '{}');
        }

        $io->table(
            ['Object Type', 'Count'],
            [
                ['threat-actor', (string) $this->countType($allObjects, 'threat-actor')],
                ['attack-pattern', (string) $this->countType($allObjects, 'attack-pattern')],
                ['relationship', (string) $this->countType($allObjects, 'relationship')],
            ]
        );

        return Command::SUCCESS;
    }

    /**
     * @param list<array<string, mixed>> $objects
     */
    private function countType(array $objects, string $type): int
    {
        return \count(array_filter($objects, fn (array $o) => ($o['type'] ?? '') === $type));
    }

    /**
     * @return list<string>
     */
    private function parsePostgresArray(string $pgArray): array
    {
        $trimmed = trim($pgArray, '{}');

        if ($trimmed === '') {
            return [];
        }

        return array_map('trim', explode(',', $trimmed));
    }
}
