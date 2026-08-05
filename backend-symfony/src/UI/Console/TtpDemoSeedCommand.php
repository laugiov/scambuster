<?php

declare(strict_types=1);

namespace App\UI\Console;

use App\Domain\Communication\Ttp;
use Doctrine\DBAL\Connection;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Seed plausible TTP observations for the demo dataset, deterministically and
 * WITHOUT an LLM.
 *
 * The public demo runs the mock LLM provider, so the real extractor cannot
 * produce any TTP observation there and the TTP screens would stay empty. This
 * command fills that gap the same way the demo already seeds threat-actor
 * psychological profiles: a runtime seed step, driven off the real demo message
 * bodies, that inserts credible observations so the cluster panel, timeline and
 * explorer render with data.
 *
 * These rows are deterministic, phrase-matched approximations — NOT real model
 * extractions. Each inbound message is matched against a curated, scam-type-aware
 * set of candidate tactics and a small phrase lexicon; a tactic is recorded only
 * when one of its trigger phrases is actually present in the text, and the stored
 * evidence is the VERBATIM matched substring with UTF-8 code-point offsets, so it
 * behaves exactly like a real observation for every downstream consumer. A message
 * with no phrase hit gets no observation, which keeps the seeded distribution
 * realistic.
 *
 * The candidate list is rotated by the message's position within its conversation
 * before the per-message cap is applied, so a long thread does not keep truncating
 * to the same three leading tactics: later candidates get their turn on later
 * messages and the taxonomy is exercised far more evenly across the demo.
 *
 * Scope is the set of inbound, non-soft-deleted messages that belong to a
 * non-soft-deleted conversation and do NOT yet carry a TTP observation, so the
 * command is idempotent and resumable: a re-run skips every message that already
 * produced an observation and the ON CONFLICT (msg_id, ttp_id) DO NOTHING insert
 * makes even an overlapping run a no-op. --purge clears existing observations
 * first (for a clean reseed) and --dry-run reports what WOULD be written without
 * touching the database.
 */
#[AsCommand(
    name: 'scambuster:ttp:demo-seed',
    description: 'Seed deterministic, plausible TTP observations for the demo dataset (no LLM).',
)]
final class TtpDemoSeedCommand extends Command
{
    private const DEFAULT_LIMIT = 5000;

    private const MODEL = 'demo-seed';

    private const PROMPT_VERSION = 'demo';

    private const MAX_TTPS_PER_MESSAGE = 3;

    /**
     * Confirmed/review boundary; mirrors the real handler's threshold so the
     * demo shows both statuses exactly as a live deployment would.
     */
    private const CONFIRMED_THRESHOLD = 0.55;

    private const STRONG_CONFIDENCE = 0.85;

    private const WEAK_CONFIDENCE = 0.5;

