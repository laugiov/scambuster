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
 * Verify cluster anchor IOC quality and detect potential artifacts.
 *
 * For each cluster with sufficient conversations, classifies anchor IOCs as
 * HIGH_VALUE or GENERIC and flags clusters with >30% generic anchors.
 *
 * Exit codes:
 *   0 = no clusters flagged
 *   1 = at least one cluster flagged for review
 */
#[AsCommand(
    name: 'app:verify:cluster-quality',
    description: 'Verify cluster anchor IOC quality and detect potential artifacts',
)]
final class VerifyClusterQualityCommand extends Command
{
    private const GENERIC_THRESHOLD_PCT = 30.0;

    /** Minimum length for a phone to be considered HIGH_VALUE (digits only). */
    private const MIN_PHONE_DIGITS = 10;

    /** Minimum length for an IBAN to be considered HIGH_VALUE. */
    private const MIN_IBAN_LENGTH = 15;

    /** Minimum length for a crypto wallet to be considered HIGH_VALUE. */
    private const MIN_CRYPTO_LENGTH = 25;

    /** Common free email domains that make an email IOC generic. */
    private const GENERIC_EMAIL_DOMAINS = [
        'gmail.com', 'yahoo.com', 'hotmail.com', 'outlook.com',
        'aol.com', 'mail.com', 'protonmail.com', 'yandex.com',
    ];

