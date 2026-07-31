<?php

declare(strict_types=1);

namespace App\UI\Console;

use Doctrine\DBAL\Connection;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Render a single IOC + its 3-message window in a human-readable
 * format for annotation OR in JSON for programmatic consumption by
 * the judge harness.
 *
 * The render contains EVERYTHING needed to label the 7 LLM-derived
 * fields without going back to the DB:
 *   - conversation metadata (scam_type, persona)
 *   - IOC type + value (NOT masked — annotation needs the raw data)
 *   - the 3-message window the production pipeline saw at enrichment time:
 *       PREVIOUS_INBOUND (scammer's message before our stimulus, if any)
 *       STIMULUS         (our honeypot outbound, if any)
 *       REVELATION       (scammer's inbound containing the IOC)
 *   - the production LLM predictions on each of the 7 fields
 *
 * Output never leaves disk in committed form — written to
 * `backend-symfony/var/eval/` which is gitignored.
 */
#[AsCommand(
    name: 'app:eval:render-ioc',
    description: 'Render one IOC + 3-msg window + production predictions for annotation/judging',
)]
final class EvalRenderIocCommand extends Command
{
    public function __construct(
        private readonly Connection $conn,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('obs-id', null, InputOption::VALUE_REQUIRED, 'observed_ioc.obs_id to render')
            ->addOption('format', null, InputOption::VALUE_REQUIRED, 'markdown | json', 'markdown');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $obsId = (string) ($input->getOption('obs-id') ?? '');
        $format = (string) ($input->getOption('format') ?? 'markdown');

        if ($obsId === '') {
            $output->writeln('<error>--obs-id required</error>');

            return Command::FAILURE;
        }

        if (!\in_array($format, ['markdown', 'json'], true)) {
            $output->writeln('<error>--format must be markdown or json</error>');

            return Command::FAILURE;
        }

        $data = $this->loadIoc($obsId);

        if ($data === null) {
            $output->writeln('<error>obs_id not found or not enriched</error>');

            return Command::FAILURE;
        }

        $window = $this->loadMessageWindow($data['conv_id'], $data['revelation_ts_msg'], $data['revelation_msg_id']);
        $payload = $this->buildPayload($data, $window);

        if ($format === 'json') {
            $output->writeln((string) json_encode($payload, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_UNICODE));
        } else {
            $output->writeln($this->renderMarkdown($payload));
        }

        return Command::SUCCESS;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function loadIoc(string $obsId): ?array
    {
        $row = $this->conn->fetchAssociative(
            "SELECT
                ic.obs_id,
                ic.indicator_id,
                oi.msg_id AS revelation_msg_id,
                m.conv_id,
                m.ts_msg AS revelation_ts_msg,
                m.subject AS revelation_subject,
                m.body_text AS revelation_body,
                d_rev.code AS revelation_direction,
                c.scam_type_id,
                st.code AS scam_type_code,
                p.persona_code,
                p.persona_label,
                i.type AS ioc_type,
                i.value AS ioc_value,
                i.value_norm AS ioc_value_norm,
                ic.stimulus_type AS pred_stimulus_type,
                ic.urgency_score AS pred_urgency_score,
                ic.hesitation_detected AS pred_hesitation_detected,
                ic.language_switch AS pred_language_switch,
                ic.semantic_role AS pred_semantic_role,
                ic.context_excerpt AS pred_context_excerpt,
                ic.enrichment_confidence AS pred_enrichment_confidence,
                ic.enrichment_model AS pred_model,
                ic.revelation_turn,
                ic.total_turns,
                ic.co_revealed_types,
                ic.co_revealed_count,
                ic.engagement_hours
             FROM ioc_context ic
             JOIN observed_ioc oi ON oi.obs_id = ic.obs_id
             JOIN message m ON m.msg_id = oi.msg_id
             JOIN lkp_direction d_rev ON d_rev.dir_id = m.direction
             JOIN conversation c ON c.conv_id = m.conv_id
             LEFT JOIN lkp_scam_type st ON st.scam_type_id = c.scam_type_id
             LEFT JOIN persona p ON p.persona_id = c.persona_id
             JOIN indicator i ON i.indicator_id = ic.indicator_id
             WHERE ic.obs_id = :id
               AND ic.enrichment_status = 'enriched'",
            ['id' => $obsId],
        );

        return $row === false ? null : $row;
    }

    /**
     * @return array{previous_inbound: ?array<string,mixed>, stimulus: ?array<string,mixed>}
     */
    private function loadMessageWindow(string $convId, string $revelationTs, string $revelationMsgId): array
    {
        // Stimulus = most recent OUTBOUND in same conv strictly before revelation.
        $stimulus = $this->conn->fetchAssociative(
            "SELECT m.msg_id, m.ts_msg, m.subject, m.body_text, d.code AS direction
             FROM message m
             JOIN lkp_direction d ON d.dir_id = m.direction
             WHERE m.conv_id = :conv
               AND m.ts_msg < :ts
               AND d.code = 'out'
             ORDER BY m.ts_msg DESC
             LIMIT 1",
            ['conv' => $convId, 'ts' => $revelationTs],
        );

        // Previous inbound = most recent INBOUND in same conv strictly before stimulus (if stimulus exists,
        // else before revelation), excluding the revelation message itself.
        $upperBound = $stimulus === false ? $revelationTs : $stimulus['ts_msg'];
        $previousInbound = $this->conn->fetchAssociative(
            "SELECT m.msg_id, m.ts_msg, m.subject, m.body_text, d.code AS direction
             FROM message m
             JOIN lkp_direction d ON d.dir_id = m.direction
             WHERE m.conv_id = :conv
               AND m.ts_msg < :upper
               AND d.code = 'in'
               AND m.msg_id != :rev
             ORDER BY m.ts_msg DESC
             LIMIT 1",
            ['conv' => $convId, 'upper' => $upperBound, 'rev' => $revelationMsgId],
        );

        return [
            'previous_inbound' => $previousInbound === false ? null : $previousInbound,
            'stimulus' => $stimulus === false ? null : $stimulus,
        ];
    }

    /**
     * @param array<string, mixed>                                                          $data
     * @param array{previous_inbound: ?array<string,mixed>, stimulus: ?array<string,mixed>} $window
     *
     * @return array<string, mixed>
     */
    private function buildPayload(array $data, array $window): array
    {
        return [
            'obs_id' => $data['obs_id'],
            'indicator_id' => $data['indicator_id'],
            'conv_id' => $data['conv_id'],
            'scam_type' => $data['scam_type_code'],
            'persona' => [
                'code' => $data['persona_code'],
                'label' => $data['persona_label'],
            ],
            'ioc' => [
                'type' => $data['ioc_type'],
                'value' => $data['ioc_value'],
                'value_norm' => $data['ioc_value_norm'],
            ],
            'turn' => [
                'revelation_turn' => $data['revelation_turn'] !== null ? (int) $data['revelation_turn'] : null,
                'total_turns' => $data['total_turns'] !== null ? (int) $data['total_turns'] : null,
            ],
            'context' => [
                'engagement_hours' => $data['engagement_hours'] !== null ? (float) $data['engagement_hours'] : null,
                'co_revealed_types' => self::parsePgArray($data['co_revealed_types'] ?? null),
                'co_revealed_count' => (int) ($data['co_revealed_count'] ?? 0),
            ],
            'window' => [
                'previous_inbound' => $window['previous_inbound'] !== null ? $this->shapeMessage($window['previous_inbound']) : null,
                'stimulus' => $window['stimulus'] !== null ? $this->shapeMessage($window['stimulus']) : null,
                'revelation' => [
                    'msg_id' => $data['revelation_msg_id'],
                    'ts_msg' => $data['revelation_ts_msg'],
                    'direction' => $data['revelation_direction'],
                    'subject' => $data['revelation_subject'],
                    'body_text' => $data['revelation_body'],
                ],
            ],
            'production_predictions' => [
                'stimulus_type' => $data['pred_stimulus_type'],
                'urgency_score' => $data['pred_urgency_score'] !== null ? (float) $data['pred_urgency_score'] : null,
                'hesitation_detected' => $data['pred_hesitation_detected'],
                'language_switch' => $data['pred_language_switch'],
                'semantic_role' => $data['pred_semantic_role'],
                'context_excerpt' => $data['pred_context_excerpt'],
                'enrichment_confidence' => $data['pred_enrichment_confidence'] !== null
                    ? (float) $data['pred_enrichment_confidence']
                    : null,
                'model' => $data['pred_model'],
            ],
        ];
    }

    /**
     * @param array<string, mixed> $msg
     *
     * @return array<string, mixed>
     */
    private function shapeMessage(array $msg): array
    {
        return [
            'msg_id' => $msg['msg_id'] ?? null,
            'ts_msg' => $msg['ts_msg'] ?? null,
            'direction' => $msg['direction'] ?? null,
            'subject' => $msg['subject'] ?? null,
            'body_text' => $msg['body_text'] ?? null,
        ];
    }

    /**
     * @param array<string, mixed> $p
     */
    private function renderMarkdown(array $p): string
    {
        $out = "# IOC {$p['obs_id']}\n\n";
        $out .= "**Scam type**: {$p['scam_type']} · **Persona**: {$p['persona']['label']} ({$p['persona']['code']})\n";
        $out .= "**Turn**: {$p['turn']['revelation_turn']}/{$p['turn']['total_turns']} · ";
        $out .= '**Engagement**: ' . number_format((float) ($p['context']['engagement_hours'] ?? 0), 1) . "h\n\n";

        $out .= "## IOC\n";
        $out .= "- **Type**: `{$p['ioc']['type']}`\n";
        $out .= "- **Value**: `{$p['ioc']['value']}`\n";
        $coTypes = is_array($p['context']['co_revealed_types']) ? implode(', ', $p['context']['co_revealed_types']) : '';
        $out .= "- **Co-revealed**: {$p['context']['co_revealed_count']} ({$coTypes})\n\n";

        $out .= "## Message window (what the LLM saw at enrichment)\n\n";

        if ($p['window']['previous_inbound'] !== null) {
            $out .= "### Previous inbound (scammer, before our reply) [direction={$p['window']['previous_inbound']['direction']}]\n";
            $out .= '**Subject**: ' . ($p['window']['previous_inbound']['subject'] ?? '(none)') . "\n\n";
            $out .= "```\n" . trim((string) ($p['window']['previous_inbound']['body_text'] ?? '')) . "\n```\n\n";
        } else {
            $out .= "### Previous inbound\n*(not available — revelation may be a first-contact spam)*\n\n";
        }

        if ($p['window']['stimulus'] !== null) {
            $out .= "### Stimulus (our honeypot outbound) [direction={$p['window']['stimulus']['direction']}]\n";
            $out .= '**Subject**: ' . ($p['window']['stimulus']['subject'] ?? '(none)') . "\n\n";
            $out .= "```\n" . trim((string) ($p['window']['stimulus']['body_text'] ?? '')) . "\n```\n\n";
        } else {
            $out .= "### Stimulus\n*(not available — first-contact case, no honeypot reply yet)*\n\n";
        }

        $out .= "### Revelation message (contains the IOC) [direction={$p['window']['revelation']['direction']}]\n";
        $out .= '**Subject**: ' . ($p['window']['revelation']['subject'] ?? '(none)') . "\n\n";
        $out .= "```\n" . trim((string) ($p['window']['revelation']['body_text'] ?? '')) . "\n```\n\n";

        $out .= "## Production predictions (gpt-4o-mini, current pipeline)\n\n";
        $pp = $p['production_predictions'];
        $out .= "| Field | Value |\n|---|---|\n";
        $out .= '| `stimulus_type` | `' . ($pp['stimulus_type'] ?? '(null)') . "` |\n";
        $out .= '| `urgency_score` | ' . ($pp['urgency_score'] !== null ? number_format((float) $pp['urgency_score'], 3) : '(null)') . " |\n";
        $out .= '| `hesitation_detected` | ' . self::boolStr($pp['hesitation_detected']) . " |\n";
        $out .= '| `language_switch` | ' . self::boolStr($pp['language_switch']) . " |\n";
        $out .= '| `semantic_role` | `' . ($pp['semantic_role'] ?? '(null)') . "` |\n";
        $out .= '| `context_excerpt` | _"' . ($pp['context_excerpt'] ?? '(null)') . "\"_ |\n";
        $out .= '| `enrichment_confidence` | ' . ($pp['enrichment_confidence'] !== null ? number_format((float) $pp['enrichment_confidence'], 3) : '(null)') . " |\n";
        $out .= '| `model` | `' . ($pp['model'] ?? '(null)') . "` |\n";

        return $out;
    }

    private static function boolStr(mixed $v): string
    {
        if ($v === true || $v === 'true' || $v === 't' || $v === 1 || $v === '1') {
            return '**true**';
        }

        if ($v === false || $v === 'false' || $v === 'f' || $v === 0 || $v === '0') {
            return 'false';
        }

        return '(null)';
    }

    /**
     * @return list<string>
     */
    private static function parsePgArray(mixed $literal): array
    {
        if (!\is_string($literal) || $literal === '' || $literal === '{}') {
            return [];
        }
        $inner = trim($literal, '{}');
        $parts = preg_split('/\s*,\s*/', $inner);

        if ($parts === false) {
            return [];
        }
        $out = [];

        foreach ($parts as $p) {
            $p = trim($p, "\"'\\ \t\n\r\0\x0B");

            if ($p === '') {
                continue;
            }
            $out[] = $p;
        }

        return $out;
    }
}