    /**
     * scam_type code => ordered list of candidate TTP codes plausible for that
     * genre. Order matters twice: the list is rotated by message position, and
     * earlier candidates in the rotated window win when a message would otherwise
     * exceed the per-message tactic cap. Codes are laid out roughly by kill-chain
     * phase (hook → trust → payment → escalation → channel → exit) so the rotation
     * surfaces a plausible spread as a conversation deepens. TTPs are transverse,
     * so a code may appear under several genres.
     *
     * @var array<string, list<string>>
     */
    private const CANDIDATE_TTPS = [
        'INVOICE_FRAUD' => ['SB-T003', 'SB-T005', 'SB-T010', 'SB-T011', 'SB-T013', 'SB-T012', 'SB-T016', 'SB-T015', 'SB-T019', 'SB-T018', 'SB-T022', 'SB-T021', 'SB-T017', 'SB-T020', 'SB-T024', 'SB-T025', 'SB-T026'],
        'CEO_FRAUD' => ['SB-T002', 'SB-T013', 'SB-T009', 'SB-T022', 'SB-T017', 'SB-T020', 'SB-T024'],
        'ROMANCE' => ['SB-T006', 'SB-T007', 'SB-T021', 'SB-T009', 'SB-T012', 'SB-T016'],
        'INVESTMENT' => ['SB-T027', 'SB-T001', 'SB-T002', 'SB-T008', 'SB-T005', 'SB-T013', 'SB-T019', 'SB-T012', 'SB-T016', 'SB-T025', 'SB-T017'],
        'LOTTERY' => ['SB-T001', 'SB-T019', 'SB-T012', 'SB-T016', 'SB-T017', 'SB-T013', 'SB-T025', 'SB-T018'],
        'ADVANCE_FEE_419' => ['SB-T001', 'SB-T002', 'SB-T007', 'SB-T005', 'SB-T010', 'SB-T019', 'SB-T012', 'SB-T016', 'SB-T018', 'SB-T013', 'SB-T024', 'SB-T026', 'SB-T009', 'SB-T017', 'SB-T022'],
        'TECH_SUPPORT' => ['SB-T003', 'SB-T019', 'SB-T020', 'SB-T023', 'SB-T016', 'SB-T017', 'SB-T014', 'SB-T010'],
        'PHISHING' => ['SB-T003', 'SB-T004', 'SB-T019', 'SB-T020', 'SB-T016', 'SB-T017', 'SB-T014'],
        'PHISH_CREDENTIALS' => ['SB-T004', 'SB-T014', 'SB-T003', 'SB-T019', 'SB-T016', 'SB-T020', 'SB-T017'],
        'PHISH_MALWARE' => ['SB-T004', 'SB-T003', 'SB-T010', 'SB-T016', 'SB-T017', 'SB-T014'],
        'JOB_OFFER' => ['SB-T001', 'SB-T014', 'SB-T023', 'SB-T005', 'SB-T012', 'SB-T016', 'SB-T006', 'SB-T018'],
        'CHARITY' => ['SB-T007', 'SB-T021', 'SB-T001', 'SB-T006', 'SB-T012', 'SB-T016', 'SB-T013'],
        'COLD_SERVICE_SPAM' => ['SB-T003', 'SB-T023', 'SB-T004', 'SB-T024', 'SB-T006', 'SB-T016'],
        'UNKNOWN' => ['SB-T003', 'SB-T001', 'SB-T004', 'SB-T019', 'SB-T013', 'SB-T014', 'SB-T016'],
    ];