    public function __construct(
        private readonly Connection $connection,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('min-conversations', null, InputOption::VALUE_REQUIRED, 'Minimum conversations for a cluster to be analyzed', '5');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        /** @var string $minConvRaw */
        $minConvRaw = $input->getOption('min-conversations');
        $minConversations = (int) $minConvRaw;

        $io->title('Cluster Anchor Quality Report');

        $clusters = $this->connection->fetchAllAssociative(
            'SELECT tac.cluster_id, tac.name, tac.conversation_count, tac.anchor_ioc_count
             FROM threat_actor_cluster tac
             WHERE tac.status != :merged AND tac.conversation_count >= :minConvs
             ORDER BY tac.conversation_count DESC',
            ['merged' => 'merged', 'minConvs' => $minConversations],
            ['minConvs' => \Doctrine\DBAL\ParameterType::INTEGER],
        );

        if ($clusters === []) {
            $io->warning(sprintf('No clusters found with >= %d conversations.', $minConversations));

            return Command::SUCCESS;
        }

        $io->writeln(sprintf('Analyzing %d clusters (min conversations: %d)', \count($clusters), $minConversations));

        /** @var list<array{cluster: string, name: string, conversations: int, anchors: int, high_value: int, high_value_pct: int, generic: int, generic_pct: float, flag: string}> $summaryRows */
        $summaryRows = [];
        /** @var list<array{cluster_id: string, short_id: string, name: string, conversations: int, anchors: list<array{indicator_id: string, type: string, value: string, classification: string, conv_count: int}>}> $flaggedDetails */
        $flaggedDetails = [];
        $hasFlagged = false;

        foreach ($clusters as $cluster) {
            $clusterId = \is_string($cluster['cluster_id'] ?? null) ? $cluster['cluster_id'] : '';
            $clusterName = \is_string($cluster['name'] ?? null) ? $cluster['name'] : '';
            $convCount = \is_numeric($cluster['conversation_count'] ?? null) ? (int) $cluster['conversation_count'] : 0;

            $anchors = $this->connection->fetchAllAssociative(
                'SELECT taci.indicator_id, taci.ioc_type, taci.conv_count,
                        i.value, i.value_norm
                 FROM threat_actor_cluster_ioc taci
                 JOIN indicator i ON i.indicator_id = taci.indicator_id
                 WHERE taci.cluster_id = :id
                 ORDER BY taci.conv_count DESC',
                ['id' => $clusterId],
            );

            $totalAnchors = \count($anchors);
            $highValue = 0;
            $generic = 0;
            /** @var list<array{indicator_id: string, type: string, value: string, classification: string, conv_count: int}> $anchorClassifications */
            $anchorClassifications = [];

            foreach ($anchors as $anchor) {
                $type = \is_string($anchor['ioc_type'] ?? null) ? $anchor['ioc_type'] : '';
                $value = \is_string($anchor['value'] ?? null) ? $anchor['value'] : '';
                $valueNorm = \is_string($anchor['value_norm'] ?? null) ? $anchor['value_norm'] : '';

                $classification = $this->classifyAnchor($type, $value, $valueNorm);

                if ($classification === 'HIGH_VALUE') {
                    $highValue++;
                } else {
                    $generic++;
                }

                $anchorClassifications[] = [
                    'indicator_id' => \is_string($anchor['indicator_id'] ?? null) ? $anchor['indicator_id'] : '',
                    'type' => $type,
                    'value' => $valueNorm !== '' ? $valueNorm : $value,
                    'classification' => $classification,
                    'conv_count' => \is_numeric($anchor['conv_count'] ?? null) ? (int) $anchor['conv_count'] : 0,
                ];
            }

            $genericPct = $totalAnchors > 0 ? round($generic / $totalAnchors * 100, 1) : 0.0;
            $flagged = $genericPct > self::GENERIC_THRESHOLD_PCT;

            if ($flagged) {
                $hasFlagged = true;
            }

            $shortId = mb_substr($clusterId, 0, 8, 'UTF-8');

            $summaryRows[] = [
                'cluster' => '#' . $shortId,
                'name' => $clusterName,
                'conversations' => $convCount,
                'anchors' => $totalAnchors,
                'high_value' => $highValue,
                'high_value_pct' => $totalAnchors > 0 ? (int) round($highValue / $totalAnchors * 100, 0) : 0,
                'generic' => $generic,
                'generic_pct' => $genericPct,
                'flag' => $flagged ? 'REVIEW' : 'OK',
            ];

            if ($flagged) {
                $flaggedDetails[] = [
                    'cluster_id' => $clusterId,
                    'short_id' => $shortId,
                    'name' => $clusterName,
                    'conversations' => $convCount,
                    'anchors' => $anchorClassifications,
                ];
            }
        }

        // Console output
        $io->table(
            ['Cluster', 'Conversations', 'Anchors', 'HIGH_VALUE', 'GENERIC', 'Flag'],
            array_map(
                fn (array $r) => [
                    $r['cluster'],
                    $r['conversations'],
                    $r['anchors'],
                    sprintf('%d (%d%%)', $r['high_value'], $r['high_value_pct']),
                    sprintf('%d (%.0f%%)', $r['generic'], $r['generic_pct']),
                    $r['flag'] === 'OK' ? 'OK' : 'REVIEW',
                ],
                $summaryRows,
            ),
        );

        // Write markdown report
        $this->writeReport($summaryRows, $flaggedDetails, $minConversations);
        $io->success('Report written to var/audit-results/cluster-quality-report.md');

        return $hasFlagged ? Command::FAILURE : Command::SUCCESS;
    }

    private function classifyAnchor(string $type, string $value, string $valueNorm): string
    {
        $effectiveValue = $valueNorm !== '' ? $valueNorm : $value;

        return match (true) {
            // IBAN: must be >= 15 chars
            str_contains($type, 'iban') => mb_strlen($effectiveValue, 'UTF-8') >= self::MIN_IBAN_LENGTH ? 'HIGH_VALUE' : 'GENERIC',

            // Phone: must have >= 10 digits
            str_contains($type, 'phone') => $this->countDigits($effectiveValue) >= self::MIN_PHONE_DIGITS ? 'HIGH_VALUE' : 'GENERIC',

            // Crypto wallet: must be >= 25 chars
            str_contains($type, 'crypto') || str_contains($type, 'bitcoin') || str_contains($type, 'ethereum') || str_contains($type, 'wallet') => mb_strlen($effectiveValue, 'UTF-8') >= self::MIN_CRYPTO_LENGTH ? 'HIGH_VALUE' : 'GENERIC',

            // Email: check if it uses a generic domain
            str_contains($type, 'email') => $this->isGenericEmail($effectiveValue) ? 'GENERIC' : 'HIGH_VALUE',

            // Domain: short or common patterns are generic
            str_contains($type, 'domain') => mb_strlen($effectiveValue, 'UTF-8') <= 6 ? 'GENERIC' : 'HIGH_VALUE',

            // URL, IP, SHA256, etc.: generally high-value
            default => 'HIGH_VALUE',
        };
    }

