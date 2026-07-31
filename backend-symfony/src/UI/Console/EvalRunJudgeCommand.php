<?php

declare(strict_types=1);

namespace App\UI\Console;

use App\Application\LLM\Port\LLMClientInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Process\Process;

/**
 * Multi-judge harness for human-factor signals.
 *
 * Calls an LLM (gpt-4o-mini for cheap baseline, gpt-4o for the strong
 * cross-validation judge) on a rendered IOC, asks it to predict the
 * same 7 fields independently, and writes the verdict to disk for
 * comparison with production predictions + my (Claude) annotations.
 *
 * The judge prompt is DIFFERENT from the production prompt: it
 * encourages independent reasoning rather than re-using the production
 * scoring conventions verbatim. This helps capture cross-family bias
 * (gpt-4o judging gpt-4o-mini will share some pre-RLHF priors).
 *
 * Cost guard: each call ≤ ~$0.03 with gpt-4o; this command caps at
 * 200 IOCs per invocation to avoid runaway.
 *
 * Output:
 *   {out_dir}/judge-{model_slug}-{obs_id}.json
 */
#[AsCommand(
    name: 'app:eval:run-judge',
    description: 'Call gpt-4o (or gpt-4o-mini) on an IOC, save independent judgment as JSON',
)]
final class EvalRunJudgeCommand extends Command
{
    private const MAX_IOCS_PER_INVOCATION = 200;
    private const JUDGE_PROMPT_SYSTEM = 'You are an independent cybersecurity analyst. Given the 3-message window of a scambaiting honeypot conversation and the IOC the scammer revealed, predict 7 signals INDEPENDENTLY (do not rely on any predictions you may see). Respond ONLY with a valid JSON object — no markdown, no preamble, no explanation outside the JSON.';

    public function __construct(
        private readonly LLMClientInterface $llmClient,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('obs-id', null, InputOption::VALUE_REQUIRED, 'Single obs_id to judge')
            ->addOption('from-csv', null, InputOption::VALUE_REQUIRED, 'CSV with obs_id column (batch mode)')
            ->addOption('model', null, InputOption::VALUE_REQUIRED, 'OpenAI model (gpt-4o or gpt-4o-mini)', 'gpt-4o')
            ->addOption('out-dir', null, InputOption::VALUE_REQUIRED, 'Output directory', '/app/var/eval')
            ->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Cap on number of IOCs to judge (safety)', '200');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $obsIdSingle = (string) ($input->getOption('obs-id') ?? '');
        $fromCsv = (string) ($input->getOption('from-csv') ?? '');
        $model = (string) ($input->getOption('model') ?? 'gpt-4o');
        $outDir = (string) ($input->getOption('out-dir') ?? '/app/var/eval');
        $limit = min(self::MAX_IOCS_PER_INVOCATION, (int) ($input->getOption('limit') ?? '200'));

        if ($obsIdSingle === '' && $fromCsv === '') {
            $io->error('Either --obs-id or --from-csv required');

            return Command::FAILURE;
        }

        if (!is_dir($outDir)) {
            $io->error("out-dir does not exist: {$outDir}");

            return Command::FAILURE;
        }

        $modelSlug = str_replace(['/', ' ', ':'], '-', $model);
        $obsIds = $obsIdSingle !== '' ? [$obsIdSingle] : $this->readObsIdsFromCsv($fromCsv, $limit);

        $io->title("Judge run ({$model})");
        $io->writeln('  IOCs: ' . \count($obsIds));
        $io->writeln('  out:  ' . $outDir);
        $io->newLine();

        $okCount = 0;
        $skipCount = 0;
        $errCount = 0;

        foreach ($obsIds as $i => $obsId) {
            $outPath = sprintf('%s/judge-%s-%s.json', rtrim($outDir, '/'), $modelSlug, $obsId);

            if (file_exists($outPath)) {
                $skipCount++;

                continue;
            }

            $rendered = $this->renderIoc($obsId);

            if ($rendered === null) {
                $io->writeln(sprintf('  [%d/%d] %s — RENDER FAILED', $i + 1, \count($obsIds), $obsId));
                $errCount++;

                continue;
            }

            $judgePrompt = $this->buildJudgePrompt($rendered);

            try {
                $response = $this->llmClient->chat([
                    ['role' => 'system', 'content' => self::JUDGE_PROMPT_SYSTEM],
                    ['role' => 'user', 'content' => $judgePrompt],
                ], [
                    'model' => $model,
                    'temperature' => 0.0,
                    'max_tokens' => 600,
                    'purpose' => 'judge',
                ]);
                $verdict = $this->parseJudgeResponse($response);

                $envelope = [
                    'obs_id' => $obsId,
                    'judge_model' => $model,
                    'judge_prompt_version' => 'v1',
                    'verdict' => $verdict,
                    'raw_response' => $response,
                ];
                file_put_contents($outPath, json_encode($envelope, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_UNICODE));
                $io->writeln(sprintf('  [%d/%d] %s — OK', $i + 1, \count($obsIds), $obsId));
                $okCount++;
            } catch (\Throwable $e) {
                $io->writeln(sprintf('  [%d/%d] %s — ERROR: %s', $i + 1, \count($obsIds), $obsId, $e->getMessage()));
                $errCount++;
            }
        }

        $io->newLine();
        $io->writeln("ok={$okCount} skipped={$skipCount} errors={$errCount}");

        return Command::SUCCESS;
    }