    /**
     * TTP code => lowercase trigger phrases with a confidence tier. Phrases are
     * drawn from the taxonomy's example formulations. A strong, unambiguous
     * phrase yields a confirmed observation; a weaker, more generic one yields a
     * review observation — the same confidence/threshold split a live extractor
     * would produce.
     *
     * @var array<string, list<array{0: string, 1: float}>>
     */
    private const PHRASE_MAP = [
        'SB-T001' => [['you have been selected', self::STRONG_CONFIDENCE], ['beneficiary', self::STRONG_CONFIDENCE], ['next of kin', self::STRONG_CONFIDENCE], ['inheritance', self::STRONG_CONFIDENCE], ['won our', self::STRONG_CONFIDENCE], ['business opportunity', self::STRONG_CONFIDENCE], ['business proposal', self::STRONG_CONFIDENCE], ['unclaimed', self::STRONG_CONFIDENCE], ['lottery', self::WEAK_CONFIDENCE], ['partnership', self::WEAK_CONFIDENCE], ['compensation', self::WEAK_CONFIDENCE]],
        'SB-T002' => [['united nations', self::STRONG_CONFIDENCE], ['central bank', self::STRONG_CONFIDENCE], ['ministry of', self::STRONG_CONFIDENCE], ['the fbi', self::STRONG_CONFIDENCE], ['high court', self::STRONG_CONFIDENCE], ['the imf', self::STRONG_CONFIDENCE], ['monetary fund', self::STRONG_CONFIDENCE], ['federal', self::WEAK_CONFIDENCE], ['on behalf of', self::WEAK_CONFIDENCE]],
        'SB-T003' => [['invoice', self::STRONG_CONFIDENCE], ['dhl', self::STRONG_CONFIDENCE], ['fedex', self::STRONG_CONFIDENCE], ['paypal', self::STRONG_CONFIDENCE], ['microsoft', self::STRONG_CONFIDENCE], ['support team', self::STRONG_CONFIDENCE], ['sales department', self::STRONG_CONFIDENCE], ['amazon', self::WEAK_CONFIDENCE], ['your account', self::WEAK_CONFIDENCE], ['on hold', self::WEAK_CONFIDENCE], ['customer service', self::WEAK_CONFIDENCE]],
        'SB-T004' => [['click here', self::STRONG_CONFIDENCE], ['click the link', self::STRONG_CONFIDENCE], ['open the attached', self::STRONG_CONFIDENCE], ['attached invoice', self::STRONG_CONFIDENCE], ['verify your', self::STRONG_CONFIDENCE], ['login page', self::STRONG_CONFIDENCE], ['secure portal', self::STRONG_CONFIDENCE], ['download', self::WEAK_CONFIDENCE], ['this link', self::WEAK_CONFIDENCE], ['https://', self::WEAK_CONFIDENCE]],
        'SB-T005' => [['attached the certificate', self::STRONG_CONFIDENCE], ['copy of my passport', self::STRONG_CONFIDENCE], ['court approval', self::STRONG_CONFIDENCE], ['certificate of deposit', self::STRONG_CONFIDENCE], ['my identification', self::STRONG_CONFIDENCE], ['please find attached', self::STRONG_CONFIDENCE], ['company registration', self::STRONG_CONFIDENCE], ['on our letterhead', self::STRONG_CONFIDENCE]],
        'SB-T006' => [['my dear', self::STRONG_CONFIDENCE], ['dearest', self::STRONG_CONFIDENCE], ['my love', self::STRONG_CONFIDENCE], ['you remind me', self::STRONG_CONFIDENCE], ['how is your family', self::STRONG_CONFIDENCE], ['i can trust you', self::STRONG_CONFIDENCE], ['feelings for you', self::STRONG_CONFIDENCE]],
        'SB-T007' => [['god has', self::STRONG_CONFIDENCE], ['god-fearing', self::STRONG_CONFIDENCE], ['by the grace of god', self::STRONG_CONFIDENCE], ['the lord', self::STRONG_CONFIDENCE], ['in jesus', self::STRONG_CONFIDENCE], ['god bless', self::WEAK_CONFIDENCE], ['pray', self::WEAK_CONFIDENCE]],
        'SB-T008' => [['received his payment', self::STRONG_CONFIDENCE], ['other beneficiaries', self::STRONG_CONFIDENCE], ['already been paid', self::STRONG_CONFIDENCE], ['satisfied clients', self::STRONG_CONFIDENCE], ['our clients have', self::STRONG_CONFIDENCE], ['many people have', self::WEAK_CONFIDENCE]],
        'SB-T009' => [['tell no one', self::STRONG_CONFIDENCE], ['strictly between us', self::STRONG_CONFIDENCE], ['keep this confidential', self::STRONG_CONFIDENCE], ['do not disclose', self::STRONG_CONFIDENCE], ['confidential', self::WEAK_CONFIDENCE], ['between us', self::WEAK_CONFIDENCE]],
        'SB-T010' => [['my barrister', self::STRONG_CONFIDENCE], ['the bank director', self::STRONG_CONFIDENCE], ['my lawyer', self::STRONG_CONFIDENCE], ['my solicitor', self::STRONG_CONFIDENCE], ['the diplomat', self::STRONG_CONFIDENCE], ['our representative', self::STRONG_CONFIDENCE], ['account manager', self::WEAK_CONFIDENCE], ['our agent', self::WEAK_CONFIDENCE]],
        'SB-T011' => [['was hacked', self::STRONG_CONFIDENCE], ['due to the holidays', self::STRONG_CONFIDENCE], ['new government regulations', self::STRONG_CONFIDENCE], ['the delay is', self::STRONG_CONFIDENCE], ['apologize for the', self::STRONG_CONFIDENCE], ['hence this', self::STRONG_CONFIDENCE]],
        'SB-T012' => [['transfer charge', self::STRONG_CONFIDENCE], ['processing fee', self::STRONG_CONFIDENCE], ['clearance fee', self::STRONG_CONFIDENCE], ['activation fee', self::STRONG_CONFIDENCE], ['insurance fee', self::STRONG_CONFIDENCE], ['release fee', self::STRONG_CONFIDENCE], ['advance payment', self::STRONG_CONFIDENCE], ['handling fee', self::STRONG_CONFIDENCE], ['upfront', self::WEAK_CONFIDENCE]],
        'SB-T013' => [['iban', self::STRONG_CONFIDENCE], ['swift', self::STRONG_CONFIDENCE], ['western union', self::STRONG_CONFIDENCE], ['moneygram', self::STRONG_CONFIDENCE], ['usdt', self::STRONG_CONFIDENCE], ['account number', self::STRONG_CONFIDENCE], ['wire the', self::STRONG_CONFIDENCE], ['bitcoin wallet', self::STRONG_CONFIDENCE], ['receiver name', self::STRONG_CONFIDENCE], ['routing number', self::STRONG_CONFIDENCE], ['bank account', self::WEAK_CONFIDENCE]],
        'SB-T014' => [['your bank details', self::STRONG_CONFIDENCE], ['copy of your id', self::STRONG_CONFIDENCE], ['date of birth', self::STRONG_CONFIDENCE], ['your password', self::STRONG_CONFIDENCE], ['verification code', self::STRONG_CONFIDENCE], ['social security', self::STRONG_CONFIDENCE], ['your full details', self::STRONG_CONFIDENCE], ['scan of your', self::STRONG_CONFIDENCE], ['personal information', self::WEAK_CONFIDENCE]],
        'SB-T015' => [['overpayment', self::STRONG_CONFIDENCE], ['transferred by mistake', self::STRONG_CONFIDENCE], ['return the excess', self::STRONG_CONFIDENCE], ['sent too much', self::STRONG_CONFIDENCE], ['refund the difference', self::STRONG_CONFIDENCE]],
        'SB-T016' => [['gift cards', self::STRONG_CONFIDENCE], ['apple gift', self::STRONG_CONFIDENCE], ['steam card', self::STRONG_CONFIDENCE], ['use bitcoin instead', self::STRONG_CONFIDENCE], ['alternative payment', self::STRONG_CONFIDENCE], ['different method', self::WEAK_CONFIDENCE], ['buy bitcoin', self::WEAK_CONFIDENCE]],
        'SB-T017' => [['within 24 hours', self::STRONG_CONFIDENCE], ['24 hours', self::STRONG_CONFIDENCE], ['immediately', self::STRONG_CONFIDENCE], ['as soon as possible', self::STRONG_CONFIDENCE], ['deadline', self::STRONG_CONFIDENCE], ['expires', self::STRONG_CONFIDENCE], ['act now', self::STRONG_CONFIDENCE], ['right away', self::STRONG_CONFIDENCE], ['without delay', self::STRONG_CONFIDENCE], ['urgent', self::WEAK_CONFIDENCE], ['today', self::WEAK_CONFIDENCE]],
        'SB-T018' => [['additional fee', self::STRONG_CONFIDENCE], ['one last charge', self::STRONG_CONFIDENCE], ['anti-terrorism', self::STRONG_CONFIDENCE], ['customs fee', self::STRONG_CONFIDENCE], ['extra charge', self::STRONG_CONFIDENCE], ['another payment', self::STRONG_CONFIDENCE]],
        'SB-T019' => [['certificate of ownership', self::STRONG_CONFIDENCE], ['clearance code', self::STRONG_CONFIDENCE], ['anti-money laundering', self::STRONG_CONFIDENCE], ['is mandatory', self::STRONG_CONFIDENCE], ['required by law', self::STRONG_CONFIDENCE], ['regulations require', self::STRONG_CONFIDENCE], ['compliance department', self::STRONG_CONFIDENCE], ['mandatory', self::WEAK_CONFIDENCE], ['cot', self::WEAK_CONFIDENCE]],
        'SB-T020' => [['legal action', self::STRONG_CONFIDENCE], ['a warrant', self::STRONG_CONFIDENCE], ['be confiscated', self::STRONG_CONFIDENCE], ['be permanently', self::STRONG_CONFIDENCE], ['be arrested', self::STRONG_CONFIDENCE], ['will be blocked', self::STRONG_CONFIDENCE], ['will be suspended', self::STRONG_CONFIDENCE], ['penalty', self::WEAK_CONFIDENCE], ['be terminated', self::WEAK_CONFIDENCE]],
        'SB-T021' => [['in the hospital', self::STRONG_CONFIDENCE], ['do not abandon', self::STRONG_CONFIDENCE], ['my last money', self::STRONG_CONFIDENCE], ['dying', self::STRONG_CONFIDENCE], ['in tears', self::STRONG_CONFIDENCE], ['suffering', self::STRONG_CONFIDENCE], ['difficult time', self::WEAK_CONFIDENCE], ['please help me', self::WEAK_CONFIDENCE], ['desperate', self::WEAK_CONFIDENCE]],
        'SB-T022' => [['no time for contracts', self::STRONG_CONFIDENCE], ['registry is confidential', self::STRONG_CONFIDENCE], ['only delay', self::STRONG_CONFIDENCE], ['no need to verify', self::STRONG_CONFIDENCE], ['just trust me', self::STRONG_CONFIDENCE]],
        'SB-T023' => [['whatsapp', self::STRONG_CONFIDENCE], ['telegram', self::STRONG_CONFIDENCE], ['call me on', self::STRONG_CONFIDENCE], ['text me', self::STRONG_CONFIDENCE], ['my private email', self::STRONG_CONFIDENCE], ['reach me on', self::STRONG_CONFIDENCE], ['signal app', self::STRONG_CONFIDENCE]],
        'SB-T024' => [['reply only to', self::STRONG_CONFIDENCE], ['do not contact the bank', self::STRONG_CONFIDENCE], ['exclusively with', self::STRONG_CONFIDENCE], ['only through me', self::STRONG_CONFIDENCE], ['only deal with', self::STRONG_CONFIDENCE]],
        'SB-T025' => [['final message', self::STRONG_CONFIDENCE], ['last chance', self::STRONG_CONFIDENCE], ['pay today or', self::STRONG_CONFIDENCE], ['final notice', self::STRONG_CONFIDENCE], ['this is your last', self::STRONG_CONFIDENCE]],
        'SB-T026' => [['since you did not respond', self::STRONG_CONFIDENCE], ['contacting you again', self::STRONG_CONFIDENCE], ['better arrangement', self::STRONG_CONFIDENCE], ['taken over your file', self::STRONG_CONFIDENCE], ['now reduced to', self::STRONG_CONFIDENCE]],
        'SB-T027' => [['your balance is now', self::STRONG_CONFIDENCE], ['your profit', self::STRONG_CONFIDENCE], ['up 34%', self::STRONG_CONFIDENCE], ['your portfolio', self::STRONG_CONFIDENCE], ['dashboard screenshot', self::STRONG_CONFIDENCE], ['has earned', self::STRONG_CONFIDENCE], ['guaranteed returns', self::STRONG_CONFIDENCE], ['high returns', self::STRONG_CONFIDENCE]],
    ];

