<?php

declare(strict_types=1);

namespace App\Application\LLM;

use App\Application\LLM\Port\LLMClientInterface;
use App\Application\LLM\Prompt\PromptCatalog;
use App\Application\LLM\Prompt\PromptProvider;
use Psr\Log\LoggerInterface;

/**
 * LLM agent that tags scammer tactics (TTPs) in one inbound message against a
 * closed taxonomy.
 *
 * Same three-layer discipline as the other classifier agents: the prompt
 * restricts output to the allowed codes, the parser is defensive, and every
 * returned code is post-validated against the taxonomy the caller passed in.
 * A first response with invalid JSON or out-of-vocabulary codes triggers
 * exactly ONE retry that feeds the failure reasons back to the model; whatever
 * is still invalid after that is dropped and logged (an LLM transport error is
 * an infrastructure failure, not a format failure — it is never retried).
 *
 * Evidence offsets are computed server-side on the ORIGINAL (untruncated)
 * text as UTF-8 CHARACTER offsets (not bytes): the model's quote is only
 * trusted when it is found verbatim, otherwise offsets are null.
 *
 * Fail-safe: never throws to the caller; returns [] on any failure.
 */
final readonly class TtpExtractor
{
    /**
     * Maximum message length (bytes) sent to the LLM.
     */
    private const MAX_TEXT_LENGTH = 4000;

    /**
     * Maximum evidence length (characters) kept per observation.
     */
    private const MAX_EVIDENCE_LENGTH = 300;

    /**
     * Output-token ceiling for the extraction call. A multi-label message can tag
     * many tactics, each carrying a verbatim evidence quote (up to
     * MAX_EVIDENCE_LENGTH characters), making this the largest response of any LLM
     * agent here. 4000 clears a near-full-vocabulary tag set with headroom while
     * staying under the smallest provider output cap; the previous 2000 truncated
     * some multi-label extractions, silently losing tactics.
     */
    private const MAX_TOKENS = 4000;

    public function __construct(
        private LLMClientInterface $llmClient,
        private LoggerInterface $logger,
        private PromptProvider $promptProvider,
    ) {
    }

    /**
     * Extract TTP observations from one inbound message text.
     *
     * @param list<array{code: string, definition: string}> $taxonomy allowed TTPs (code + definition)
     *
     * @return list<array{ttp_code: string, confidence: float, evidence: string, evidence_start: ?int, evidence_end: ?int}>
     */
    public function extract(string $text, array $taxonomy): array
    {
        // Guarantee valid UTF-8 before the text reaches json_encode (the request
        // payload) or the offset math. An undeclared JIS/Shift-JIS body the mail
        // parser could not decode leaves malformed bytes that would otherwise fail
        // the whole LLM request or corrupt code-point offsets. UTF-8 -> UTF-8 drops
        // or substitutes only the invalid sequences; it is a no-op on valid text,
        // so it never shifts offsets for the normal (already-valid) corpus. For a
        // rare invalid-UTF-8 body the offsets below are relative to this scrubbed
        // text; the stored verbatim evidence string stays the source of truth for
        // the highlight (see TtpHandler), so a slight offset drift degrades
        // gracefully rather than corrupting anything.
        $text = mb_convert_encoding($text, 'UTF-8', 'UTF-8');

        if (trim($text) === '') {
            $this->logger->warning('[TtpExtractor] Cannot extract TTPs from empty text');

            return [];
        }

        if ($taxonomy === []) {
            $this->logger->warning('[TtpExtractor] Cannot extract TTPs without a taxonomy');

            return [];
        }

        $allowedCodes = array_column($taxonomy, 'code');

        $messages = [
            ['role' => 'system', 'content' => 'You are a cybersecurity analyst. Respond with a raw JSON array only, no markdown.'],
            ['role' => 'user', 'content' => $this->buildPrompt($text, $taxonomy)],
        ];

        $response = $this->callLlm($messages);

        if ($response === null) {
            return [];
        }

        $parsed = $this->parseResponse($response, $allowedCodes);

        if ($parsed['errors'] !== []) {
            // One retry with targeted feedback: name the failure so the model can correct
            // its format/vocabulary. Only format failures reach this path.
            $this->logger->info('[TtpExtractor] Retrying with feedback', ['reasons' => $parsed['errors']]);
            $messages[1]['content'] .= sprintf(
                "\n\nYour previous answer was invalid: %s. Return ONLY a raw JSON array of {\"ttp_id\",\"confidence\",\"evidence\"} using ONLY the allowed codes.",
                implode('; ', $parsed['errors']),
            );

            $response = $this->callLlm($messages);

            if ($response === null) {
                return [];
            }

            $parsed = $this->parseResponse($response, $allowedCodes);

            foreach ($parsed['errors'] as $reason) {
                $this->logger->warning('[TtpExtractor] Dropping invalid item after retry', ['reason' => $reason]);
            }
        }

        return $this->normalize($parsed['items'], $text);
    }

    /**
     * Build the extraction prompt: enumerate the allowed vocabulary as
     * "CODE — definition" lines and inline the (length-capped) message text.
     *
     * @param list<array{code: string, definition: string}> $taxonomy
     */
    private function buildPrompt(string $text, array $taxonomy): string
    {
        // Limit text length for LLM (max ~4000 bytes to stay within token limits).
        // mb_strcut never splits a multibyte character, so the payload stays valid
        // UTF-8 (a raw substr could cut mid-character and fail the whole request).
        // Offsets are computed later against the ORIGINAL text, never this copy.
        if (strlen($text) > self::MAX_TEXT_LENGTH) {
            $originalLength = strlen($text);
            $text = mb_strcut($text, 0, self::MAX_TEXT_LENGTH, 'UTF-8') . '... [truncated]';
            $this->logger->info('[TtpExtractor] Truncated text for LLM TTP extraction', ['original_length' => $originalLength]);
        }

        $ttpLines = [];

        foreach ($taxonomy as $row) {
            $ttpLines[] = $row['code'] . ' — ' . $row['definition'];
        }

        $replacements = [
            '{{TTP_LIST}}' => implode("\n", $ttpLines),
            '{{MESSAGE}}' => $text,
        ];

        // Operator override resolves under config/scambuster/prompts/ttp_extraction.txt;
        // absent or invalid → the shipped inline default. Required placeholders guard
        // against an override that drops the vocabulary or the message itself.
        return $this->promptProvider->resolve(
            'ttp_extraction',
            $replacements,
            PromptCatalog::defaultBody('ttp_extraction'),
            array_keys($replacements),
        );
    }

    /**
     * Call the LLM. A transport error is an infrastructure failure, not a format
     * failure: it is logged and surfaces as null (no feedback retry).
     *
     * @param array<int, array{role: string, content: string}> $messages
     */
    private function callLlm(array $messages): ?string
    {
        try {
            return $this->llmClient->chat($messages, [
                'temperature' => 0.1,
                'max_tokens' => self::MAX_TOKENS,
                'purpose' => 'ttp_extraction',
            ]);
        } catch (\Throwable $e) {
            $this->logger->error('[TtpExtractor] LLM call failed', ['error' => $e->getMessage()]);

            return null;
        }
    }

    /**
     * Defensive parse + post-validation against the allowed vocabulary. Valid items
     * and failure reasons are returned side by side so the caller can decide whether
     * a feedback retry is warranted.
     *
     * @param list<string> $allowedCodes
     *
     * @return array{items: list<array{ttp_id: string, confidence: float, evidence: string}>, errors: list<string>}
     */
    private function parseResponse(string $response, array $allowedCodes): array
    {
        try {
            $data = json_decode($this->extractJson($response), true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            $this->logger->warning('[TtpExtractor] LLM response is not valid JSON', [
                'error' => $e->getMessage(),
                'response_preview' => substr($response, 0, 200),
            ]);

            return ['items' => [], 'errors' => ['invalid JSON (' . $e->getMessage() . ')']];
        }

        if (!\is_array($data) || !array_is_list($data)) {
            return ['items' => [], 'errors' => ['response is not a JSON array']];
        }

        $items = [];
        $errors = [];

        foreach ($data as $index => $item) {
            if (!\is_array($item)
                || !isset($item['ttp_id'], $item['confidence'], $item['evidence'])
                || !\is_string($item['ttp_id'])
                || !is_numeric($item['confidence'])
                || !\is_string($item['evidence'])
                || trim($item['evidence']) === ''
            ) {
                $errors[] = sprintf('malformed item at index %d (expected {"ttp_id","confidence","evidence"})', $index);

                continue;
            }

            if (!\in_array($item['ttp_id'], $allowedCodes, true)) {
                $errors[] = sprintf('unknown ttp_id "%s" (not in the allowed list)', $item['ttp_id']);

                continue;
            }

            $items[] = [
                'ttp_id' => $item['ttp_id'],
                'confidence' => (float) $item['confidence'],
                'evidence' => $item['evidence'],
            ];
        }

        return ['items' => $items, 'errors' => $errors];
    }

    /**
     * Clamp confidence, cap evidence, dedup per code (higher confidence wins), then
     * compute evidence offsets server-side on the original text. Offsets are UTF-8
     * CHARACTER offsets ([start, end) with end exclusive), null when the quote is
     * not found verbatim.
     *
     * @param list<array{ttp_id: string, confidence: float, evidence: string}> $items
     *
     * @return list<array{ttp_code: string, confidence: float, evidence: string, evidence_start: ?int, evidence_end: ?int}>
     */
    private function normalize(array $items, string $originalText): array
    {
        /** @var array<string, array{ttp_code: string, confidence: float, evidence: string}> $byCode */
        $byCode = [];

        foreach ($items as $item) {
            $candidate = [
                'ttp_code' => $item['ttp_id'],
                'confidence' => max(0.0, min(1.0, $item['confidence'])),
                'evidence' => mb_substr(trim($item['evidence']), 0, self::MAX_EVIDENCE_LENGTH),
            ];

            $kept = $byCode[$candidate['ttp_code']] ?? null;

            if ($kept === null || $candidate['confidence'] > $kept['confidence']) {
                $byCode[$candidate['ttp_code']] = $candidate;
            }
        }

        $observations = [];

        foreach ($byCode as $item) {
            $start = null;
            $end = null;
            $pos = mb_strpos($originalText, $item['evidence']);

            if ($pos !== false) {
                $start = $pos;
                $end = $pos + mb_strlen($item['evidence']);
            } else {
                $this->logger->warning('[TtpExtractor] evidence not found verbatim in the message, offsets set to null', [
                    'ttp_code' => $item['ttp_code'],
                ]);
            }

            $observations[] = [
                'ttp_code' => $item['ttp_code'],
                'confidence' => $item['confidence'],
                'evidence' => $item['evidence'],
                'evidence_start' => $start,
                'evidence_end' => $end,
            ];
        }

        return $observations;
    }

    /**
     * Extract the JSON array from the LLM response (handles markdown code blocks).
     */
    private function extractJson(string $response): string
    {
        $response = trim($response);

        if (preg_match('/```(?:json)?\s*(\[.*\])\s*```/s', $response, $matches)) {
            return $matches[1];
        }

        if (preg_match('/(\[.*\])/s', $response, $matches)) {
            return $matches[1];
        }

        return $response;
    }
}
