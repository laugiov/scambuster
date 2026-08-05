<?php

declare(strict_types=1);

namespace App\Application\ThreatActor;

use App\Application\LLM\Port\LLMClientInterface;
use App\Domain\LLM\Event\LlmCallCompletedEvent;
use App\Domain\ThreatActor\CialdiniLever;
use App\Domain\ThreatActor\ThreatActorPsychProfile;
use Doctrine\DBAL\Connection;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

/**
 * Offline generator of the per-cluster threat-actor psychological profile.
 *
 * For one IOC cluster it reads the actor's inbound (scammer) messages plus the
 * already-persisted `ioc_context` behavioural aggregate, asks the LLM for the
 * dominant {@see CialdiniLever} + a behavioural narrative, and upserts one row
 * into `threat_actor_psych_profile`. Driven by app:actor:compute-psych-profiles.
 *
 * Never touches reply generation. Fail-safe: any error returns null and writes
 * nothing (the profile simply stays "pending"); no exception ever propagates.
 * Idempotent when the caller honours {@see exists()}.
 */
final readonly class ThreatActorPsychProfileGenerator
{
    private const MODEL = 'gpt-4o-mini';
    private const PROMPT_VERSION = 'v1';
    private const MAX_MESSAGES = 25;
    private const MAX_MESSAGE_CHARS = 400;
    private const ESCALATION_PATTERNS = ['rapid', 'gradual', 'stable', 'erratic', 'unknown'];
    private const SYSTEM_PROMPT = 'You are a threat-intelligence analyst profiling a scammer (threat actor) from their own messages. Respond with strict JSON only — no markdown, no preamble, no prose outside the JSON.';

    public function __construct(
        private LLMClientInterface $llmClient,
        private Connection $connection,
        private ClusterBehaviourReaderInterface $clusterBehaviour,
        private EventDispatcherInterface $dispatcher,
        private LoggerInterface $logger,
    ) {
    }

    public function exists(string $clusterId): bool
    {
        $row = $this->connection->fetchOne(
            'SELECT 1 FROM threat_actor_psych_profile WHERE cluster_id = :cid',
            ['cid' => $clusterId],
        );

        return $row !== false && $row !== null;
    }

    public function generateForCluster(string $clusterId): ?ThreatActorPsychProfile
    {
        $context = $this->loadClusterContext($clusterId);

        if ($context === null) {
            return null; // unknown cluster or no inbound corpus to profile
        }

        try {
            $userPrompt = $this->buildPrompt($context);
            $response = $this->llmClient->chat([
                ['role' => 'system', 'content' => self::SYSTEM_PROMPT],
                ['role' => 'user', 'content' => $userPrompt],
            ], [
                'model'       => self::MODEL,
                'temperature' => 0.3,
                'max_tokens'  => 500,
                'purpose'     => 'threat_actor_psych_profile',
            ]);

            $parsed = $this->parseResponse($response);

            if ($parsed === null) {
                $this->logger->warning('[ThreatActorPsychProfileGenerator] invalid LLM JSON', ['cluster_id' => $clusterId]);

                return null;
            }

            $profile = $this->assembleProfile($clusterId, $context, $parsed);
            $this->persist($profile);

            $this->dispatcher->dispatch(new LlmCallCompletedEvent(
                provider: 'openai',
                model: self::MODEL,
                purpose: 'threat_actor_psych_profile',
                promptTokens: (int) ceil(\strlen($userPrompt) / 4),
                completionTokens: (int) ceil(\strlen($response) / 4),
            ));

            return $profile;
        } catch (\Throwable $e) {
            $this->logger->warning('[ThreatActorPsychProfileGenerator] generation failed', [
                'cluster_id' => $clusterId,
                'error'      => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * @return array{
     *     name: string,
     *     conversation_count: int,
     *     message_count: int,
     *     scam_types: string,
     *     messages: list<string>,
     *     behaviour: array{dominant_stimulus: string|null, avg_urgency_score: float, hesitation_count: int, language_switch_count: int}
     * }|null
     */
    private function loadClusterContext(string $clusterId): ?array
    {
        $row = $this->connection->fetchAssociative(
            "SELECT COALESCE(name, '') AS name, COALESCE(conversation_count, 0) AS conversation_count,
                    COALESCE(array_to_string(primary_scam_types, ', '), '') AS scam_types
             FROM threat_actor_cluster
             WHERE cluster_id = :cid AND merged_into_id IS NULL",
            ['cid' => $clusterId],
        );

        if ($row === false) {
            return null;
        }

        /** @var list<string> $messages */
        $messages = [];

        // Inbound = scammer messages. direction is an FK to lkp_direction whose
        // dir_id is sequence-generated (differs per environment), so filter by the
        // stable `code`, never a hardcoded id.
        $rows = $this->connection->fetchAllAssociative(
            "SELECT m.body_text
             FROM threat_actor_cluster_conversation tacc
             JOIN message m ON m.conv_id = tacc.conv_id
             JOIN lkp_direction d ON d.dir_id = m.direction AND d.code = 'in'
             WHERE tacc.cluster_id = :cid AND m.deleted_at IS NULL
             ORDER BY m.ts_msg ASC
             LIMIT :lim",
            ['cid' => $clusterId, 'lim' => self::MAX_MESSAGES],
            ['lim' => \PDO::PARAM_INT],
        );

        foreach ($rows as $r) {
            $body = \is_string($r['body_text'] ?? null) ? trim($r['body_text']) : '';

            if ($body !== '') {
                $messages[] = mb_substr($body, 0, self::MAX_MESSAGE_CHARS);
            }
        }

        if ($messages === []) {
            return null; // nothing to profile
        }

        $behaviour = $this->clusterBehaviour->getBehavioralProfile($clusterId);

        return [
            'name'               => \is_string($row['name']) ? $row['name'] : '',
            'conversation_count' => \is_numeric($row['conversation_count'] ?? null) ? (int) $row['conversation_count'] : 0,
            'message_count'      => \count($messages),
            'scam_types'         => \is_string($row['scam_types']) ? $row['scam_types'] : '',
            'messages'           => $messages,
            'behaviour'          => [
                'dominant_stimulus'     => \is_string($behaviour['dominant_stimulus'] ?? null) ? $behaviour['dominant_stimulus'] : null,
                'avg_urgency_score'     => \is_numeric($behaviour['avg_urgency_score'] ?? null) ? (float) $behaviour['avg_urgency_score'] : 0.0,
                'hesitation_count'      => \is_numeric($behaviour['hesitation_count'] ?? null) ? (int) $behaviour['hesitation_count'] : 0,
                'language_switch_count' => \is_numeric($behaviour['language_switch_count'] ?? null) ? (int) $behaviour['language_switch_count'] : 0,
            ],
        ];
    }

    /**
     * @param array{name: string, conversation_count: int, message_count: int, scam_types: string, messages: list<string>, behaviour: array{dominant_stimulus: string|null, avg_urgency_score: float, hesitation_count: int, language_switch_count: int}} $context
     */
    private function buildPrompt(array $context): string
    {
        $levers = implode(', ', CialdiniLever::names());
        $patterns = implode(', ', self::ESCALATION_PATTERNS);
        $scamTypes = $context['scam_types'] !== '' ? $context['scam_types'] : 'unknown';
        $stimulus = $context['behaviour']['dominant_stimulus'] ?? 'n/a';
        $urgency = number_format($context['behaviour']['avg_urgency_score'], 2);
        $hesitation = $context['behaviour']['hesitation_count'];
        $langSwitch = $context['behaviour']['language_switch_count'];

        $corpus = '';

        foreach ($context['messages'] as $i => $message) {
            $corpus .= sprintf("--- message %d ---\n%s\n", $i + 1, $message);
        }

        return <<<PROMPT
            Profile the scammer (threat actor) behind the messages below. Base your
            analysis ONLY on the actor's own messages and the behavioural signals.

            Scam type(s): {$scamTypes}
            Behavioural signals (measured): dominant psychological stimulus = {$stimulus},
            average urgency = {$urgency}, hesitation events = {$hesitation}, language switches = {$langSwitch}.

            Actor messages (inbound only):
            {$corpus}

            Produce:
            1. dominant_lever: the single dominant Cialdini influence principle the
               actor uses. EXACTLY one of: {$levers}.
            2. secondary_levers: 0-3 other principles they also use, from the same list.
            3. behavioural_summary: 2-3 sentences on how this actor manipulates —
               observational, concrete, no PII, no real names. Max 400 characters.
            4. escalation_pattern: how their pressure evolves across turns. EXACTLY one
               of: {$patterns}.
            5. victim_targeting: one sentence on who this actor targets. Max 200 characters, no PII.

            Return strictly this JSON shape (no extra keys, no markdown):
            {
              "dominant_lever": "...",
              "secondary_levers": ["..."],
              "behavioural_summary": "...",
              "escalation_pattern": "...",
              "victim_targeting": "..."
            }
            PROMPT;
    }

    /**
     * @return array{dominant_lever: CialdiniLever, secondary_levers: list<CialdiniLever>, behavioural_summary: string, escalation_pattern: string, victim_targeting: string}|null
     */
    private function parseResponse(string $response): ?array
    {
        $r = trim($response);

        if (preg_match('/```(?:json)?\s*(\{.*\})\s*```/s', $r, $m)) {
            $r = $m[1];
        } elseif (preg_match('/(\{.*\})/s', $r, $m)) {
            $r = $m[1];
        }

        try {
            $decoded = json_decode($r, true, 16, \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }

        if (!\is_array($decoded)) {
            return null;
        }

        $dominant = \is_string($decoded['dominant_lever'] ?? null)
            ? CialdiniLever::tryFromLabel($decoded['dominant_lever'])
            : null;

        if (!$dominant instanceof CialdiniLever) {
            return null; // a profile with no recognisable dominant lever is not trustworthy
        }

        $summary = \is_string($decoded['behavioural_summary'] ?? null) ? trim($decoded['behavioural_summary']) : '';
        $targeting = \is_string($decoded['victim_targeting'] ?? null) ? trim($decoded['victim_targeting']) : '';

        if ($summary === '') {
            return null;
        }

        $secondary = [];

        if (\is_array($decoded['secondary_levers'] ?? null)) {
            foreach ($decoded['secondary_levers'] as $label) {
                $lever = \is_string($label) ? CialdiniLever::tryFromLabel($label) : null;

                if ($lever instanceof CialdiniLever && $lever !== $dominant && $lever !== CialdiniLever::None && !\in_array($lever, $secondary, true)) {
                    $secondary[] = $lever;
                }
            }
        }

        $escalation = \is_string($decoded['escalation_pattern'] ?? null) ? strtolower(trim($decoded['escalation_pattern'])) : '';

        if (!\in_array($escalation, self::ESCALATION_PATTERNS, true)) {
            $escalation = 'unknown';
        }

        return [
            'dominant_lever'      => $dominant,
            'secondary_levers'    => \array_slice($secondary, 0, 3),
            'behavioural_summary' => mb_substr($summary, 0, 400),
            'escalation_pattern'  => $escalation,
            'victim_targeting'    => mb_substr($targeting, 0, 200),
        ];
    }

    /**
     * @param array{name: string, conversation_count: int, message_count: int, scam_types: string, messages: list<string>, behaviour: array{dominant_stimulus: string|null, avg_urgency_score: float, hesitation_count: int, language_switch_count: int}} $context
     * @param array{dominant_lever: CialdiniLever, secondary_levers: list<CialdiniLever>, behavioural_summary: string, escalation_pattern: string, victim_targeting: string}                                                                              $parsed
     */
    private function assembleProfile(string $clusterId, array $context, array $parsed): ThreatActorPsychProfile
    {
        return new ThreatActorPsychProfile(
            clusterId: $clusterId,
            dominantLever: $parsed['dominant_lever'],
            secondaryLevers: $parsed['secondary_levers'],
            behaviouralSummary: $parsed['behavioural_summary'],
            escalationPattern: $parsed['escalation_pattern'],
            victimTargeting: $parsed['victim_targeting'],
            dominantStimulus: $context['behaviour']['dominant_stimulus'],
            avgUrgency: $context['behaviour']['avg_urgency_score'],
            hesitationEvents: $context['behaviour']['hesitation_count'],
            languageSwitches: $context['behaviour']['language_switch_count'],
            conversationCount: $context['conversation_count'],
            messageCount: $context['message_count'],
            generatedByModel: self::MODEL,
            promptVersion: self::PROMPT_VERSION,
            generatedAt: new \DateTimeImmutable(),
        );
    }

    private function persist(ThreatActorPsychProfile $profile): void
    {
        // Safe Postgres text[] literal — lever labels are a fixed alnum enum.
        $secondary = '{' . implode(',', array_map(
            static fn (CialdiniLever $l): string => $l->value,
            $profile->secondaryLevers,
        )) . '}';

        $sql = <<<'SQL'
            INSERT INTO threat_actor_psych_profile
                (cluster_id, dominant_lever, secondary_levers, behavioural_summary, escalation_pattern,
                 victim_targeting, dominant_stimulus, avg_urgency, hesitation_events, language_switches,
                 conversation_count, message_count, generated_at, generated_by_model, prompt_version)
            VALUES
                (:cid, :lever, CAST(:secondary AS TEXT[]), :summary, :escalation,
                 :targeting, :stimulus, :urgency, :hesitation, :langsw,
                 :convcount, :msgcount, :ts, :model, :pv)
            ON CONFLICT (cluster_id) DO UPDATE SET
                dominant_lever = EXCLUDED.dominant_lever,
                secondary_levers = EXCLUDED.secondary_levers,
                behavioural_summary = EXCLUDED.behavioural_summary,
                escalation_pattern = EXCLUDED.escalation_pattern,
                victim_targeting = EXCLUDED.victim_targeting,
                dominant_stimulus = EXCLUDED.dominant_stimulus,
                avg_urgency = EXCLUDED.avg_urgency,
                hesitation_events = EXCLUDED.hesitation_events,
                language_switches = EXCLUDED.language_switches,
                conversation_count = EXCLUDED.conversation_count,
                message_count = EXCLUDED.message_count,
                generated_at = EXCLUDED.generated_at,
                generated_by_model = EXCLUDED.generated_by_model,
                prompt_version = EXCLUDED.prompt_version
        SQL;

        $this->connection->executeStatement($sql, [
            'cid'        => $profile->clusterId,
            'lever'      => $profile->dominantLever->value,
            'secondary'  => $secondary,
            'summary'    => $profile->behaviouralSummary,
            'escalation' => $profile->escalationPattern,
            'targeting'  => $profile->victimTargeting,
            'stimulus'   => $profile->dominantStimulus,
            'urgency'    => $profile->avgUrgency,
            'hesitation' => $profile->hesitationEvents,
            'langsw'     => $profile->languageSwitches,
            'convcount'  => $profile->conversationCount,
            'msgcount'   => $profile->messageCount,
            'ts'         => $profile->generatedAt->format('Y-m-d H:i:s'),
            'model'      => $profile->generatedByModel,
            'pv'         => $profile->promptVersion,
        ]);
    }
}