    public function __construct(
        private readonly Connection $connection,
        private readonly LoggerInterface $logger,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Maximum number of inbound messages to scan.', (string) self::DEFAULT_LIMIT)
            ->addOption('purge', null, InputOption::VALUE_NONE, 'Delete existing ttp_observation rows before seeding (clean reseed).')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Report what would be written without touching the database.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $dryRun = (bool) $input->getOption('dry-run');
        $purge = (bool) $input->getOption('purge');

        $limitRaw = $input->getOption('limit');
        $limit = is_numeric($limitRaw) ? max(1, (int) $limitRaw) : self::DEFAULT_LIMIT;

        $ttpIdByCode = $this->loadTtpIds();

        if ($ttpIdByCode === []) {
            $io->error('lkp_ttp is empty — run the migrations/reference fixtures before seeding demo TTPs.');

            return Command::FAILURE;
        }

        $io->title(sprintf(
            'TTP demo seed — %s%s | scan up to %d message(s)',
            $dryRun ? 'DRY-RUN (no writes)' : 'APPLY (writes)',
            $purge ? ' | purge' : '',
            $limit,
        ));

        if ($purge) {
            $existingRaw = $this->connection->fetchOne('SELECT COUNT(*) FROM ttp_observation');
            $existing = is_numeric($existingRaw) ? (int) $existingRaw : 0;

            if ($dryRun) {
                $io->note(sprintf('Would delete %d existing ttp_observation row(s) before reseeding.', $existing));
            } else {
                $this->connection->executeStatement('DELETE FROM ttp_observation');
                $io->note(sprintf('Purged %d existing ttp_observation row(s).', $existing));
            }
        }

        $messages = $this->findScope($limit);

        if ($messages === []) {
            $io->success('No in-scope inbound messages without an observation. Nothing to do.');

            return Command::SUCCESS;
        }

        $scanned = 0;
        $tagged = 0;
        $written = 0;
        $confirmed = 0;
        $review = 0;
        $failed = 0;
        /** @var array<string, int> $distribution */
        $distribution = [];
        /** @var array<string, int> $convMessageIndex */
        $convMessageIndex = [];

        foreach ($messages as $row) {
            ++$scanned;

            try {
                $analysedText = ($row['subject'] ?? '') . "\n\n" . $row['body_text'];
                $scamCode = $row['scam_code'] ?? 'UNKNOWN';
                // 1-based position of this inbound message within its conversation
                // (rows arrive chronologically per conversation from findScope);
                // drives the candidate rotation so the per-message cap stops
                // truncating to the same leading tactics on every message.
                $messageIndex = $convMessageIndex[$row['conv_id']] = ($convMessageIndex[$row['conv_id']] ?? 0) + 1;
                $tags = $this->tagMessage($scamCode, $analysedText, $messageIndex);

                if ($tags === []) {
                    continue;
                }

                ++$tagged;

                foreach ($tags as $tag) {
                    $ttpId = $ttpIdByCode[$tag['ttp_code']] ?? null;

                    if ($ttpId === null) {
                        // A candidate code with no active taxonomy row: skip rather
                        // than fabricate an observation with no FK target.
                        continue;
                    }

                    $status = $tag['confidence'] >= self::CONFIRMED_THRESHOLD ? 'confirmed' : 'review';
                    $distribution[$tag['ttp_code']] = ($distribution[$tag['ttp_code']] ?? 0) + 1;

                    if ($status === 'confirmed') {
                        ++$confirmed;
                    } else {
                        ++$review;
                    }

                    if ($dryRun) {
                        continue;
                    }

                    $inserted = $this->insertObservation($row['msg_id'], $row['conv_id'], $ttpId, $tag, $status);

                    if ($inserted) {
                        ++$written;
                    }
                }
            } catch (\Throwable $e) {
                ++$failed;
                $this->logger->error('[TtpDemoSeed] Failed to seed message', [
                    'msg_id' => $row['msg_id'],
                    'error' => $e->getMessage(),
                ]);
                $io->warning(sprintf('Error seeding message %s: %s', substr($row['msg_id'], 0, 8), $e->getMessage()));
            }
        }

        $this->renderSummary($io, $dryRun, $scanned, $tagged, $written, $confirmed, $review, $failed, $distribution);

        return $failed > 0 ? Command::FAILURE : Command::SUCCESS;
    }

