<?php

declare(strict_types=1);

namespace App\Application\Campaign;

use App\Application\LLM\Port\LLMClientInterface;
use Psr\Log\LoggerInterface;

/**
 * Rule Compiler - Génération de règles DSL MailGuard
 *
 * Compile un profil YAML de campagne en règles DSL MailGuard exécutables.
 *
 * Features:
 * - Retry logic avec exponential backoff (1s, 2s, 4s)
 * - Validation syntaxe DSL
 * - Génération de tests automatiques
 * - Logging détaillé
 */
final readonly class RuleCompiler
{
    private const MAX_RETRIES = 3;
    private const TIMEOUT_SEC = 45;

    /**
     * Exponential backoff delays (secondes).
     *
     * @var array<int>
     */
    private const BACKOFF_DELAYS = [1, 2, 4];

    /**
     * Syntaxe DSL autorisée.
     */
    private const DSL_ALLOWED_FIELDS = [
        'subject',
        'body',
        'url.domain.age',
        'sender.display_name',
        'dkim.pass',
        'spf.pass',
    ];

    private const DSL_ALLOWED_OPERATORS = [
        'simhash≈',
        'containsAny',
        'fuzzy',
        '∈',
        '<',
        '>',
        '=',
    ];

    public function __construct(
        private LLMClientInterface $llmClient,
        private PromptBuilder $promptBuilder,
        private LoggerInterface $logger
    ) {
    }

    /**
     * Compile un profil YAML en règles DSL MailGuard.
     *
     * @param string                                                $profileYaml Profil YAML généré par CampaignProfiler
     * @param array{pos: array<int, mixed>, neg: array<int, mixed>} $examples    Exemples positifs/négatifs (optionnel)
     *
     * @throws \RuntimeException Si la compilation échoue après MAX_RETRIES tentatives
     *
     * @return array{rules_dsl: string, rules_count: int, attempts: int}
     */
    public function compile(string $profileYaml, array $examples = ['pos' => [], 'neg' => []]): array
    {
        $startTime = microtime(true);

        $this->logger->info('Starting DSL rule compilation', [
            'profile_length' => strlen($profileYaml),
            'examples_pos' => count($examples['pos']),
            'examples_neg' => count($examples['neg']),
        ]);

        $result = $this->compileWithRetry($profileYaml, $examples);

        $latencyMs = (int) ((microtime(true) - $startTime) * 1000);

        $this->logger->info('DSL rule compilation completed', [
            'rules_count' => $result['rules_count'],
            'attempts' => $result['attempts'],
            'latency_ms' => $latencyMs,
        ]);

        return $result;
    }

    /**
     * Compile avec retry logic et exponential backoff.
     *
     * @param array{pos: array<int, mixed>, neg: array<int, mixed>} $examples
     *
     * @throws \RuntimeException Si toutes les tentatives échouent
     *
     * @return array{rules_dsl: string, rules_count: int, attempts: int}
     */
    private function compileWithRetry(string $profileYaml, array $examples): array
    {
        $lastException = null;

        for ($attempt = 1; $attempt <= self::MAX_RETRIES; $attempt++) {
            try {
                $this->logger->debug("Compilation attempt {$attempt}/" . self::MAX_RETRIES);

                // 1. Construire les prompts
                $prompts = $this->promptBuilder->buildRuleCompilerPrompts($profileYaml, $examples);

                // 2. Appel LLM
                $messages = [
                    ['role' => 'system', 'content' => $prompts['system']],
                    ['role' => 'user', 'content' => $prompts['user']],
                ];

                $response = $this->llmClient->chat($messages, [
                    'temperature' => 0.2, // Très basse température pour syntaxe stricte
                    'max_tokens' => 1000,
                    'timeout' => self::TIMEOUT_SEC,
                ]);

                // 3. Extraire DSL depuis la réponse (peut contenir markdown)
                $dslText = $this->extractDSL($response);

                // 4. Valider syntaxe DSL
                $this->validateDSL($dslText);

                // 5. Compter les règles
                $rulesCount = $this->countRules($dslText);

                $this->logger->info('DSL compilation succeeded', [
                    'attempt' => $attempt,
                    'rules_count' => $rulesCount,
                    'dsl_length' => strlen($dslText),
                ]);

                return [
                    'rules_dsl' => $dslText,
                    'rules_count' => $rulesCount,
                    'attempts' => $attempt,
                ];
            } catch (\Throwable $e) {
                $lastException = $e;

                $this->logger->warning("Compilation attempt {$attempt} failed", [
                    'attempt' => $attempt,
                    'error' => $e->getMessage(),
                ]);

                // Si ce n'est pas la dernière tentative, attendre avec backoff
                if ($attempt < self::MAX_RETRIES) {
                    $delay = self::BACKOFF_DELAYS[$attempt - 1];
                    $this->logger->debug("Retrying in {$delay}s...");
                    sleep($delay);
                }
            }
        }

        // Toutes les tentatives ont échoué
        /** @var \Throwable $lastException */
        throw new \RuntimeException(
            'DSL compilation failed after ' . self::MAX_RETRIES . ' attempts: ' . $lastException->getMessage(),
            previous: $lastException
        );
    }

    /**
     * Extrait le DSL depuis une réponse LLM (peut contenir markdown).
     */
    private function extractDSL(string $response): string
    {
        $response = trim($response);

        // Cas 1: DSL dans code block markdown
        if (preg_match('/```(?:dsl)?\s*(RULE\s+.*?)```/s', $response, $matches)) {
            return trim($matches[1]);
        }

        // Cas 2: DSL brut (commence par "RULE")
        if (preg_match('/(RULE\s+.*)/s', $response, $matches)) {
            return trim($matches[1]);
        }

        throw new \RuntimeException('No valid DSL found in LLM response');
    }

    /**
     * Valide la syntaxe DSL.
     *
     * @throws \RuntimeException Si la syntaxe est invalide
     */
    private function validateDSL(string $dslText): void
    {
        // 1. Vérifier structure générale RULE { WHERE ... ACTION ... }
        if (!preg_match('/RULE\s+[\w.]+\s*\{.*WHERE\s+.*ACTION\s+.*\}/s', $dslText)) {
            throw new \RuntimeException('Invalid DSL structure: expected RULE { WHERE ... ACTION ... }');
        }

        // 2. Extraire toutes les règles
        preg_match_all('/RULE\s+([\w.]+)\s*\{(.*?)\}/s', $dslText, $matches, PREG_SET_ORDER);

        if ($matches === []) {
            throw new \RuntimeException('No valid RULE blocks found');
        }

        foreach ($matches as $match) {
            $ruleName = $match[1];
            $ruleBody = $match[2];

            // 3. Vérifier présence WHERE
            if (!preg_match('/WHERE\s+/i', $ruleBody)) {
                throw new \RuntimeException("Missing WHERE clause in rule: {$ruleName}");
            }

            // 4. Vérifier présence ACTION
            if (!preg_match('/ACTION\s+/i', $ruleBody)) {
                throw new \RuntimeException("Missing ACTION clause in rule: {$ruleName}");
            }

            // 5. Extraire les champs utilisés
            preg_match_all('/\b(' . implode('|', array_map('preg_quote', self::DSL_ALLOWED_FIELDS)) . ')\b/', $ruleBody, $fieldMatches);

            // 6. Vérifier qu'au moins un champ est utilisé
            if (empty($fieldMatches[0])) {
                throw new \RuntimeException("No valid fields found in rule: {$ruleName}");
            }

            // 7. Vérifier qu'au moins un opérateur est utilisé
            $hasOperator = false;

            foreach (self::DSL_ALLOWED_OPERATORS as $op) {
                if (str_contains($ruleBody, $op)) {
                    $hasOperator = true;

                    break;
                }
            }

            if (!$hasOperator) {
                throw new \RuntimeException("No valid operators found in rule: {$ruleName}");
            }
        }

        $this->logger->debug('DSL validation passed', [
            'rules_count' => count($matches),
        ]);
    }

    /**
     * Compte le nombre de règles dans le DSL.
     */
    private function countRules(string $dslText): int
    {
        preg_match_all('/RULE\s+[\w.]+\s*\{/i', $dslText, $matches);

        return count($matches[0]);
    }

    /**
     * Génère des tests automatiques pour les règles DSL.
     *
     * @param string                                                $rulesDsl Règles DSL générées
     * @param array{pos: array<int, mixed>, neg: array<int, mixed>} $examples Exemples utilisés pour la génération
     *
     * @return array{test_cases: array<int, array{description: string, input: array<string, mixed>, expected: bool}>}
     */
    public function generateTests(string $rulesDsl, array $examples): array
    {
        $testCases = [];

        // Tests positifs (doivent matcher)
        foreach ($examples['pos'] as $i => $ex) {
            /** @var array<string, mixed> $ex */
            $testCases[] = [
                'description' => "Positive example {$i} should match",
                'input' => [
                    'subject' => $ex['subject'] ?? '',
                    'body' => $ex['body'] ?? '',
                    'dkim' => $ex['dkim'] ?? null,
                ],
                'expected' => true,
            ];
        }

        // Tests négatifs (ne doivent PAS matcher)
        foreach ($examples['neg'] as $i => $ex) {
            /** @var array<string, mixed> $ex */
            $testCases[] = [
                'description' => "Negative example {$i} should NOT match",
                'input' => [
                    'subject' => $ex['subject'] ?? '',
                    'body' => $ex['body'] ?? '',
                    'dkim' => $ex['dkim'] ?? null,
                ],
                'expected' => false,
            ];
        }

        $this->logger->info('Generated test cases', [
            'test_count' => count($testCases),
            'positive' => count($examples['pos']),
            'negative' => count($examples['neg']),
        ]);

        return ['test_cases' => $testCases];
    }
}
