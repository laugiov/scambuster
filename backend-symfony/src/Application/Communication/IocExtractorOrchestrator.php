<?php

declare(strict_types=1);

namespace App\Application\Communication;

use App\Domain\Communication\Message;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Orchestrates IOC extraction from messages using multiple methods.
 *
 * Extracted from IocHandler (CT-0 decomposition).
 * Provides pure extraction logic (regex, LLM, derivation) without persistence.
 */
class IocExtractorOrchestrator
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly IocValidator $validator,
        private readonly IocNormalizer $normalizer,
        private readonly IocExtractor $iocExtractor,
    ) {
    }

    /**
     * Extract IOCs from a message (without persisting).
     *
     * @param string             $msgId  Message ID
     * @param string             $method 'regex', 'llm', or 'hybrid'
     * @param array<int, string> $types  IOC types to extract (empty = all)
     *
     * @throws \RuntimeException if message not found
     *
     * @return array<int, array<string, mixed>>
     */
    public function extractFromMessage(string $msgId, string $method = 'hybrid', array $types = []): array
    {
        $message = $this->em->getRepository(Message::class)->find($msgId);

        if (!$message || $message->getDeletedAt() !== null) {
            throw new \RuntimeException("Message not found: {$msgId}");
        }

        $text = $message->getSubject() . "\n\n" . $message->getBodyText();

        $iocs = [];

        if ($method === 'regex' || $method === 'hybrid') {
            $iocs = array_merge($iocs, $this->extractIocsWithRegex($text, $types));
        }

        if ($method === 'llm' || $method === 'hybrid') {
            $llmIocs = $this->iocExtractor->extractIocsWithLLM($text, $types);

            foreach ($llmIocs as $llmIoc) {
                $type = $llmIoc['type'];
                $value = $llmIoc['value'];

                if (!$this->validator->validate($type, $value)) {
                    continue;
                }

                $valueNorm = $this->normalizer->normalize($type, $value);

                $iocs[] = [
                    'type' => $type,
                    'value' => $value,
                    'value_norm' => $valueNorm,
                    'context' => [
                        'extraction_method' => 'llm',
                    ],
                ];
            }
        }

        // Deduplicate
        $uniqueIocs = [];
        $seen = [];

        foreach ($iocs as $ioc) {
            /** @var string $iocType */
            $iocType = $ioc['type'] ?? '';
            /** @var string $iocValueNorm */
            $iocValueNorm = $ioc['value_norm'] ?? '';
            $key = $iocType . ':' . $iocValueNorm;

            if (!isset($seen[$key])) {
                $uniqueIocs[] = $ioc;
                $seen[$key] = true;
            }
        }

        return $this->deriveAdditionalIocs($uniqueIocs);
    }

    /**
     * Derive additional IOCs from extracted URLs and emails.
     *
     * @param array<int, array<string, mixed>> $iocs
     *
     * @return array<int, array<string, mixed>>
     */
    public function deriveAdditionalIocs(array $iocs): array
    {
        $skipDomains = [
            'gmail.com', 'yahoo.com', 'outlook.com', 'hotmail.com',
            'proton.me', 'protonmail.com', 'live.com', 'icloud.com',
            'aol.com', 'mail.com', 'yandex.com', 'zoho.com',
        ];

        $existingKeys = [];

        foreach ($iocs as $ioc) {
            /** @var array<string, mixed> $ioc */
            $type = \is_string($ioc['type'] ?? null) ? $ioc['type'] : '';
            $norm = strtolower(\is_string($ioc['value_norm'] ?? null) ? $ioc['value_norm'] : (\is_string($ioc['value'] ?? null) ? $ioc['value'] : ''));
            $existingKeys[$type . ':' . $norm] = true;
        }

        $derived = [];

        foreach ($iocs as $ioc) {
            /** @var array<string, mixed> $ioc */
            $type = \is_string($ioc['type'] ?? null) ? $ioc['type'] : '';
            $value = \is_string($ioc['value'] ?? null) ? $ioc['value'] : '';

            if ($type === 'url') {
                $refanged = str_replace(['hxxp', '[.]', '[:]'], ['http', '.', ':'], $value);
                $parsed = parse_url($refanged);
                $host = $parsed['host'] ?? null;

                if ($host !== null) {
                    $hostLower = strtolower($host);

                    if (filter_var($host, \FILTER_VALIDATE_IP, \FILTER_FLAG_IPV4)) {
                        $key = 'ipv4:' . $host;

                        if (!isset($existingKeys[$key])) {
                            $derived[] = [
                                'type' => 'ipv4',
                                'value' => $host,
                                'value_norm' => $host,
                                'context' => ['extraction_method' => 'derived_from_url'],
                            ];
                            $existingKeys[$key] = true;
                        }
                    } elseif (!filter_var($host, \FILTER_VALIDATE_IP)) {
                        $key = 'domain:' . $hostLower;

                        if (!isset($existingKeys[$key])) {
                            $normDomain = $this->normalizer->normalize('domain', $host);
                            $derived[] = [
                                'type' => 'domain',
                                'value' => $host,
                                'value_norm' => $normDomain,
                                'context' => ['extraction_method' => 'derived_from_url'],
                            ];
                            $existingKeys[$key] = true;
                        }
                    }
                }
            }

            if ($type === 'email') {
                $parts = explode('@', $value);
                $domain = $parts[1] ?? null;

                if ($domain !== null) {
                    $domainLower = strtolower($domain);
                    $key = 'domain:' . $domainLower;

                    if (!isset($existingKeys[$key]) && !\in_array($domainLower, $skipDomains, true)) {
                        $normDomain = $this->normalizer->normalize('domain', $domain);
                        $derived[] = [
                            'type' => 'domain',
                            'value' => $domain,
                            'value_norm' => $normDomain,
                            'context' => ['extraction_method' => 'derived_from_email'],
                        ];
                        $existingKeys[$key] = true;
                    }
                }
            }
        }

        return array_merge($iocs, $derived);
    }

    /**
     * Extract IOCs using regex patterns.
     *
     * @param array<int, string> $types
     *
     * @return array<int, array<string, mixed>>
     */
    public function extractIocsWithRegex(string $text, array $types = []): array
    {
        $iocs = [];

        $patterns = [
            'ipv4' => '/\b(?:(?:25[0-5]|2[0-4]\d|[01]?\d\d?)\.){3}(?:25[0-5]|2[0-4]\d|[01]?\d\d?)\b/',
            'ipv6' => '/\b(([0-9a-fA-F]{1,4}:){7,7}[0-9a-fA-F]{1,4}|([0-9a-fA-F]{1,4}:){1,7}:|([0-9a-fA-F]{1,4}:){1,6}:[0-9a-fA-F]{1,4}|([0-9a-fA-F]{1,4}:){1,5}(:[0-9a-fA-F]{1,4}){1,2}|([0-9a-fA-F]{1,4}:){1,4}(:[0-9a-fA-F]{1,4}){1,3}|([0-9a-fA-F]{1,4}:){1,3}(:[0-9a-fA-F]{1,4}){1,4}|([0-9a-fA-F]{1,4}:){1,2}(:[0-9a-fA-F]{1,4}){1,5}|[0-9a-fA-F]{1,4}:((:[0-9a-fA-F]{1,4}){1,6})|:((:[0-9a-fA-F]{1,4}){1,7}|:)|fe80:(:[0-9a-fA-F]{0,4}){0,4}%[0-9a-zA-Z]{1,}|::(ffff(:0{1,4}){0,1}:){0,1}((25[0-5]|(2[0-4]|1{0,1}\d){0,1}\d)\.){3,3}(25[0-5]|(2[0-4]|1{0,1}\d){0,1}\d)|([0-9a-fA-F]{1,4}:){1,4}:((25[0-5]|(2[0-4]|1{0,1}\d){0,1}\d)\.){3,3}(25[0-5]|(2[0-4]|1{0,1}\d){0,1}\d))\b/',
            'email' => '/\b[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[A-Z|a-z]{2,}\b/',
            'url' => '#\b(?:https?://|www\.)[^\s<>"{}|\\^\[\]`]+#i',
            'domain' => '/\b(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,}\b/i',
            'md5' => '/\b[a-f0-9]{32}\b/i',
            'sha1' => '/\b[a-f0-9]{40}\b/i',
            'sha256' => '/\b[a-f0-9]{64}\b/i',
            'iban' => '/\b[A-Z]{2}\d{2}[A-Z0-9]{1,30}\b/',
            'bic' => '/\b[A-Z]{6}[A-Z0-9]{2}([A-Z0-9]{3})?\b/',
            'wallet_btc' => '/\b(bc1|[13])[a-zA-HJ-NP-Z0-9]{25,62}\b/',
            'wallet_eth' => '/\b0x[a-fA-F0-9]{40}\b/',
            'wallet_xmr' => '/\b[48][0-9AB][1-9A-HJ-NP-Za-km-z]{93}\b/',
            'credit_card' => '/\b\d{4}[\s\-]?\d{4}[\s\-]?\d{4}[\s\-]?\d{4}\b/',
            'phone' => '/(?:\+\d{1,4}[\s.-]\d{2,4}[\s.-]\d{2,4}[\s.-]\d{2,6}|\+\d{7,15}\b|\b(?:\+?\d{1,3}[-.\s]?)?\(?\d{2,4}\)?[-.\s]?\d{2,4}[-.\s]?\d{2,4}(?:[-.\s]?\d{2,4})?\b)/',
            'telegram_username' => '/(?<!\w)@[a-zA-Z]\w{4,31}\b/',
            'discord_username' => '/\b.{2,32}#\d{4}\b/',
            'cve' => '/\bCVE-\d{4}-\d{4,}\b/i',
            'mitre_attack_id' => '/\bT\d{4}(?:\.\d{3})?\b/',
            'tracking_number' => '/\b(?:DHL|UPS|FedEx|USPS|TNT|EMS|Royal Mail|Colissimo)[-\s]?\d{6,15}[-\s]?[A-Z]{0,2}\b/i',
        ];

        if ($types !== []) {
            $patterns = array_intersect_key($patterns, array_flip($types));
        }

        foreach ($patterns as $type => $pattern) {
            if (preg_match_all($pattern, $text, $matches)) {
                foreach ($matches[0] as $value) {
                    if (!$this->validator->validate($type, $value)) {
                        continue;
                    }

                    $valueNorm = $this->normalizer->normalize($type, $value);

                    if ($type === 'ipv4' && $this->isPrivateIp($value)) {
                        continue;
                    }

                    $iocs[] = [
                        'type' => $type,
                        'value' => $value,
                        'value_norm' => $valueNorm,
                        'context' => [
                            'extraction_method' => 'regex',
                            'pattern' => $type,
                        ],
                    ];
                }
            }
        }

        return $iocs;
    }

    /**
     * Check if an IP is private/reserved.
     */
    private function isPrivateIp(string $ip): bool
    {
        $long = ip2long($ip);

        if ($long === false) {
            return true;
        }

        return (
            ($long >= ip2long('10.0.0.0') && $long <= ip2long('10.255.255.255'))
            || ($long >= ip2long('172.16.0.0') && $long <= ip2long('172.31.255.255'))
            || ($long >= ip2long('192.168.0.0') && $long <= ip2long('192.168.255.255'))
            || ($long >= ip2long('127.0.0.0') && $long <= ip2long('127.255.255.255'))
        );
    }
}
