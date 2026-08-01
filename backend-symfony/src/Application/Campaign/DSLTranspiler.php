<?php

declare(strict_types=1);

namespace App\Application\Campaign;

use Psr\Log\LoggerInterface;

final readonly class DSLTranspiler
{
    public function __construct(
        private LoggerInterface $logger
    ) {
    }

    /**
     * Transpile MailGuard DSL to PostgreSQL SQL with prepared statements.
     *
     * @throws \RuntimeException if parsing fails
     *
     * @return array{sql: string, params: array<string, mixed>, tests: array<string>}
     */
    public function transpile(string $dsl): array
    {
        $this->logger->info('Transpiling DSL to SQL', [
            'dsl_length' => mb_strlen($dsl),
        ]);

        // 1. Parse DSL
        $parsed = $this->parseDSL($dsl);

        // 2. Generate SQL with params
        $compiled = $this->generateSQL($parsed);

        $this->logger->info('DSL transpiled successfully', [
            'sql_length' => mb_strlen($compiled['sql']),
            'params_count' => count($compiled['params']),
            'predicates_count' => count($parsed['predicates']),
        ]);

        return [
            'sql' => $compiled['sql'],
            'params' => $compiled['params'],
            'tests' => [],
        ];
    }

    /**
     * Parses DSL into a simplified AST.
     *
     * @return array{predicates: array<int, array<string, mixed>>}
     */
    private function parseDSL(string $dsl): array
    {
        // Extract WHERE clause
        if (!preg_match('/WHERE\s+(.+?)\s+ACTION/s', $dsl, $whereMatch)) {
            throw new \RuntimeException('DSL parsing failed: WHERE clause not found');
        }

        $whereClause = $whereMatch[1];

        // Split on AND (MVP: no OR/NOT support)
        $split = preg_split('/\s+AND\s+/i', $whereClause);

        if ($split === false) {
            throw new \RuntimeException('Failed to split WHERE clause');
        }
        $predicates = array_filter(array_map('trim', $split));

        $parsed = ['predicates' => []];

        foreach ($predicates as $predicate) {
            $parsed['predicates'][] = $this->parsePredicate($predicate);
        }

        return $parsed;
    }

    /**
     * Parses an individual predicate.
     *
     * @return array{type: string, field?: string, value?: mixed, operator?: string}
     */
    private function parsePredicate(string $predicate): array
    {
        // subject.simhash≈"avis important" ±15%
        if (preg_match('/subject\.simhash≈"([^"]+)"\s*±(\d+)%/', $predicate, $m)) {
            return [
                'type' => 'simhash',
                'field' => 'subject',
                'value' => $m[1],
                'tolerance' => (int) $m[2],
            ];
        }

        // subject.containsAny [...] OR body.containsAny [...]
        if (preg_match('/(subject|body)\.containsAny\s*\[([^\]]+)\]/', $predicate, $m)) {
            $field = $m[1];
            $terms = array_map(
                fn ($t): string => trim((string) $t, '"\''),
                array_map('trim', explode(',', $m[2]))
            );

            return [
                'type' => 'containsAny',
                'field' => $field,
                'values' => $terms,
            ];
        }

        // url.domain.age < 14d
        if (preg_match('/url\.domain\.age\s*(<|>|<=|>=)\s*(\d+)d/', $predicate, $m)) {
            return [
                'type' => 'domain_age',
                'operator' => $m[1],
                'value' => (int) $m[2],
            ];
        }

        // sender.display_name.fuzzy ∈ {...}
        if (preg_match('/sender\.display_name\.fuzzy\s*∈\s*\{([^}]+)\}/', $predicate, $m)) {
            $names = array_map(
                fn ($n): string => trim((string) $n, '"\''),
                array_map('trim', explode(',', $m[1]))
            );

            return [
                'type' => 'sender_fuzzy',
                'values' => $names,
            ];
        }

        // dkim.pass ∈ {false, null}
        if (preg_match('/dkim\.pass\s*∈\s*\{([^}]+)\}/', $predicate, $m)) {
            return [
                'type' => 'dkim',
                'values' => array_map('trim', explode(',', $m[1])),
            ];
        }

        // spf.pass ∈ {false, null}
        if (preg_match('/spf\.pass\s*∈\s*\{([^}]+)\}/', $predicate, $m)) {
            return [
                'type' => 'spf',
                'values' => array_map('trim', explode(',', $m[1])),
            ];
        }

        throw new \RuntimeException('Unsupported predicate: ' . $predicate);
    }

    /**
     * Generates SQL from AST with parameterized queries.
     *
     * @param array{predicates: array<int, array<string, mixed>>} $parsed
     *
     * @return array{sql: string, params: array<string, mixed>}
     */
    private function generateSQL(array $parsed): array
    {
        $sqlClauses = [];
        /** @var array<string, mixed> $params */
        $params = [];
        $paramIndex = 0;

        foreach ($parsed['predicates'] as $pred) {
            $clause = $this->generateSQLForPredicate($pred, $params, $paramIndex);
            $sqlClauses[] = $clause;
        }

        if ($sqlClauses === []) {
            throw new \RuntimeException('No SQL clauses generated');
        }

        $sql = sprintf(
            'SELECT msg_id, subject, body_text, ts_msg FROM message WHERE %s ORDER BY ts_msg DESC LIMIT 100',
            implode(' AND ', $sqlClauses)
        );

        return [
            'sql' => $sql,
            'params' => $params,
        ];
    }

    /**
     * Generates SQL for a predicate with params.
     *
     * @param array<string, mixed> $pred
     * @param array<string, mixed> $params Modified by reference
     * @param int                  $index  Modified by reference
     */
    private function generateSQLForPredicate(array $pred, array &$params, int &$index): string
    {
        /** @var string $type */
        $type = $pred['type'];

        return match ($type) {
            'simhash' => $this->generateSimhashSQL($pred, $params, $index),
            'containsAny' => $this->generateContainsAnySQL($pred, $params, $index),
            'domain_age' => $this->generateDomainAgeSQL($pred, $params, $index),
            'sender_fuzzy' => $this->generateSenderFuzzySQL($pred, $params, $index),
            'dkim' => "(headers->'auth'->>'dkim')::bool IS NOT TRUE",
            'spf' => "(headers->'auth'->>'spf')::bool IS NOT TRUE",
            default => throw new \RuntimeException('Unknown predicate type: ' . $type),
        };
    }

    /**
     * @param array<string, mixed> $pred
     * @param array<string, mixed> $params
     */
    private function generateSimhashSQL(array $pred, array &$params, int &$index): string
    {
        $paramKey = 'p' . $index++;
        $params[$paramKey] = $pred['value'];

        /** @var int $tolerance */
        $tolerance = $pred['tolerance'];
        $threshold = 1.0 - ((float) $tolerance / 100.0);

        return sprintf('similarity(subject, :%s) >= %f', $paramKey, $threshold);
    }

    /**
     * @param array<string, mixed> $pred
     * @param array<string, mixed> $params
     */
    private function generateContainsAnySQL(array $pred, array &$params, int &$index): string
    {
        $patterns = [];

        /** @var array<int, string> $values */
        $values = $pred['values'];

        foreach ($values as $value) {
            $paramKey = 'p' . $index++;
            $params[$paramKey] = '%' . $value . '%';
            $patterns[] = ':' . $paramKey;
        }

        // Determine SQL column based on field (subject or body)
        /** @var string $field */
        $field = $pred['field'];
        $column = match($field) {
            'subject' => 'subject',
            'body' => 'body_text',
            default => throw new \RuntimeException('Unsupported field for containsAny: ' . $field)
        };

        return sprintf('%s ILIKE ANY(ARRAY[%s])', $column, implode(',', $patterns));
    }

    /**
     * @param array<string, mixed> $pred
     * @param array<string, mixed> $params
     */
    private function generateDomainAgeSQL(array $pred, array &$params, int &$index): string
    {
        $paramKey = 'p' . $index++;
        $params[$paramKey] = $pred['value'];

        /** @var string $operator */
        $operator = $pred['operator'];

        return sprintf(
            "(headers->'url_meta'->>'age_days')::int %s :%s",
            $operator,
            $paramKey
        );
    }

    /**
     * @param array<string, mixed> $pred
     * @param array<string, mixed> $params
     */
    private function generateSenderFuzzySQL(array $pred, array &$params, int &$index): string
    {
        $conditions = [];

        /** @var array<int, string> $names */
        $names = $pred['values'];

        foreach ($names as $name) {
            $paramKey = 'p' . $index++;
            $params[$paramKey] = $name;
            $conditions[] = sprintf("similarity(headers->>'from_display', :%s) >= 0.7", $paramKey);
        }

        return '(' . implode(' OR ', $conditions) . ')';
    }
}
