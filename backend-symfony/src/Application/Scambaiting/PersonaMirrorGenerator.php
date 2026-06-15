<?php

declare(strict_types=1);

namespace App\Application\Scambaiting;

use App\Application\LLM\Port\LLMClientInterface;
use App\Domain\LLM\Event\LlmCallCompletedEvent;
use Doctrine\DBAL\Connection;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

/**
 * Spec 104 P3 — Cognitive Mirror generator.
 *
 * For each (persona, scam type) pair, the LLM produces three short
 * editorial framings:
 *   - hunted_victim_profile: who this scam type preys on
 *   - cognitive_lever: the emotional manipulation mechanism
 *   - mirror_explanation: why this persona matches the target
 *
 * Read by the frontend Cognitive Mirror panel to explain — in plain
 * language — why a specific persona dominates a specific scam type.
 * Editorial, not measured: the production caveat in the UI footer
 * names the model + date so the viewer knows it's inferred from the
 * persona definition, not from a behavioural measurement.
 *
 * Fail-safe by design. Any LLM failure returns null. The caller (the
 * CLI batch) decides whether to retry; the row simply isn't written
 * and the panel shows "generation pending" for that cell. No
 * exception ever propagates out of generateForPair().
 *
 * Pricing note: ~$0.001 per call on gpt-4o-mini, so the 351-pair
 * full corpus costs ~$0.35.
 */