    /**
     * Load the active taxonomy as a code => ttp_id map for FK resolution.
     *
     * @return array<string, int>
     */
    private function loadTtpIds(): array
    {
        $rows = $this->connection->fetchAllAssociative('SELECT code, ttp_id FROM lkp_ttp WHERE active = true');
        $map = [];

        foreach ($rows as $row) {
            $code = $row['code'];
            $ttpId = $row['ttp_id'];

            if (\is_string($code) && is_numeric($ttpId)) {
                $map[$code] = (int) $ttpId;
            }
        }

        return $map;
    }

    /**
     * Resolve the in-scope inbound messages: not soft-deleted, in a
     * non-soft-deleted conversation, and without a TTP observation yet.
     *
     * @return list<array{msg_id: string, conv_id: string, subject: ?string, body_text: string, scam_code: ?string}>
     */
    private function findScope(int $limit): array
    {
        $sql = 'SELECT m.msg_id, m.conv_id, m.subject, m.body_text, st.code AS scam_code'
            . ' FROM message m'
            . " JOIN lkp_direction d ON d.dir_id = m.direction AND d.code = 'in'"
            . ' JOIN conversation c ON c.conv_id = m.conv_id AND c.deleted_at IS NULL'
            . ' LEFT JOIN lkp_scam_type st ON st.scam_type_id = c.scam_type_id'
            . ' LEFT JOIN ttp_observation o ON o.msg_id = m.msg_id'
            . ' WHERE m.deleted_at IS NULL AND o.obs_id IS NULL'
            . ' ORDER BY m.ts_msg ASC, m.msg_id ASC'
            . ' LIMIT ' . $limit;

        /** @var list<array{msg_id: string, conv_id: string, subject: ?string, body_text: string, scam_code: ?string}> $rows */
        $rows = $this->connection->fetchAllAssociative($sql);

        return $rows;
    }