    private function countDigits(string $value): int
    {
        return (int) preg_match_all('/\d/', $value);
    }

    private function isGenericEmail(string $email): bool
    {
        $parts = explode('@', $email);
        $domain = mb_strtolower(end($parts), 'UTF-8');

        return \in_array($domain, self::GENERIC_EMAIL_DOMAINS, true);
    }

    /**
     * @param list<array{cluster: string, name: string, conversations: int, anchors: int, high_value: int, high_value_pct: int, generic: int, generic_pct: float, flag: string}>                                    $summaryRows
     * @param list<array{cluster_id: string, short_id: string, name: string, conversations: int, anchors: list<array{indicator_id: string, type: string, value: string, classification: string, conv_count: int}>}> $flaggedDetails
     */
    private function writeReport(array $summaryRows, array $flaggedDetails, int $minConversations): void
    {
        $date = date('Y-m-d');
        $total = \count($summaryRows);

        $md = "# Cluster Anchor Quality Report\n";
        $md .= "**Date**: {$date}\n";
        $md .= "**Clusters analyzed**: {$total} (min conversations: {$minConversations})\n\n";

        $md .= "## Summary\n";
        $md .= "| Cluster | Name | Conversations | Anchors | HIGH_VALUE | GENERIC | Flag |\n";
        $md .= "|---|---|---|---|---|---|---|\n";

        foreach ($summaryRows as $r) {
            $okIcon = $r['flag'] === 'OK' ? "\u{2705} OK" : "\u{26A0}\u{FE0F} REVIEW";
            $md .= sprintf(
                "| %s | %s | %d | %d | %d (%d%%) | %d (%.0f%%) | %s |\n",
                $r['cluster'],
                str_replace('|', '\\|', (string) $r['name']),
                $r['conversations'],
                $r['anchors'],
                $r['high_value'],
                $r['high_value_pct'],
                $r['generic'],
                $r['generic_pct'],
                $okIcon,
            );
        }

        if ($flaggedDetails !== []) {
            $md .= "\n## Flagged Clusters\n";

            foreach ($flaggedDetails as $detail) {
                $md .= sprintf(
                    "\n### Cluster #%s — %s (%d conversations)\n",
                    $detail['short_id'],
                    $detail['name'],
                    $detail['conversations'],
                );

                $md .= "| Indicator ID | Type | Value | Classification | Conv Count |\n";
                $md .= "|---|---|---|---|---|\n";

                $anchors = $detail['anchors'];

                foreach ($anchors as $anchor) {
                    $val = mb_substr($anchor['value'], 0, 50, 'UTF-8');
                    $val = str_replace('|', '\\|', $val);
                    $md .= sprintf(
                        "| %s | %s | %s | %s | %d |\n",
                        mb_substr($anchor['indicator_id'], 0, 8, 'UTF-8'),
                        $anchor['type'],
                        $val,
                        $anchor['classification'],
                        $anchor['conv_count'],
                    );
                }
            }
        }

        $dir = \dirname(__DIR__, 3) . '/var/audit-results';

        if (!is_dir($dir)) {
            mkdir($dir, 0o755, true);
        }

        file_put_contents($dir . '/cluster-quality-report.md', $md);
    }
}
