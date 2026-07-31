<?php

declare(strict_types=1);

namespace App\Application\Stix;

/**
 * Minimal JSON Schema validator for the two custom STIX
 * extensions we author (x_scambuster_context, x_scambuster_mirror).
 *
 * Supports the subset of JSON Schema we need: type (incl. arrays of types),
 * required, properties, enum, additionalProperties (true|false). No $ref,
 * no oneOf/allOf, no format/regex — small enough to hand-roll, large
 * enough to catch the regressions we care about (a missing required key
 * after a spec adds one, a renamed key creeping in).
 *
 * Intended caller: ExportIocsStixController / ConversationStixExportHandler
 * + TaxiiService in test environments (APP_ENV=test). Production exports
 * skip validation to avoid the per-bundle parse cost. The test gate is the
 * enforcement point.
 */
final class ExtensionSchemaValidator
{
    private const SCHEMA_DIR = __DIR__ . '/../../../config/stix-schemas';

    /** @var array<string, array<string, mixed>> */
    private array $schemaCache = [];

    /**
     * Validate a STIX bundle: every indicator.extensions.x_scambuster_context
     * + every note.x_scambuster_mirror must conform to its schema. Returns
     * a (possibly empty) list of human-readable violation strings.
     *
     * @param array<string, mixed> $bundle
     *
     * @return list<string>
     */
    public function validateBundle(array $bundle): array
    {
        $errors = [];
        $objects = \is_array($bundle['objects'] ?? null) ? $bundle['objects'] : [];

        foreach ($objects as $index => $obj) {
            if (!\is_array($obj)) {
                continue;
            }

            $type = \is_string($obj['type'] ?? null) ? $obj['type'] : '';

            if ($type === 'indicator') {
                // x_scambuster_context now lives under its extension-definition id.
                $ctx = $this->extractAssocArray($obj['extensions'][ScambusterStixExtensions::CONTEXT_ID]['x_scambuster_context'] ?? null);

                if ($ctx !== null) {
                    foreach ($this->validate($ctx, 'x_scambuster_context') as $err) {
                        $errors[] = sprintf('objects[%d] (indicator) x_scambuster_context: %s', $index, $err);
                    }
                }
            }

            if ($type === 'note') {
                $mirror = $this->extractAssocArray($obj['x_scambuster_mirror'] ?? null);

                if ($mirror !== null) {
                    foreach ($this->validate($mirror, 'x_scambuster_mirror') as $err) {
                        $errors[] = sprintf('objects[%d] (note) x_scambuster_mirror: %s', $index, $err);
                    }
                }
            }

            if ($type === 'sighting') {
                // x_scambuster_ttp_sighting lives under its extension-definition id.
                $ttpSighting = $this->extractAssocArray($obj['extensions'][ScambusterStixExtensions::TTP_SIGHTING_ID]['x_scambuster_ttp_sighting'] ?? null);

                if ($ttpSighting !== null) {
                    foreach ($this->validate($ttpSighting, 'x_scambuster_ttp_sighting') as $err) {
                        $errors[] = sprintf('objects[%d] (sighting) x_scambuster_ttp_sighting: %s', $index, $err);
                    }
                }
            }
        }

        return $errors;
    }

    /**
     * Validate one extension payload against a named schema.
     *
     * @param array<string, mixed> $data
     *
     * @return list<string>
     */
    public function validate(array $data, string $schemaName): array
    {
        $schema = $this->loadSchema($schemaName);

        return $this->validateNode($data, $schema, '');
    }

