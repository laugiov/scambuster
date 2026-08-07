<?php

declare(strict_types=1);

namespace App\Application\LLM;

use Psr\Log\LoggerInterface;

/**
 * PolicyGuard enforces hard rules on generated text.
 *
 * Validates text against policies (length limits, forbidden patterns, PII,
 * out-of-band channels) deterministically, without LLM inference. It is
 * "deterministic and certifiable for the string classes it enumerates" — NOT a
 * proof that no harmful output can ever pass (a novel paraphrase carries no
 * enumerated substring). Scope claims accordingly.
 *
 * Two pattern groups with different trust levels:
 *
 *   HARM set — real-world-harm invariants. Compile-time `private const`,
 *   ALWAYS applied, and reducible by NO constructor input:
 *     - {@see self::THREAT_PATTERNS}
 *     - {@see self::AUTHORITY_PATTERNS}
 *     - {@see self::PII_PATTERNS}
 *     - {@see self::OUT_OF_BAND_CHANNEL_PATTERNS}
 *
 *   OPSEC set — automation/infrastructure reveal. Also always applied, but an
 *   operator may only UNION extra patterns in via `$additionalOpsecPatterns`;
 *   there is no code path that removes or weakens a HARM pattern:
 *     - {@see self::FORBIDDEN_PATTERNS}
 *     - {@see self::OPERATIONAL_LEAKAGE_PATTERNS}
 */
