<?php

declare(strict_types=1);

namespace App\Application\Communication;

use App\Application\LLM\Port\LLMClientInterface;
use Psr\Log\LoggerInterface;

/**
 * LLM-based IOC extraction service
 *
 * Extracts Indicators of Compromise from message text using LLM.
 * Advantages over regex:
 * - Understands semantic context (distinguishes phone numbers from invoice numbers)
 * - Extracts obfuscated IOCs (e.g., "payp4l . c0m" → "paypal.com")
 * - Extracts IOCs described in natural language (e.g., "zéro six douze..." → "0612...")
 * - No false positives on dates, SIRET, invoice numbers
 *
 * Architecture: Application service following hexagonal architecture
 * - Depends on LLMClientInterface port (infrastructure provides concrete implementation)
 * - Uses JsonValidator for response parsing
 * - Logs extraction attempts for debugging
 */
final class IocExtractor
{
    /**
     * All supported IOC types (40+ types from Sprint 3 spec)
     */
    private const ALL_IOC_TYPES = [
        // Email & Headers
        'email', 'whois_email', 'message_id', 'subject', 'x_mailer', 'return_path',
        'spf_result', 'dkim_result', 'dmarc_result',

        // Infrastructure
        'url', 'domain', 'ipv4', 'ipv6', 'registrar', 'whois_registrar_name',

        // Hashes
        'md5', 'sha1', 'sha256',

        // Finance
        'iban', 'bic', 'wallet_btc', 'wallet_eth', 'wallet_xmr', 'bank_account', 'credit_card',

        // Contact channels
        'phone', 'telegram_username', 'discord_username', 'skype_id',

        // Files
        'filename', 'mimetype',

        // Security identifiers
        'cve', 'malware_family', 'mitre_attack_id',
    ];

