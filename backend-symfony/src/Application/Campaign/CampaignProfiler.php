<?php

declare(strict_types=1);

namespace App\Application\Campaign;

use App\Application\LLM\Port\LLMClientInterface;
use App\Domain\Communication\Message;
use Psr\Log\LoggerInterface;
use Symfony\Component\Yaml\Yaml;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

/**
 * Campaign Profiler - Analyse des campagnes via LLM
 *
 * Generates a structured YAML profile describing a phishing/scam campaign
 * from a sample of messages.
 *
 * Features:
 * - Retry logic avec exponential backoff (1s, 2s, 4s)
 * - Cache Redis (TTL 2h)
 * - Validation YAML stricte
 * - PII detection et masquage
 * - Detailed logging
 */
final readonly class CampaignProfiler
{
    private const MAX_RETRIES = 3;
    private const TIMEOUT_SEC = 45;
    private const CACHE_TTL = 7200; // 2 heures

    /**
     * Exponential backoff delays (secondes).
     *
     * @var array<int>
     */
    private const BACKOFF_DELAYS = [1, 2, 4];

    public function __construct(
        private LLMClientInterface $llmClient,
        private PromptBuilder $promptBuilder,
        private CacheInterface $cache,
        private LoggerInterface $logger
    ) {
    }

    /**
     * Profiles a campaign via LLM and returns a structured YAML profile.
     *
     * @param array<Message> $sampleMessages Échantillon de 3-10 messages
     *
     * @throws \RuntimeException If profiling fails after MAX_RETRIES attempts
     *
     * @return array{profile_yaml: string, cache_hit: bool, attempts: int}
     */
    public function profile(array $sampleMessages): array
    {
        if (count($sampleMessages) < 3) {
            throw new \InvalidArgumentException('Au moins 3 messages requis pour profiler une campagne');
        }

        if (count($sampleMessages) > 10) {
            $this->logger->warning('Too many messages provided, limiting to 10', [
                'provided' => count($sampleMessages),
            ]);
            $sampleMessages = array_slice($sampleMessages, 0, 10);
        }

        // Generate a cache key based on message IDs
        $cacheKey = $this->generateCacheKey($sampleMessages);

        $startTime = microtime(true);

        // Try to retrieve from cache
        try {
            $cached = $this->cache->get($cacheKey, function (ItemInterface $item) use ($sampleMessages): array {
                $item->expiresAfter(self::CACHE_TTL);

                $this->logger->info('Cache miss - Profiling campaign via LLM', [
                    'sample_size' => count($sampleMessages),
                ]);

                // Profiling avec retry logic
                $result = $this->profileWithRetry($sampleMessages);

                return $result;
            });

            $latencyMs = (int) ((microtime(true) - $startTime) * 1000);
            /** @var array{profile_yaml: string, attempts: int} $cached */
            $cacheHit = true;

            $this->logger->info('Campaign profiling completed', [
                'cache_hit' => $cacheHit,
                'latency_ms' => $latencyMs,
                'attempts' => $cached['attempts'],
            ]);

            return [
                'profile_yaml' => $cached['profile_yaml'],
                'cache_hit' => $cacheHit,
                'attempts' => $cached['attempts'],
            ];
        } catch (\Throwable $e) {
            $this->logger->error('Campaign profiling failed', [
                'error' => $e->getMessage(),
                'sample_size' => count($sampleMessages),
            ]);

            throw new \RuntimeException("Campaign profiling failed: {$e->getMessage()}", $e->getCode(), previous: $e);
        }
    }

    /**
     * Profile avec retry logic et exponential backoff.
     *
     * @param array<Message> $sampleMessages
     *
     * @throws \RuntimeException If all attempts fail
     *
     * @return array{profile_yaml: string, attempts: int}
     */
    private function profileWithRetry(array $sampleMessages): array
    {
        $lastException = null;

        for ($attempt = 1; $attempt <= self::MAX_RETRIES; $attempt++) {
            try {
                $this->logger->debug("Profiling attempt {$attempt}/" . self::MAX_RETRIES, [
                    'sample_size' => count($sampleMessages),
                ]);

                // 1. Construire les prompts
                $prompts = $this->promptBuilder->buildCampaignProfilerPrompts($sampleMessages);

                // 2. Appel LLM
                $messages = [
                    ['role' => 'system', 'content' => $prompts['system']],
                    ['role' => 'user', 'content' => $prompts['user']],
                ];

                $response = $this->llmClient->chat($messages, [
                    'temperature' => 0.3, // Low temperature for structured output
                    'max_tokens' => 800,
                    'timeout' => self::TIMEOUT_SEC,
                ]);

                // 3. Extract YAML from response (may contain markdown)
                $yamlText = $this->extractYaml($response);

                // 4. Valider YAML
                $this->validateProfileYaml($yamlText);

                // 5. PII detection
                $this->detectPII($yamlText);

                $this->logger->info('Campaign profiling succeeded', [
                    'attempt' => $attempt,
                    'yaml_length' => strlen($yamlText),
                ]);

                return [
                    'profile_yaml' => $yamlText,
                    'attempts' => $attempt,
                ];
            } catch (\Throwable $e) {
                $lastException = $e;

                $this->logger->warning("Profiling attempt {$attempt} failed", [
                    'attempt' => $attempt,
                    'error' => $e->getMessage(),
                ]);

                // If not the last attempt, wait with backoff
                if ($attempt < self::MAX_RETRIES) {
                    $delay = self::BACKOFF_DELAYS[$attempt - 1];
                    $this->logger->debug("Retrying in {$delay}s...");
                    sleep($delay);
                }
            }
        }

        // All attempts failed
        /** @var \Throwable $lastException */
        throw new \RuntimeException(
            'Campaign profiling failed after ' . self::MAX_RETRIES . ' attempts: ' . $lastException->getMessage(),
            previous: $lastException
        );
    }

    /**
     * Extracts YAML from an LLM response (may contain markdown).
     */
    private function extractYaml(string $response): string
    {
        $response = trim($response);

        // Cas 1: YAML dans code block markdown
        if (preg_match('/```(?:yaml)?\s*(campaign:.*?)```/s', $response, $matches)) {
            return trim($matches[1]);
        }

        // Cas 2: YAML brut (commence par "campaign:")
        if (preg_match('/(campaign:.*)/s', $response, $matches)) {
            return trim($matches[1]);
        }

        throw new \RuntimeException('No valid YAML found in LLM response');
    }

    /**
     * Valide la structure YAML du profil.
     *
     * @throws \RuntimeException Si le YAML est invalide ou incomplet
     */
    private function validateProfileYaml(string $yamlText): void
    {
        try {
            $data = Yaml::parse($yamlText);
        } catch (\Throwable $e) {
            throw new \RuntimeException("Invalid YAML syntax: {$e->getMessage()}", $e->getCode(), previous: $e);
        }

        if (!is_array($data)) {
            throw new \RuntimeException('Invalid YAML: expected an array');
        }

        // Verify minimum required structure
        $requiredKeys = ['campaign', 'variants', 'infra'];

        foreach ($requiredKeys as $key) {
            if (!isset($data[$key])) {
                throw new \RuntimeException("Missing required YAML key: {$key}");
            }
        }

        // Verify campaign sub-keys
        $campaignData = $data['campaign'];

        if (!is_array($campaignData)) {
            throw new \RuntimeException('campaign must be an array');
        }

        $campaignKeys = ['summary', 'tactics', 'target_audience', 'cta', 'risk'];

        foreach ($campaignKeys as $key) {
            if (!isset($campaignData[$key])) {
                throw new \RuntimeException("Missing required campaign key: {$key}");
            }
        }

        // Verify variants sub-keys
        $variantsData = $data['variants'];

        if (!is_array($variantsData)) {
            throw new \RuntimeException('variants must be an array');
        }

        $variantsKeys = ['subjects', 'display_names', 'url_shapes'];

        foreach ($variantsKeys as $key) {
            if (!isset($variantsData[$key])) {
                throw new \RuntimeException("Missing required variants key: {$key}");
            }
        }

        // Verify types
        if (!is_array($campaignData['tactics'])) {
            throw new \RuntimeException('campaign.tactics must be an array');
        }

        if (!is_int($campaignData['risk']) || $campaignData['risk'] < 1 || $campaignData['risk'] > 5) {
            throw new \RuntimeException('campaign.risk must be integer between 1 and 5');
        }
    }

    /**
     * Detects PII (personal data) in the YAML.
     *
     * @throws \RuntimeException If PII is detected
     */
    private function detectPII(string $yamlText): void
    {
        $patterns = [
            // Email en clair (ex: user@example.com)
            '/\b[a-z0-9._%+-]+@[a-z0-9.-]+\.[a-z]{2,}\b/i',
            // French phone number (e.g. 06 12 34 56 78, +33612345678)
            '/\b(?:\+33|0)[1-9](?:[\s.-]?\d{2}){4}\b/',
            // IBAN (commence par 2 lettres + 2 chiffres)
            '/\b[A-Z]{2}\d{2}[A-Z0-9]{10,30}\b/',
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $yamlText, $matches)) {
                $this->logger->error('PII detected in profile YAML', [
                    'pattern' => $pattern,
                    'match' => $matches[0],
                ]);

                throw new \RuntimeException('PII detected in profile: ' . $matches[0]);
            }
        }
    }

    /**
     * Generates a cache key based on message IDs.
     *
     * @param array<Message> $messages
     */
    private function generateCacheKey(array $messages): string
    {
        $ids = array_map(fn ($msg): string => $msg->getMsgId(), $messages);
        sort($ids); // Ordre stable

        return 'campaign_profile_' . md5(implode(':', $ids));
    }
}