final readonly class PersonaMirrorGenerator
{
    private const MODEL = 'gpt-4o-mini';
    private const PROMPT_VERSION = 'v1';
    private const SYSTEM_PROMPT = 'You are a cybersecurity analyst writing victim-profile framing for a scambaiting honeypot platform. Respond with strict JSON only — no markdown, no preamble, no extra prose outside the JSON.';

    public function __construct(
        private LLMClientInterface $llmClient,
        private Connection $connection,
        private EventDispatcherInterface $dispatcher,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * Generate the mirror for one pair and persist it. Idempotent
     * when called without --force (caller checks existence).
     *
     * @return array{success: bool, cost_estimate_usd: float, error?: string}
     */
    public function generateForPair(int $personaId, int $scamTypeId): array
    {
        $context = $this->loadContext($personaId, $scamTypeId);

        if ($context === null) {
            return ['success' => false, 'cost_estimate_usd' => 0.0, 'error' => 'persona or scam type not found'];
        }

        try {
            $userPrompt = $this->buildPrompt($context);
            $response = $this->llmClient->chat([
                ['role' => 'system', 'content' => self::SYSTEM_PROMPT],
                ['role' => 'user', 'content' => $userPrompt],
            ], [
                'model' => self::MODEL,
                'temperature' => 0.3,
                'max_tokens' => 350,
                'purpose' => 'persona_mirror_generation',
            ]);

            $parsed = $this->parseResponse($response);

            if ($parsed === null) {
                return ['success' => false, 'cost_estimate_usd' => 0.0, 'error' => 'invalid LLM JSON response'];
            }

            $this->persist($personaId, $scamTypeId, $parsed);

            // Rough cost (prompt ~600 tokens + response ~150 tokens at
            // gpt-4o-mini pricing). Used for the CLI budget guard.
            $costEstimate = 0.001;

            $this->dispatcher->dispatch(new LlmCallCompletedEvent(
                provider: 'openai',
                model: self::MODEL,
                purpose: 'persona_mirror_generation',
                promptTokens: (int) ceil(\strlen($userPrompt) / 4),
                completionTokens: (int) ceil(\strlen($response) / 4),
            ));

            return ['success' => true, 'cost_estimate_usd' => $costEstimate];
        } catch (\Throwable $e) {
            $this->logger->warning('[PersonaMirrorGenerator] LLM call failed', [
                'persona_id' => $personaId,
                'scam_type_id' => $scamTypeId,
                'error' => $e->getMessage(),
            ]);

            return ['success' => false, 'cost_estimate_usd' => 0.0, 'error' => $e->getMessage()];
        }
    }

    public function exists(int $personaId, int $scamTypeId): bool
    {
        $row = $this->connection->fetchOne(
            'SELECT 1 FROM persona_scam_mirror WHERE persona_id = :pid AND scam_type_id = :sid',
            ['pid' => $personaId, 'sid' => $scamTypeId]
        );

        return $row !== false && $row !== null;
    }

    /**
     * @return array{persona_id: int, persona_code: string, persona_label: string, system_prompt: string, scam_type_id: int, scam_type_code: string, scam_type_label: string, scam_type_description: string, attck_technique: string}|null
     */
    private function loadContext(int $personaId, int $scamTypeId): ?array
    {
        $row = $this->connection->fetchAssociative(
            'SELECT p.persona_id, p.persona_code, COALESCE(p.persona_label, p.persona_code) AS persona_label,'
            . ' COALESCE(p.system_prompt, \'\') AS system_prompt,'
            . ' st.scam_type_id, st.code AS scam_type_code,'
            . ' COALESCE(st.label, st.code) AS scam_type_label,'
            . ' COALESCE(st.description, \'\') AS scam_type_description,'
            . ' COALESCE(st.attck_technique, \'\') AS attck_technique'
            . ' FROM persona p, lkp_scam_type st'
            . ' WHERE p.persona_id = :pid AND st.scam_type_id = :sid',
            ['pid' => $personaId, 'sid' => $scamTypeId]
        );

        if ($row === false) {
            return null;
        }

        return [
            'persona_id' => is_numeric($row['persona_id'] ?? null) ? (int) $row['persona_id'] : 0,
            'persona_code' => \is_string($row['persona_code'] ?? null) ? $row['persona_code'] : '',
            'persona_label' => \is_string($row['persona_label'] ?? null) ? $row['persona_label'] : '',
            'system_prompt' => \is_string($row['system_prompt'] ?? null) ? $row['system_prompt'] : '',
            'scam_type_id' => is_numeric($row['scam_type_id'] ?? null) ? (int) $row['scam_type_id'] : 0,
            'scam_type_code' => \is_string($row['scam_type_code'] ?? null) ? $row['scam_type_code'] : '',
            'scam_type_label' => \is_string($row['scam_type_label'] ?? null) ? $row['scam_type_label'] : '',
            'scam_type_description' => \is_string($row['scam_type_description'] ?? null) ? $row['scam_type_description'] : '',
            'attck_technique' => \is_string($row['attck_technique'] ?? null) ? $row['attck_technique'] : '',
        ];
    }

    /**
     * @param array{persona_id: int, persona_code: string, persona_label: string, system_prompt: string, scam_type_id: int, scam_type_code: string, scam_type_label: string, scam_type_description: string, attck_technique: string} $context
     */
    private function buildPrompt(array $context): string
    {
        // Local typed bindings — PHPStan can't propagate the @param
        // shape into the heredoc interpolation reliably at level 8.
        $scamTypeCode = $context['scam_type_code'];
        $scamTypeLabel = $context['scam_type_label'];
        $scamTypeDescription = $context['scam_type_description'];
        $attckTechnique = $context['attck_technique'];
        $personaCode = $context['persona_code'];
        $personaLabel = $context['persona_label'];

        // Sanitize system_prompt: strip any {{placeholders}} so they
        // don't end up rephrased by the LLM as if they were real names.
        $sanitized = preg_replace('/\{\{[^}]+\}\}/', '[name]', $context['system_prompt']);
        $personaPromptExcerpt = substr(\is_string($sanitized) ? $sanitized : $context['system_prompt'], 0, 500);

        return <<<PROMPT
            Given a scam type and a persona that ScamBuster deploys against it,
            describe three things:

            1. hunted_victim_profile: who does this scam type prey on?
               One sentence, concrete (age / demographic / emotional state).
               Max 200 characters. NO PII, no real names.

            2. cognitive_lever: the primary emotional manipulation mechanism.
               One of: greed, fear, urgency, trust, loneliness, authority,
               reciprocity — or a short combination like "greed + flattery".
               Max 60 characters.

            3. mirror_explanation: why this persona is a good match for that
               target. 1-2 sentences. Framed observationally ("this persona
               reproduces ...", "the persona's profile mirrors ...").
               Max 300 characters. NO PII.

            Scam type code: {$scamTypeCode}
            Scam type label: {$scamTypeLabel}
            Scam description: {$scamTypeDescription}
            MITRE ATT&CK: {$attckTechnique}

            Persona code: {$personaCode}
            Persona label: {$personaLabel}
            Persona system prompt excerpt: {$personaPromptExcerpt}

            Return strictly this JSON shape (no extra keys, no markdown):
            {
              "hunted_victim_profile": "...",
              "cognitive_lever": "...",
              "mirror_explanation": "..."
            }
            PROMPT;
    }

    /**
     * @return array{hunted_victim_profile: string, cognitive_lever: string, mirror_explanation: string}|null
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

        $hunted = isset($decoded['hunted_victim_profile']) && \is_string($decoded['hunted_victim_profile']) ? $decoded['hunted_victim_profile'] : null;
        $lever = isset($decoded['cognitive_lever']) && \is_string($decoded['cognitive_lever']) ? $decoded['cognitive_lever'] : null;
        $mirror = isset($decoded['mirror_explanation']) && \is_string($decoded['mirror_explanation']) ? $decoded['mirror_explanation'] : null;

        if ($hunted === null || $lever === null || $mirror === null) {
            return null;
        }

        return [
            'hunted_victim_profile' => substr($hunted, 0, 400),
            'cognitive_lever' => substr($lever, 0, 120),
            'mirror_explanation' => substr($mirror, 0, 500),
        ];
    }

    /**
     * @param array{hunted_victim_profile: string, cognitive_lever: string, mirror_explanation: string} $payload
     */
    private function persist(int $personaId, int $scamTypeId, array $payload): void
    {
        $now = (new \DateTimeImmutable())->format('Y-m-d H:i:s');

        $sql = <<<'SQL'
            INSERT INTO persona_scam_mirror
                (persona_id, scam_type_id, hunted_victim_profile, cognitive_lever, mirror_explanation,
                 generated_at, generated_by_model, prompt_version)
            VALUES
                (:pid, :sid, :hunted, :lever, :mirror, :ts, :model, :pv)
            ON CONFLICT (persona_id, scam_type_id) DO UPDATE SET
                hunted_victim_profile = EXCLUDED.hunted_victim_profile,
                cognitive_lever = EXCLUDED.cognitive_lever,
                mirror_explanation = EXCLUDED.mirror_explanation,
                generated_at = EXCLUDED.generated_at,
                generated_by_model = EXCLUDED.generated_by_model,
                prompt_version = EXCLUDED.prompt_version
        SQL;

        $this->connection->executeStatement($sql, [
            'pid' => $personaId,
            'sid' => $scamTypeId,
            'hunted' => $payload['hunted_victim_profile'],
            'lever' => $payload['cognitive_lever'],
            'mirror' => $payload['mirror_explanation'],
            'ts' => $now,
            'model' => self::MODEL,
            'pv' => self::PROMPT_VERSION,
        ]);
    }
}
