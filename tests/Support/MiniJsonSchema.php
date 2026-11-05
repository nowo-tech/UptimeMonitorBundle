<?php

declare(strict_types=1);

namespace Nowo\UptimeMonitorBundle\Tests\Support;

use LogicException;
use stdClass;

use function array_key_exists;
use function array_values;
use function count;
use function get_object_vars;
use function in_array;
use function is_array;
use function is_bool;
use function is_float;
use function is_int;
use function is_string;
use function mb_strlen;
use function preg_match;
use function sprintf;
use function str_replace;
use function str_starts_with;
use function substr;

/**
 * The subset of JSON Schema draft 2020-12 that `apply-monitors.v1.json` uses, so the shipped schema can be checked
 * against fixtures without a schema library. A keyword outside the subset throws: if the schema grows one, the sync
 * test must grow with it instead of passing silently.
 */
final readonly class MiniJsonSchema
{
    private const KEYWORDS = [
        '$schema', '$id', 'title', 'description', '$defs', '$ref', 'type', 'const', 'required', 'properties',
        'additionalProperties', 'propertyNames', 'minProperties', 'maxProperties', 'items', 'maxItems', 'pattern',
        'minLength', 'maxLength', 'minimum', 'maximum',
    ];

    public function __construct(private stdClass $root)
    {
    }

    /**
     * Whether the document (decoded with objects as stdClass) is valid.
     */
    public function isValid(mixed $document): bool
    {
        return $this->check($this->root, $document);
    }

    private function check(stdClass $schema, mixed $value): bool
    {
        $rules = get_object_vars($schema);
        foreach ($rules as $keyword => $_) {
            if (!in_array($keyword, self::KEYWORDS, true)) {
                throw new LogicException(sprintf('Keyword "%s" is outside the tested subset.', $keyword));
            }
        }
        if (isset($rules['$ref'])) {
            return $this->check($this->resolve((string) $rules['$ref']), $value);
        }
        if (array_key_exists('type', $rules) && !$this->hasType($value, array_values((array) $rules['type']))) {
            return false;
        }
        if (array_key_exists('const', $rules) && $value !== $rules['const']) {
            return false;
        }
        if ($value instanceof stdClass) {
            return $this->checkObject($rules, $value);
        }
        if (is_array($value)) {
            if (isset($rules['maxItems']) && count($value) > $rules['maxItems']) {
                return false;
            }
            foreach ($value as $item) {
                if (isset($rules['items']) && !$this->check($rules['items'], $item)) {
                    return false;
                }
            }

            return true;
        }
        if (is_string($value)) {
            return (!isset($rules['pattern']) || preg_match('/' . str_replace('/', '\/', (string) $rules['pattern']) . '/u', $value) === 1)
                && (!isset($rules['minLength']) || mb_strlen($value) >= $rules['minLength'])
                && (!isset($rules['maxLength']) || mb_strlen($value) <= $rules['maxLength']);
        }
        if (is_int($value) || is_float($value)) {
            return (!isset($rules['minimum']) || $value >= $rules['minimum'])
                && (!isset($rules['maximum']) || $value <= $rules['maximum']);
        }

        return true;
    }

    /**
     * @param array<string, mixed> $rules
     */
    private function checkObject(array $rules, stdClass $value): bool
    {
        $fields = get_object_vars($value);
        foreach ((array) ($rules['required'] ?? []) as $required) {
            if (!array_key_exists($required, $fields)) {
                return false;
            }
        }
        if (isset($rules['minProperties']) && count($fields) < $rules['minProperties']) {
            return false;
        }
        if (isset($rules['maxProperties']) && count($fields) > $rules['maxProperties']) {
            return false;
        }
        $properties = isset($rules['properties']) ? get_object_vars($rules['properties']) : [];
        foreach ($fields as $name => $field) {
            $name = (string) $name;
            if (isset($rules['propertyNames']) && !$this->check($rules['propertyNames'], $name)) {
                return false;
            }
            if (array_key_exists($name, $properties)) {
                if (!$this->check($properties[$name], $field)) {
                    return false;
                }
                continue;
            }
            $additional = $rules['additionalProperties'] ?? true;
            if ($additional === false || ($additional instanceof stdClass && !$this->check($additional, $field))) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param list<mixed> $types
     */
    private function hasType(mixed $value, array $types): bool
    {
        foreach ($types as $type) {
            $matches = match ($type) {
                'object'  => $value instanceof stdClass,
                'array'   => is_array($value),
                'string'  => is_string($value),
                'integer' => is_int($value),
                'number'  => is_int($value) || is_float($value),
                'boolean' => is_bool($value),
                'null'    => $value === null,
                default   => throw new LogicException(sprintf('Type "%s" is outside the tested subset.', $type)),
            };
            if ($matches) {
                return true;
            }
        }

        return false;
    }

    private function resolve(string $ref): stdClass
    {
        if (!str_starts_with($ref, '#/$defs/')) {
            throw new LogicException('Only local $defs references are supported.');
        }
        $definition = $this->root->{'$defs'}->{substr($ref, 8)} ?? null;
        if (!$definition instanceof stdClass) {
            throw new LogicException(sprintf('Unknown reference "%s".', $ref));
        }

        return $definition;
    }
}