    /**
     * @param array<string, mixed>|mixed $data
     * @param array<string, mixed>       $schema
     *
     * @return list<string>
     */
    private function validateNode(mixed $data, array $schema, string $path): array
    {
        $errors = [];

        $expectedType = $schema['type'] ?? null;

        if ($expectedType !== null && !$this->matchesType($data, $expectedType)) {
            $errors[] = sprintf('%s expected type %s, got %s', $path === '' ? '<root>' : $path, $this->describeType($expectedType), get_debug_type($data));

            return $errors;
        }

        $enum = $schema['enum'] ?? null;

        if (\is_array($enum) && !\in_array($data, $enum, true)) {
            $errors[] = sprintf('%s value %s not in enum %s', $path === '' ? '<root>' : $path, json_encode($data), json_encode($enum));
        }

        if ($expectedType === 'object' && \is_array($data)) {
            $required = $schema['required'] ?? [];

            if (\is_array($required)) {
                foreach ($required as $key) {
                    if (!\is_string($key)) {
                        continue;
                    }

                    if (!\array_key_exists($key, $data)) {
                        $errors[] = sprintf('%s missing required key "%s"', $path === '' ? '<root>' : $path, $key);
                    }
                }
            }

            $properties = $schema['properties'] ?? [];
            $additional = $schema['additionalProperties'] ?? true;

            if (\is_array($properties)) {
                foreach ($data as $key => $value) {
                    if (!\is_string($key)) {
                        continue;
                    }

                    $childPath = $path === '' ? $key : ($path . '.' . $key);

                    $propertySchema = $this->extractAssocArray($properties[$key] ?? null);

                    if ($propertySchema !== null) {
                        foreach ($this->validateNode($value, $propertySchema, $childPath) as $childErr) {
                            $errors[] = $childErr;
                        }

                        continue;
                    }

                    if ($additional === false) {
                        $errors[] = sprintf('%s unexpected additional key "%s"', $path === '' ? '<root>' : $path, $key);
                    }
                }
            }
        }

        return $errors;
    }

    private function matchesType(mixed $data, mixed $expected): bool
    {
        if (\is_array($expected)) {
            foreach ($expected as $candidate) {
                if (\is_string($candidate) && $this->matchesSingleType($data, $candidate)) {
                    return true;
                }
            }

            return false;
        }

        return \is_string($expected) && $this->matchesSingleType($data, $expected);
    }

    private function matchesSingleType(mixed $data, string $expected): bool
    {
        return match ($expected) {
            'object' => \is_array($data) && (empty($data) || !array_is_list($data)),
            'array' => \is_array($data) && (empty($data) || array_is_list($data)),
            'string' => \is_string($data),
            'integer' => \is_int($data),
            'number' => \is_int($data) || \is_float($data),
            'boolean' => \is_bool($data),
            'null' => $data === null,
            default => false,
        };
    }

    private function describeType(mixed $type): string
    {
        return \is_array($type) ? implode('|', array_filter($type, 'is_string')) : (\is_string($type) ? $type : 'unknown');
    }

    /**
     * Narrow a mixed value to array<string, mixed> for PHPStan. Returns
     * null when the input is not an associative array we can validate.
     *
     * @return array<string, mixed>|null
     */
    private function extractAssocArray(mixed $value): ?array
    {
        if (!\is_array($value)) {
            return null;
        }

        $result = [];

        foreach ($value as $k => $v) {
            if (!\is_string($k)) {
                return null;
            }
            $result[$k] = $v;
        }

        return $result;
    }

    /**
     * @return array<string, mixed>
     */
    private function loadSchema(string $name): array
    {
        if (isset($this->schemaCache[$name])) {
            return $this->schemaCache[$name];
        }

        $path = self::SCHEMA_DIR . '/' . $name . '.schema.json';

        if (!is_file($path)) {
            throw new \RuntimeException(sprintf('STIX extension schema "%s" not found at %s', $name, $path));
        }

        $raw = file_get_contents($path);

        if ($raw === false) {
            throw new \RuntimeException(sprintf('Failed to read STIX extension schema "%s"', $name));
        }

        $decoded = json_decode($raw, true);

        if (!\is_array($decoded)) {
            throw new \RuntimeException(sprintf('STIX extension schema "%s" did not parse to an array', $name));
        }

        /** @var array<string, mixed> $decoded */
        $this->schemaCache[$name] = $decoded;

        return $decoded;
    }
}