    /**
     * @return list<string>
     */
    private function readObsIdsFromCsv(string $csvPath, int $limit): array
    {
        if (!is_file($csvPath)) {
            throw new \RuntimeException("CSV not found: {$csvPath}");
        }
        $fh = fopen($csvPath, 'r');

        if ($fh === false) {
            throw new \RuntimeException("Cannot open CSV: {$csvPath}");
        }
        $header = fgetcsv($fh);

        if ($header === false || !\in_array('obs_id', $header, true)) {
            fclose($fh);

            throw new \RuntimeException('CSV must have an obs_id column');
        }
        $idx = array_search('obs_id', $header, true);
        $ids = [];

        while (($row = fgetcsv($fh)) !== false) {
            if (\count($ids) >= $limit) {
                break;
            }
            $ids[] = (string) $row[$idx];
        }
        fclose($fh);

        return $ids;
    }

    private function renderIoc(string $obsId): ?array
    {
        // Invoke the existing renderer command to get the JSON payload.
        // Cleanest reuse — no DB duplication.
        $process = new Process(['php', '/app/bin/console', 'app:eval:render-ioc', '--obs-id', $obsId, '--format', 'json']);
        $process->setTimeout(30);
        $process->run();

        if (!$process->isSuccessful()) {
            return null;
        }
        $out = $process->getOutput();
        $data = json_decode($out, true);

        return \is_array($data) ? $data : null;
    }

    private function buildJudgePrompt(array $payload): string
    {
        $msgWindow = $payload['window'] ?? [];
        $prev = $msgWindow['previous_inbound']['body_text'] ?? '(not available)';
        $stim = $msgWindow['stimulus']['body_text'] ?? '(not available)';
        $rev = $msgWindow['revelation']['body_text'] ?? '';
        $iocType = $payload['ioc']['type'] ?? 'unknown';
        $iocValue = $payload['ioc']['value'] ?? '';
        $scamType = $payload['scam_type'] ?? 'unknown';
        $persona = $payload['persona']['code'] ?? 'unknown';
        $turn = $payload['turn']['revelation_turn'] ?? '?';
        $total = $payload['turn']['total_turns'] ?? '?';

        return <<<PROMPT
            ## Context
            Scam type: {$scamType}
            Honeypot persona: {$persona}
            IOC revelation turn: {$turn} of {$total}
            IOC type: {$iocType}
            IOC value: {$iocValue}

            ## Message window

            ### Previous inbound (scammer message before our reply):
            {$prev}

            ### Stimulus (our honeypot reply, if any):
            {$stim}

            ### Revelation (scammer message containing the IOC):
            {$rev}

            ## Your task

            Predict these 7 fields independently. Use ONLY evidence from the window above.

            1. `stimulus_type`: one of [PASSIVE, URGENCY_PRESSURE, TRUST_BUILDING, DIRECT_REQUEST, DOCUMENT_REQUEST, PAYMENT_INITIATION, UNKNOWN]. Use UNKNOWN if context is genuinely ambiguous. PASSIVE only when the scammer clearly volunteers IOCs without any honeypot prompt.

            2. `urgency_score`: float in [0.0, 1.0]. Calibrate using actual cues (deadlines, threats, pressure verbs). Spread your values; do not anchor at any single value.

            3. `hesitation_detected`: boolean. TRUE only on clear textual hesitation cues: "actually let me check", reformulation, asking for confirmation mid-promise, abrupt topic switch. FALSE for routine politeness or apologies for delay.

            4. `language_switch`: boolean. TRUE only if the scammer switches language WITHIN this message for a meaningful sentence (not proper nouns, not URLs). FALSE otherwise.

            5. `semantic_role`: most specific role for the IOC type. Choose from [CONTACT_CHANNEL, INFRASTRUCTURE_DOMAIN, PHISHING_CREDENTIAL_URL, PAYMENT_DESTINATION, PAYMENT_REDIRECT_URL, IDENTITY_DOCUMENT, MALWARE_DOWNLOAD_URL, MONEY_MULE_ACCOUNT, UNKNOWN].

            6. `context_excerpt`: ONE specific sentence explaining WHY this IOC appeared in THIS conversation. Mention a concrete detail (the pretext, the urgency framing, a named entity). Max 150 chars. NO PII (no emails, phones, IBANs).

            7. `enrichment_confidence`: float in [0.0, 1.0] for your own confidence in this analysis. Be honest: if the window is incomplete (no stimulus, no previous), keep below 0.65.

            Respond with EXACTLY this JSON shape:
            {
              "stimulus_type": "...",
              "urgency_score": 0.0,
              "hesitation_detected": false,
              "language_switch": false,
              "semantic_role": "...",
              "context_excerpt": "...",
              "enrichment_confidence": 0.0,
              "rationale": "1-2 sentences explaining your reasoning"
            }
            PROMPT;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function parseJudgeResponse(string $response): ?array
    {
        $response = trim($response);

        if (preg_match('/```(?:json)?\s*(\{.*\})\s*```/s', $response, $m)) {
            $response = $m[1];
        } elseif (preg_match('/(\{.*\})/s', $response, $m)) {
            $response = $m[1];
        }

        try {
            $decoded = json_decode($response, true, 32, \JSON_THROW_ON_ERROR);

            return \is_array($decoded) ? $decoded : null;
        } catch (\JsonException) {
            return null;
        }
    }
}