    /**
     * Match one message's analysed text against the candidate tactics of its
     * scam type. A tactic is recorded only when one of its trigger phrases is
     * present; the evidence is the verbatim matched substring and the offsets
     * are UTF-8 code-point offsets ([start, end) with end exclusive) — the exact
     * convention the real extractor uses so seeded rows are indistinguishable
     * from extracted ones downstream. Capped at MAX_TTPS_PER_MESSAGE tactics.
     *
     * The candidate list is rotated by the message's position so consecutive
     * messages scan from a different starting point and later tactics are not
     * permanently starved by the cap.
     *
     * @return list<array{ttp_code: string, confidence: float, evidence: string, evidence_start: int, evidence_end: int}>
     */
    private function tagMessage(string $scamCode, string $analysedText, int $messageIndex): array
    {
        $candidates = $this->rotateCandidates(
            self::CANDIDATE_TTPS[$scamCode] ?? self::CANDIDATE_TTPS['UNKNOWN'],
            $messageIndex,
        );
        $haystack = mb_strtolower($analysedText);

        $tags = [];

        foreach ($candidates as $code) {
            if (\count($tags) >= self::MAX_TTPS_PER_MESSAGE) {
                break;
            }

            foreach (self::PHRASE_MAP[$code] as [$phrase, $confidence]) {
                $pos = mb_strpos($haystack, $phrase);

                if ($pos === false) {
                    continue;
                }

                // Recover the original-cased substring at the matched position.
                $evidence = mb_substr($analysedText, $pos, mb_strlen($phrase));

                // A length-changing lowercase fold earlier in the text would shift
                // every subsequent position; only keep the match when the original
                // substring lowercases back to the phrase, so the offsets are exact
                // and the evidence is a genuine verbatim substring (never fabricate
                // an offset).
                if (mb_strtolower($evidence) !== $phrase) {
                    continue;
                }

                $tags[] = [
                    'ttp_code' => $code,
                    'confidence' => $confidence,
                    'evidence' => $evidence,
                    'evidence_start' => $pos,
                    'evidence_end' => $pos + mb_strlen($evidence),
                ];

                break;
            }
        }

        return $tags;
    }