final readonly class PolicyGuard
{
    // ===================== OPSEC set (union-extensible) =====================

    /**
     * Forbidden patterns — ONLY words that reveal the honeypot/automation.
     *
     * Responsibility: PolicyGuard owns ALL pattern-based checks.
     * The LLM validator does NOT re-check these — it focuses on semantic quality.
     *
     * Common victim words like "test", "suspect", "strange" are intentionally ALLOWED.
     *
     * @var array<string>
     */
    private const FORBIDDEN_PATTERNS = [
        '/\bhoneypot\b/i',
        '/\bscambuster\b/i',
        '/\bI am (?:a |an )?(?:bot|automated|AI)\b/i',
        '/\bautomated system\b/i',
        '/\bartificial intelligence\b/i',
        '/\bleurre\b/i',
    ];

    /**
     * Operational identifiers that must NEVER appear in generated replies.
     * Additive to FORBIDDEN_PATTERNS — does not replace it.
     *
     * Each entry maps a regex to the canonical flag suffix used in the
     * `operational_leak:<suffix>` flag string. The suffix is the
     * normalized lowercase identifier so the orchestrator can do
     * exact-match comparisons.
     *
     * @var array<int, array{pattern: string, suffix: string}>
     */
    private const OPERATIONAL_LEAKAGE_PATTERNS = [
        ['pattern' => '/\bn8n\b/i',                                'suffix' => 'n8n'],
        ['pattern' => '/\bworkflow[_\s-]?(?:id|name)?\b/i',        'suffix' => 'workflow'],
        ['pattern' => '#\bingest/raw\b#i',                         'suffix' => 'ingest/raw'],
        ['pattern' => '#api/v1/(?:admin|internal)\b#i',            'suffix' => 'api/v1/admin'],
        ['pattern' => '/\bSCAMBUSTER_[A-Z][A-Z0-9_]*\b/',          'suffix' => 'scambuster_env'],
        ['pattern' => '/\bbackend-(?:dev|test|preprod|e2e|prod)\b/i', 'suffix' => 'backend-service'],
        ['pattern' => '/\bMailAccount(?:SecretResolver)?\b/',      'suffix' => 'mailaccount'],
        ['pattern' => '/\bIocUpsertService\b/',                    'suffix' => 'iocupsertservice'],
        ['pattern' => '/\bsodium_crypto_secretbox\b/i',            'suffix' => 'sodium_crypto_secretbox'],
        ['pattern' => '/\bdocker[\s-]compose\b/i',                 'suffix' => 'docker-compose'],
    ];

    // ===================== HARM set (never reducible) =====================

    /** @var array<string> Threat and intimidation patterns */
    private const THREAT_PATTERNS = [
        '/\bje vais vous\s+(?:tuer|frapper|détruire|blesser|éliminer)\b/i',
        '/\bi will\s+(?:kill|hurt|destroy|harm)\b/i',
        '/\bje vais te\s+(?:tuer|frapper|détruire|blesser)\b/i',
        '/\bvous allez (?:le |en )?(?:payer|regretter|souffrir)\b/i',
        '/\bvous êtes mort\b/i',
        '/\bje sais où vous (?:habitez|vivez)\b/i',
        '/\bgare à (?:toi|vous)\b/i',
    ];

    /** @var array<string> Authority impersonation patterns */
    private const AUTHORITY_PATTERNS = [
        '/\bje suis\s+(?:policier|gendarme|commissaire|agent de police|officier|inspecteur|détective)\b/i',
        '/\bje suis\s+(?:procureur|juge|magistrat|avocat général)\b/i',
        '/\bje travaille (?:pour|à)\s+(?:la police|la gendarmerie|interpol|europol)\b/i',
        '/\bje travaille (?:pour|à)\s+(?:la banque de france|l\'autorité des marchés)\b/i',
        '/\bi am\s+(?:a |an )?(?:police officer|detective|federal agent|fbi agent|cia agent)\b/i',
        '/\bi work for\s+(?:the police|law enforcement|interpol|europol|the fbi)\b/i',
        '/\bau nom de la loi\b/i',
        '/\bmandat d\'arrêt\b/i',

        // Commercial-bank impersonation (the bait must never claim to BE the bank).
        '/\bje suis\s+(?:votre\s+)?(?:conseill(?:er|ère)\s+bancaire|de\s+(?:la|votre)\s+banque)\b/iu',
        '/\bi(?:\'m| am)\s+(?:calling\s+)?(?:from|with)\s+(?:your\s+)?bank\b/iu',
        '/\bi(?:\'m| am)\s+(?:your\s+)?bank\s+(?:representative|advisor|rep)\b/iu',
        '/\bsoy\s+del?\s+banco\b/iu',
        '/\bich\s+bin\s+von\s+(?:der|ihrer)\s+bank\b/iu',
        '/\bsono\s+(?:della|di)\s+banca\b/iu',

        // Tax-authority impersonation (fisc / IRS / HMRC / Hacienda / Finanzamt).
        '/\bje suis\s+(?:un\s+)?(?:agent\s+)?(?:des\s+imp[oô]ts|du\s+fisc|de\s+la\s+dgfip)\b/iu',
        '/\bi(?:\'m| am)\s+(?:from|with)\s+(?:the\s+)?(?:irs|hmrc|tax\s+(?:office|authority|department))\b/iu',
        '/\bsoy\s+de\s+(?:hacienda|la\s+agencia\s+tributaria)\b/iu',
        '/\bich\s+bin\s+vom\s+finanzamt\b/iu',

        // Police / law-enforcement impersonation in more languages (ES/DE/IT).
        '/\bsoy\s+(?:de\s+la\s+)?polic[ií]a\b/iu',
        '/\bich\s+bin\s+(?:von\s+der\s+polizei|polizist|kommissar)\b/iu',
        '/\bsono\s+(?:un\s+)?(?:poliziotto|della\s+polizia)\b/iu',
    ];

    /** @var array<string> PII patterns to detect and reject.
     *
     * Limited to IBAN + postal address: both could be real, both are
     * sensitive when emitted by the bait. (Phone, messaging handles,
     * crypto wallets — historically grouped here — moved to the
     * dedicated `OUT_OF_BAND_CHANNEL_PATTERNS` set below.)
     */
    private const PII_PATTERNS = [
        '/\b[A-Z]{2}\d{2}[A-Z0-9]{11,30}\b/', // IBAN (real bank account)
        '/\b\d{1,3}\s+(?:rue|avenue|boulevard|impasse)\s+[A-Z]/i', // Full address with street name
    ];

    /** @var array<string> Out-of-band-channel patterns.
     *
     * The bait MUST stay within the email thread it owns. Emitting any
     * non-email contact channel:
     *   - Tells the scammer they're talking to automation when the
     *     channel value is a trivial fake (e.g. "0612345678").
     *   - Pulls the conversation outside our IMAP honeypot — we lose
     *     attribution, observability, and the recording.
     *   - Risks LLM-hallucinated handles that happen to belong to a
     *     real third party (real-world harm).
     *   - Adds zero defensive value: the scammer asking for our
     *     contact details is HIS social-engineering; refusing is the
     *     correct behaviour.
     *
     * Patterns:
     *   - Phone-shaped sequences (E.164 or freeform with separators)
     *   - Telegram-style `@username` handles
     *   - Skype `live:` / `skype:` URIs
     *   - Signal.me / Discord invite links
     *   - t.me / telegram.me / wa.me messenger links
     *   - Email address used as an off-thread redirect
     *   - Crypto wallets: BTC (bc1/1/3), ETH (0x…40hex), XMR (4/8…95)
     */
    private const OUT_OF_BAND_CHANNEL_PATTERNS = [
        // Crypto wallets — three most common chains in scam traffic.
        // Listed FIRST so the broad `phone` catch-all below doesn't
        // shadow them (e.g. an ETH address contains the substring
        // "0123456789" which the phone regex would otherwise grab).
        // BTC supports both base58 legacy (1.../3...) and bech32
        // (bc1q...); the two alphabets differ — bech32 includes `0`
        // and excludes `1bio`.
        'crypto_btc' => '/\b(?:bc1[02-9ac-hj-np-z]{7,87}|[13][1-9A-HJ-NP-Za-km-z]{25,34})\b/',
        'crypto_eth' => '/\b0x[a-fA-F0-9]{40}\b/',
        'crypto_xmr' => '/\b[48][0-9AB][1-9A-HJ-NP-Za-km-z]{93}\b/',

        // Telegram-style handle. Leading boundary is a non-word char
        // OR start-of-string so we don't match in-word `@`. Handle
        // must start with a letter, 5–32 chars total.
        'telegram_handle' => '/(?:^|[^A-Za-z0-9_])@[A-Za-z][A-Za-z0-9_]{4,31}\b/',

        // Skype contact URIs.
        'skype_uri' => '/\b(?:skype|live):\S+/i',

        // Signal personal link or Discord invite.
        'signal_discord' => '/\b(?:signal\.me\/\S+|discord(?:\.gg|app\.com)?\/[A-Za-z0-9]+)/i',

        // Phone: catch-all, last. 7+ digits with optional country
        // prefix and separators, ending on a digit so we don't match
        // trailing punctuation. The `[\d\s().-]{6,}` middle absorbs
        // spaces, dots, dashes, parens.
        'phone' => '/(?:\+?\d[\d\s().-]{6,})\d/',

        // Messenger-link + redirect-email pivots — concrete off-thread channels the persona
        // must never hand out. Kept byte-identical to SafetyInvariantOracle's channel extensions
        // (the drift test asserts the oracle stays a superset of this set). A bare app NAME is
        // intentionally NOT matched: naming a platform to ask the scammer for THEIR handle is
        // desired IOC elicitation, not a leak. (A numeric wa.me/<number> link is rejected either
        // way but reported under the earlier `phone` catch-all, not `messenger_link` — the reason
        // suffix is best-effort; the rejection is what matters.)
        'messenger_link' => '/\b(?:t(?:elegram)?|wa)\.me\/\S+/i',
        'redirect_email' => '/\b[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[A-Za-z]{2,}\b/',
    ];

    /**
     * @param list<mixed> $additionalOpsecPatterns Extra OPSEC regexes an operator
     *                                             may UNION in (e.g. instance-specific
     *                                             infra terms). Additive only — it can
     *                                             never remove or weaken a HARM pattern.
     *                                             Treated as untrusted input (a future
     *                                             config source): non-string / empty /
     *                                             invalid entries are ignored, not fatal.
     */
    public function __construct(
        private LoggerInterface $logger,
        private int $maxLinks = 1,
        private array $additionalOpsecPatterns = [],
    ) {
    }

    /**
     * Validate text against all hard rules.
     *
     * @param string                 $text   Text to validate
     * @param PolicyGuardConfig|null $config Context-aware thresholds (null = default 20-150)
     *
     * @return array{approved: bool, flags: array<string>}
     */
    public function validate(string $text, ?PolicyGuardConfig $config = null): array
    {
        $config ??= PolicyGuardConfig::default();

        $this->logger->debug('[PolicyGuard] Starting syntactic validation', [
            'text_length' => strlen($text),
            'text_preview' => substr($text, 0, 100) . '...',
        ]);

        $flags = [];

        // Check word count against context-aware thresholds.
        // Whitespace tokenization via WordCounter — the same rule the
        // generator is instructed with ("Target length: N-M words"), so a
        // reply produced at the floor cannot be rejected as below it.
        $wordCount = WordCounter::count($text);

        $this->logger->debug('[PolicyGuard] Checking word count', [
            'word_count' => $wordCount,
            'min_allowed' => $config->minWords,
            'max_allowed' => $config->maxWords,
        ]);

        if ($wordCount < $config->minWords) {
            $flags[] = "too_short:{$wordCount}_words_min_{$config->minWords}";
            $this->logger->warning('[PolicyGuard] ❌ Text too short', [
                'word_count' => $wordCount,
                'min_allowed' => $config->minWords,
            ]);
        }

        if ($wordCount > $config->maxWords) {
            $flags[] = "too_long:{$wordCount}_words";
            $this->logger->warning('[PolicyGuard] ❌ Text too long', [
                'word_count' => $wordCount,
                'max_allowed' => $config->maxWords,
            ]);
        }

        // Check links count
        preg_match_all('#https?://[^\s<>"{}|\\^`\[\]]+#i', $text, $links);
        $linkCount = count($links[0]);

        $this->logger->debug('[PolicyGuard] Checking link count', [
            'link_count' => $linkCount,
            'max_allowed' => $this->maxLinks,
            'links_found' => $links[0],
        ]);

        if ($linkCount > $this->maxLinks) {
            $flags[] = "excessive_links:{$linkCount}_found";
            $this->logger->warning('[PolicyGuard] ❌ Too many links', [
                'link_count' => $linkCount,
                'max_allowed' => $this->maxLinks,
            ]);
        }

        // Check forbidden patterns
        $this->logger->debug('[PolicyGuard] Checking forbidden patterns', [
            'patterns_count' => count(self::FORBIDDEN_PATTERNS),
        ]);

        foreach (self::FORBIDDEN_PATTERNS as $pattern) {
            if (preg_match($pattern, $text, $matches)) {
                $flags[] = 'forbidden_pattern:' . strtolower($matches[0]);
                $this->logger->warning('[PolicyGuard] ❌ Forbidden pattern detected', [
                    'pattern' => $pattern,
                    'matched' => $matches[0],
                ]);
            }
        }

        // Check operational leakage patterns (additive to
        // FORBIDDEN_PATTERNS). Reports `operational_leak:<suffix>` so
        // the orchestrator distinguishes from the existing forbidden
        // pattern flags.
        foreach (self::OPERATIONAL_LEAKAGE_PATTERNS as $entry) {
            if (preg_match($entry['pattern'], $text, $matches)) {
                $flags[] = 'operational_leak:' . $entry['suffix'];
                $this->logger->warning('[PolicyGuard] ❌ Operational leakage detected', [
                    'pattern' => $entry['pattern'],
                    'suffix' => $entry['suffix'],
                    'matched' => $matches[0],
                ]);
            }
        }

        // Operator-supplied OPSEC patterns (union-only). Additive to the two
        // const OPSEC sets above; can never disable a HARM pattern below.
        foreach ($this->additionalOpsecPatterns as $i => $pattern) {
            if (!is_string($pattern) || $pattern === '') {
                continue;
            }

            if (@preg_match($pattern, $text, $matches) === 1) {
                $flags[] = 'opsec_extra:' . $i;
                $this->logger->warning('[PolicyGuard] ❌ Operator OPSEC pattern detected', [
                    'index' => $i,
                    'matched' => $matches[0],
                ]);
            }
        }

        // Check threat/intimidation patterns
        foreach (self::THREAT_PATTERNS as $pattern) {
            if (preg_match($pattern, $text, $matches)) {
                $flags[] = 'threat_detected:' . strtolower($matches[0]);
                $this->logger->warning('[PolicyGuard] Threat pattern detected', [
                    'pattern' => $pattern,
                    'matched' => $matches[0],
                ]);
            }
        }

        // Check authority impersonation patterns
        foreach (self::AUTHORITY_PATTERNS as $pattern) {
            if (preg_match($pattern, $text, $matches)) {
                $flags[] = 'authority_impersonation:' . strtolower($matches[0]);
                $this->logger->warning('[PolicyGuard] Authority impersonation detected', [
                    'pattern' => $pattern,
                    'matched' => $matches[0],
                ]);
            }
        }

        // Check PII patterns
        $this->logger->debug('[PolicyGuard] Checking PII patterns', [
            'patterns_count' => count(self::PII_PATTERNS),
        ]);

        foreach (self::PII_PATTERNS as $pattern) {
            if (preg_match($pattern, $text)) {
                $flags[] = 'pii_detected';
                $this->logger->warning('[PolicyGuard] ❌ PII detected', [
                    'pattern' => $pattern,
                ]);

                break; // One flag is enough
            }
        }

        // Out-of-band channel patterns. Distinct flag from
        // `pii_detected` so audit consumers can tell at a glance which
        // category of leak triggered the rejection.
        foreach (self::OUT_OF_BAND_CHANNEL_PATTERNS as $kind => $pattern) {
            if (preg_match($pattern, $text, $matches)) {
                $flags[] = 'out_of_band_channel:' . $kind;
                $this->logger->warning('[PolicyGuard] ❌ Out-of-band channel detected', [
                    'kind' => $kind,
                    'pattern' => $pattern,
                    'matched' => $matches[0],
                ]);

                break; // One flag is enough — orchestrator retries
            }
        }

        $approved = $flags === [];

        $this->logger->info('[PolicyGuard] ✅ Validation completed', [
            'approved' => $approved,
            'flags_count' => count($flags),
            'flags' => $flags,
        ]);

        return [
            'approved' => $approved,
            'flags' => $flags,
        ];
    }
}
