<?php

declare(strict_types=1);

namespace App\Application\LLM;

use Psr\Log\LoggerInterface;

/**
 * PolicyGuard enforces hard rules on generated text
 *
 * Validates text against policies like length limits, forbidden patterns,
 * and PII detection without using LLM inference.
 */
final class PolicyGuard
{
    /** @var array<string> Forbidden patterns that reveal the honeypot */
    private const FORBIDDEN_PATTERNS = [
        '/\bhoneypot\b/i',
        '/\btest(?:ing)?\b/i',
        '/\banalyse\b/i',
        '/\bleurre\b/i',
        '/\bfake\b/i',
        '/\bsimulation\b/i',
        '/\bbot\b/i',
        '/\bautomatique\b/i',
        '/\bintelligence artificielle\b/i',
        '/\bscambuster\b/i',
        '/\barnaque\b/i',
        '/\bscam\b/i',
        '/\bsuspect\b/i',
        '/\bétrange\b/i',
        '/\binhabituel\b/i',
        '/\bmenace\b/i',
    ];

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
    ];

    /** @var array<string> PII patterns to detect and reject
     *
     * Note: Phone numbers are ALLOWED (we provide fake ones to attackers)
     * Only IBAN and full addresses are blocked as they're too sensitive
     */
    private const PII_PATTERNS = [
        '/\b[A-Z]{2}\d{2}[A-Z0-9]{11,30}\b/', // IBAN (real bank account)
        '/\b\d{1,3}\s+(?:rue|avenue|boulevard|impasse)\s+[A-Z]/i', // Full address with street name
    ];

    public function __construct(
        private readonly LoggerInterface $logger,
        private readonly int $minWords = 50,
        private readonly int $maxWords = 150,
        private readonly int $maxLinks = 1
    ) {
    }

    /**
     * Validate text against all hard rules
     *
     * @param string $text Text to validate
     *
     * @return array{approved: bool, flags: array<string>}
     */
    public function validate(string $text): array
    {
        $this->logger->debug('[PolicyGuard] Starting syntactic validation', [
            'text_length' => strlen($text),
            'text_preview' => substr($text, 0, 100) . '...',
        ]);

        $flags = [];

        // Check length (max only - min is handled by ReplyValidator's "maintient la conversation" check)
        $wordCount = str_word_count($text, 0, 'àâäéèêëïîôùûüÿç');

        $this->logger->debug('[PolicyGuard] Checking word count', [
            'word_count' => $wordCount,
            'min_allowed' => $this->minWords,
            'max_allowed' => $this->maxWords,
        ]);

        if ($wordCount > $this->maxWords) {
            $flags[] = "too_long:{$wordCount}_words";
            $this->logger->warning('[PolicyGuard] ❌ Text too long', [
                'word_count' => $wordCount,
                'max_allowed' => $this->maxWords,
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

        $approved = empty($flags);

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
