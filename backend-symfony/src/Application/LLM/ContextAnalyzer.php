<?php

declare(strict_types=1);

namespace App\Application\LLM;

/**
 * Analyzes conversation context to extract structured state information
 *
 * Provides intelligent analysis of conversation history to determine:
 * - Conversation stage (first_contact, follow_up, payment_push)
 * - IOCs already obtained from the attacker
 * - Missing IOCs to target next
 * - Target communication channel priority
 * - Attacker's tone and urgency level
 * - Promises made by the victim (us)
 *
 * This analysis enables more targeted and effective LLM prompting.
 */
final readonly class ContextAnalyzer
{
    public function __construct(
        private ?\Psr\Log\LoggerInterface $logger = null,
    ) {
    }

    /** @var array<string> All possible IOC types we want to collect */
    private const IOC_TYPES = ['phone', 'url', 'iban', 'whatsapp', 'crypto', 'email'];

    /** @var array<string> Urgent keywords indicating pressured tone */
    private const URGENT_KEYWORDS = [
        'urgent',
        'immédiat',
        'rapidement',
        'vite',
        'maintenant',
        'tout de suite',
        'sans délai',
        'impératif',
    ];

    /** @var array<string, string> Channel detection keywords */
    private const CHANNEL_KEYWORDS = [
        'whatsapp' => 'whatsapp',
        'telegram' => 'whatsapp', // Group messaging apps together
        'iban' => 'iban',
        'virement' => 'iban',
        'compte bancaire' => 'iban',
        'téléphone' => 'phone',
        'appel' => 'phone',
        'appeler' => 'phone',
        'numéro' => 'phone',
        'site' => 'url',
        'lien' => 'url',
        'cliquer' => 'url',
        'bitcoin' => 'crypto',
        'crypto' => 'crypto',
        'wallet' => 'crypto',
    ];

    /**
     * Analyze conversation to extract structured state slots
     *
     * @param array<int, array{direction: string, body_text: string, ts_msg: string, headers: array<string, mixed>}> $messages
     *
     * @return array{
     *     stage: string,
     *     iocs_obtained: array<int, array{type: string, value: string}>,
     *     missing_iocs: array<int, string>,
     *     canal_cible: string,
     *     risk_tone: string,
     *     promises_made: array<int, string>,
     *     message_count: int
     * }
     */
    public function analyzeConversation(array $messages): array
    {
        $result = [
            'stage' => $this->detectStage($messages),
            'iocs_obtained' => $this->extractIOCs($messages),
            'missing_iocs' => $this->identifyMissingIOCs($messages),
            'canal_cible' => $this->detectTargetChannel($messages),
            'risk_tone' => $this->analyzeTone($messages),
            'promises_made' => $this->extractPromises($messages),
            'message_count' => count($messages),
        ];

        $this->logger?->debug('[ContextAnalyzer] Analysis complete', [
            'stage' => $result['stage'],
            'iocs_obtained' => $result['iocs_obtained'],
            'missing_iocs' => $result['missing_iocs'],
            'risk_tone' => $result['risk_tone'],
        ]);

        return $result;
    }

    /**
     * Detect conversation stage based on message count and content
     *
     * @param array<int, array{direction: string, body_text: string}> $messages
     */
    private function detectStage(array $messages): string
    {
        $count = count($messages);

        // Analyze content for payment-related keywords
        $lastMessages = array_slice($messages, -3); // Last 3 messages
        $combinedText = implode(' ', array_column($lastMessages, 'body_text'));

        $paymentKeywords = [
            'payer',
            'paiement',
            'envoyer',
            'transférer',
            'argent',
            'euros',
            'dollars',
            'virement',
            'iban',
            'crypto',
        ];

        foreach ($paymentKeywords as $keyword) {
            if (stripos($combinedText, $keyword) !== false) {
                return 'payment_push';
            }
        }

        // Stage based on message count
        if ($count <= 2) {
            return 'first_contact';
        }

        if ($count <= 5) {
            return 'follow_up';
        }

        return 'payment_push';
    }

    /**
     * Extract IOCs from attacker messages (direction='in')
     *
     * @param array<int, array{direction: string, body_text: string}> $messages
     *
     * @return array<int, array{type: string, value: string}>
     */
    private function extractIOCs(array $messages): array
    {
        $iocs = [];

        foreach ($messages as $msg) {
            // Only extract from attacker messages
            if ($msg['direction'] !== 'in') {
                continue;
            }

            $text = $msg['body_text'];
            $cleanText = $text; // Copy for phone extraction after removing IBANs

            // IBAN (check first and remove from text to avoid phone regex confusion)
            if (preg_match('/\b([A-Z]{2}\d{2}[A-Z0-9]{11,30})\b/', $text, $matches)) {
                $iocs[] = ['type' => 'iban', 'value' => $matches[1]];
                // Remove IBAN from clean text to avoid phone false positives
                $cleanText = str_replace($matches[1], '', $cleanText);
            }

            // Phone numbers (French format or international) - use cleaned text
            if (preg_match('/(\+?\d[\d\s]{8,14}\d)/', $cleanText, $matches)) {
                $iocs[] = [
                    'type' => 'phone',
                    'value' => preg_replace('/\s+/', '', $matches[1]), // Remove spaces
                ];
            }

            // URLs
            if (preg_match_all('#(https?://[^\s<>"{}|\\^`\[\]]+)#i', $text, $matches)) {
                foreach ($matches[1] as $url) {
                    $iocs[] = ['type' => 'url', 'value' => $url];
                }
            }

            // WhatsApp mentions
            if (preg_match('/whatsapp/i', $text)) {
                $iocs[] = ['type' => 'whatsapp', 'value' => 'mentioned'];
            }

            // Crypto addresses (basic pattern)
            if (preg_match('/\b(bc1|0x)[a-zA-Z0-9]{25,42}\b/', $text, $matches)) {
                $iocs[] = ['type' => 'crypto', 'value' => $matches[0]];
            }

            // Email addresses
            if (preg_match('/\b[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[A-Z|a-z]{2,}\b/', $text, $matches)) {
                $iocs[] = ['type' => 'email', 'value' => $matches[0]];
            }
        }

        // Filter out null values and deduplicate based on type+value
        /** @var array<int, array{type: string, value: string}> $validIocs */
        $validIocs = array_filter($iocs, fn (array $ioc): bool => $ioc['value'] !== null);

        return $this->deduplicateIOCs(array_values($validIocs));
    }

    /**
     * Identify IOC types not yet obtained
     *
     * @param array<int, array{direction: string, body_text: string}> $messages
     *
     * @return array<int, string>
     */
    private function identifyMissingIOCs(array $messages): array
    {
        $obtained = array_column($this->extractIOCs($messages), 'type');
        $obtainedUnique = array_unique($obtained);

        return array_values(array_diff(self::IOC_TYPES, $obtainedUnique));
    }

    /**
     * Detect which communication channel to target based on recent mentions
     *
     * @param array<int, array{direction: string, body_text: string}> $messages
     */
    private function detectTargetChannel(array $messages): string
    {
        if ($messages === []) {
            return 'phone'; // Default fallback
        }

        // Analyze last 2 messages with higher weight
        $lastMessages = array_slice($messages, -2);
        $recentText = strtolower(implode(' ', array_column($lastMessages, 'body_text')));

        // Check channel keywords
        foreach (self::CHANNEL_KEYWORDS as $keyword => $channel) {
            if (stripos($recentText, $keyword) !== false) {
                return $channel;
            }
        }

        // Check what IOCs are missing and prioritize
        $missing = $this->identifyMissingIOCs($messages);

        if ($missing === []) {
            return 'url'; // Fallback if all obtained
        }

        // Prioritize: phone > whatsapp > iban > url > crypto
        $priority = ['phone', 'whatsapp', 'iban', 'url', 'crypto', 'email'];

        foreach ($priority as $type) {
            if (in_array($type, $missing, true)) {
                return $type;
            }
        }

        return 'phone'; // Final fallback
    }

    /**
     * Analyze attacker's tone to detect urgency/pressure
     *
     * @param array<int, array{direction: string, body_text: string}> $messages
     */
    private function analyzeTone(array $messages): string
    {
        if ($messages === []) {
            return 'calm';
        }

        // Check last 3 attacker messages
        $attackerMessages = array_filter($messages, fn ($msg): bool => $msg['direction'] === 'in');
        $recentAttacker = array_slice($attackerMessages, -3);
        $combinedText = strtolower(implode(' ', array_column($recentAttacker, 'body_text')));

        // Check for urgent keywords
        foreach (self::URGENT_KEYWORDS as $keyword) {
            if (stripos($combinedText, $keyword) !== false) {
                return 'urgent';
            }
        }

        // Check for exclamation marks (indicator of urgency)
        $exclamationCount = substr_count($combinedText, '!');

        if ($exclamationCount >= 2) {
            return 'urgent';
        }

        // Check for UPPERCASE words (shouting)
        if (preg_match('/\b[A-Z]{4,}\b/', implode(' ', array_column($recentAttacker, 'body_text')))) {
            return 'urgent';
        }

        return 'calm';
    }

    /**
     * Extract promises made by the victim (outgoing messages)
     *
     * @param array<int, array{direction: string, body_text: string}> $messages
     *
     * @return array<int, string>
     */
    private function extractPromises(array $messages): array
    {
        $promises = [];

        foreach ($messages as $msg) {
            // Only check victim messages (direction='out')
            if ($msg['direction'] !== 'out') {
                continue;
            }

            $text = $msg['body_text'];

            // Pattern 1: "je vais/peux/vous envoie..."
            if (preg_match('/\b(je (?:vais|peux|vous envoie|vous donne|vais vous|peux vous)[^.!?]{0,50})/iu', $text, $matches)) {
                $promises[] = trim($matches[1]);
            }

            // Pattern 2: "je ferai/enverrai..."
            if (preg_match('/\b(je (?:ferai|enverrai|donnerai|fournirai)[^.!?]{0,50})/iu', $text, $matches)) {
                $promises[] = trim($matches[1]);
            }

            // Pattern 3: "je suis disponible à..." (French availability phrase)
            if (preg_match('/\b((?:disponible|joignable) (?:à|pour|ce|demain)[^.!?]{0,50})/iu', $text, $matches)) {
                $promises[] = trim($matches[1]);
            }
        }

        // Deduplicate and limit to last 5 promises
        return array_values(array_unique(array_slice($promises, -5)));
    }

    /**
     * Deduplicate IOCs based on type and value
     *
     * @param array<int, array{type: string, value: string}> $iocs
     *
     * @return array<int, array{type: string, value: string}>
     */
    private function deduplicateIOCs(array $iocs): array
    {
        $seen = [];
        $unique = [];

        foreach ($iocs as $ioc) {
            $key = $ioc['type'] . ':' . $ioc['value'];

            if (!isset($seen[$key])) {
                $seen[$key] = true;
                $unique[] = $ioc;
            }
        }

        return $unique;
    }
}