    /**
     * Rotate a candidate list by a stride of MAX_TTPS_PER_MESSAGE per message
     * position, so message 1 scans candidates [0, 1, 2, ...], message 2 scans
     * from index 3, message 3 from index 6, and so on (wrapping around). This
     * spreads the per-message cap across the whole list instead of always
     * truncating to the first few codes. Deterministic — a pure function of the
     * list and the 1-based message index.
     *
     * @param list<string> $candidates
     *
     * @return list<string>
     */
    private function rotateCandidates(array $candidates, int $messageIndex): array
    {
        $count = \count($candidates);

        if ($count === 0) {
            return $candidates;
        }

        $offset = (($messageIndex - 1) * self::MAX_TTPS_PER_MESSAGE) % $count;

        if ($offset === 0) {
            return $candidates;
        }

        return array_merge(\array_slice($candidates, $offset), \array_slice($candidates, 0, $offset));
    }

    /**
     * Idempotent raw-DBAL insert mirroring TtpObservationUpsertService's column
     * set (obs_id and created_at fall to their DB defaults). Returns true when a
     * row was actually inserted, false when the (msg_id, ttp_id) pair existed.
     *
     * @param array{ttp_code: string, confidence: float, evidence: string, evidence_start: int, evidence_end: int} $tag
     */
    private function insertObservation(string $msgId, string $convId, int $ttpId, array $tag, string $status): bool
    {
        $affected = (int) $this->connection->executeStatement(
            'INSERT INTO ttp_observation (msg_id, conv_id, ttp_id, confidence, evidence, evidence_start, evidence_end, status, taxonomy_version, extraction_model, prompt_version)
             VALUES (:msg_id, :conv_id, :ttp_id, :confidence, :evidence, :evidence_start, :evidence_end, :status, :taxonomy_version, :extraction_model, :prompt_version)
             ON CONFLICT (msg_id, ttp_id) DO NOTHING',
            [
                'msg_id' => $msgId,
                'conv_id' => $convId,
                'ttp_id' => $ttpId,
                'confidence' => $tag['confidence'],
                'evidence' => $tag['evidence'],
                'evidence_start' => $tag['evidence_start'],
                'evidence_end' => $tag['evidence_end'],
                'status' => $status,
                'taxonomy_version' => Ttp::TAXONOMY_VERSION,
                'extraction_model' => self::MODEL,
                'prompt_version' => self::PROMPT_VERSION,
            ]
        );

        return $affected === 1;
    }

    /**
     * @param array<string, int> $distribution
     */
    private function renderSummary(
        SymfonyStyle $io,
        bool $dryRun,
        int $scanned,
        int $tagged,
        int $written,
        int $confirmed,
        int $review,
        int $failed,
        array $distribution,
    ): void {
        $io->newLine();
        $io->section('Summary');
        $io->definitionList(
            ['Mode' => $dryRun ? 'dry-run (no writes)' : 'apply (persisted)'],
            ['Messages scanned' => $scanned],
            ['Messages tagged' => $tagged],
            ['Observations ' . ($dryRun ? 'would write' : 'written') => $dryRun ? ($confirmed + $review) : $written],
            ['Confirmed' => $confirmed],
            ['Review' => $review],
            ['Failed' => $failed],
        );

        if ($distribution !== []) {
            arsort($distribution);
            $io->section('TTP distribution');

            foreach ($distribution as $code => $count) {
                $io->writeln(sprintf('  %-12s %d', $code, $count));
            }
        }

        if ($dryRun) {
            $io->newLine();
            $io->note('Dry-run only — no rows were written. Re-run without --dry-run to persist.');
        }
    }
}