    public function __construct(
        private readonly LLMClientInterface $llmClient,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * Extract IOCs from text using LLM
     *
     * @param string        $text  Text to extract IOCs from (subject + body)
     * @param array<string> $types IOC types to extract (empty = all types)
     *
     * @return array<array{type: string, value: string}> Array of extracted IOCs
     */
    public function extractIocsWithLLM(string $text, array $types = []): array
    {
        if (empty($text)) {
            $this->logger->warning('Cannot extract IOCs from empty text');

            return [];
        }

        // Limit text length for LLM (max ~4000 chars to stay within token limits)
        if (strlen($text) > 4000) {
            $text = substr($text, 0, 4000) . '... [truncated]';
            $this->logger->info('Truncated text for LLM IOC extraction', ['original_length' => strlen($text)]);
        }

        $allowedTypes = empty($types) ? self::ALL_IOC_TYPES : $types;

        // Build prompt
        $prompt = $this->buildIocExtractionPrompt($text, $allowedTypes);

        try {
            // Call LLM
            $llmMessages = [
                ['role' => 'system', 'content' => $prompt['system']],
                ['role' => 'user', 'content' => $prompt['user']],
            ];

            $this->logger->debug('Calling LLM for IOC extraction', [
                'text_length' => strlen($text),
                'allowed_types' => $allowedTypes,
            ]);

            $response = $this->llmClient->chat($llmMessages, [
                'temperature' => 0.1,  // Very low temperature for precision
                'max_tokens' => 2000,  // Enough for many IOCs
            ]);

            $this->logger->debug('LLM IOC extraction response received', [
                'response_length' => strlen($response),
                'response_preview' => substr($response, 0, 200),
            ]);

            // Parse JSON response (IOC extraction returns simple array, not complex object like ScamClassifier)
            try {
                $data = json_decode($response, true, 512, JSON_THROW_ON_ERROR);
            } catch (\JsonException $e) {
                $this->logger->error('LLM IOC extraction JSON parsing failed', [
                    'error' => $e->getMessage(),
                    'response' => substr($response, 0, 500),
                ]);

                return [];
            }

            if (!is_array($data)) {
                $this->logger->error('LLM IOC extraction returned non-array data', [
                    'data_type' => gettype($data),
                ]);

                return [];
            }

            // Validate extracted IOCs structure
            $validatedIocs = [];

            foreach ($data as $ioc) {
                if (!is_array($ioc)) {
                    continue;
                }

                if (!isset($ioc['type']) || !isset($ioc['value'])) {
                    $this->logger->warning('Invalid IOC structure (missing type or value)', ['ioc' => $ioc]);

                    continue;
                }

                // Validate type is allowed
                if (!in_array($ioc['type'], $allowedTypes, true)) {
                    $this->logger->warning('LLM returned disallowed IOC type', [
                        'type' => $ioc['type'],
                        'allowed' => $allowedTypes,
                    ]);

                    continue;
                }

                $validatedIocs[] = [
                    'type' => $ioc['type'],
                    'value' => $ioc['value'],
                ];
            }

            $this->logger->info('LLM IOC extraction successful', [
                'iocs_found' => count($validatedIocs),
            ]);

            return $validatedIocs;
        } catch (\Exception $e) {
            $this->logger->error('LLM IOC extraction failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return [];
        }
    }

    /**
     * Build prompts for IOC extraction
     *
     * @param string        $text         Text to extract IOCs from
     * @param array<string> $allowedTypes IOC types to extract
     *
     * @return array{system: string, user: string}
     */
    private function buildIocExtractionPrompt(string $text, array $allowedTypes): array
    {
        $typesJson = json_encode($allowedTypes);

        $systemPrompt = <<<SYSTEM
You are a cybersecurity analyst specialized in extracting Indicators of Compromise (IOCs) from phishing and scam emails.

Your task is to extract ALL IOCs from the email content and return them as a JSON array.

ALLOWED IOC TYPES: {$typesJson}

EXTRACTION RULES:
1. **URLs and Domains**: Extract in ORIGINAL format (do NOT defang)
   - Extract URLs exactly as they appear (e.g., "https://evil.com")
   - Extract domains exactly as they appear (e.g., "evil.com")
   - The system will automatically defang them for safe storage

2. **Phone Numbers**: Extract ONLY real phone numbers, NOT:
   - Dates (e.g., "2025-11-05")
   - Invoice numbers (e.g., "FAC-2025-88951")
   - SIRET/SIREN numbers (e.g., "11907593899875")
   - Contract/market numbers (e.g., "MP-667-2025")
   - Use context to distinguish real phones from other numbers
   - Preserve international prefix (+33, +1, etc.) when present

3. **Email Addresses**: Extract all email addresses (lowercase)

4. **Financial IOCs**:
   - IBAN: Remove spaces, uppercase (e.g., "FR76 1234..." → "FR7612345...")
   - Crypto wallets: Extract Bitcoin, Ethereum, Monero addresses
   - Credit cards: Extract ONLY if clearly mentioned as payment method

5. **Hashes**: Extract MD5, SHA1, SHA256 hashes (lowercase hex)

6. **Obfuscated IOCs**: Extract obfuscated IOCs and normalize them
   - "payp4l . c0m" → "paypal.com"
   - "g00gle dot com" → "google.com"
   - "hxxps://example[.]com" → "https://example.com" (refang if already defanged)

7. **Context Awareness**: Use semantic understanding to distinguish:
   - Phone numbers vs dates/invoice numbers
   - Real URLs vs example URLs in signatures
   - Financial data vs random numbers

OUTPUT FORMAT:
Return a JSON array of objects with "type" and "value" fields:
[
  {"type": "url", "value": "https://evil.com"},
  {"type": "phone", "value": "+33612345678"},
  {"type": "email", "value": "scammer@example.com"},
  {"type": "iban", "value": "FR7612345678901234567890185"}
]

IMPORTANT: Extract URLs and domains in their ORIGINAL format (NOT defanged).

IMPORTANT:
- Return ONLY the JSON array, no additional text
- If no IOCs found, return empty array: []
- DO NOT invent IOCs, extract ONLY what exists in the text
- DO NOT extract example/placeholder data from email signatures
SYSTEM;

        $userPrompt = <<<USER
EMAIL CONTENT:
{$text}

Extract all IOCs following the rules above. Return strict JSON array.
USER;

        return [
            'system' => $systemPrompt,
            'user' => $userPrompt,
        ];
    }

    /**
     * Get all supported IOC types
     *
     * @return array<string>
     */
    public static function getSupportedTypes(): array
    {
        return self::ALL_IOC_TYPES;
    }
}
