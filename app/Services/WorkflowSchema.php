<?php

namespace App\Services;

/** Deliberately bounded JSON Schema subset; unsupported keywords fail validation. */
class WorkflowSchema
{
    public static function supported(array $schema, int $depth = 0): void
    {
        abort_if($depth > 16 || count($schema) > 30, 422, 'workflow_schema_depth');
        abort_if(array_diff(array_keys($schema), ['type', 'properties', 'required', 'additionalProperties', 'items', 'enum', 'minimum', 'maximum', 'minItems', 'maxItems', 'minLength', 'maxLength', 'default', 'description', 'title']) !== [], 422, 'workflow_schema_keyword_unsupported');
        abort_if(isset($schema['type']) && ! in_array($schema['type'], ['object', 'array', 'string', 'integer', 'number', 'boolean', 'null'], true), 422, 'workflow_schema_type_unsupported');
        if (isset($schema['properties'])) {
            abort_unless(is_array($schema['properties']) && count($schema['properties']) <= 100, 422, 'workflow_schema_properties_invalid');
            foreach ($schema['properties'] as $property) {
                abort_unless(is_array($property), 422, 'workflow_schema_property_invalid');
                self::supported($property, $depth + 1);
            }
        }
        if (isset($schema['items'])) {
            abort_unless(is_array($schema['items']), 422, 'workflow_schema_items_invalid');
            self::supported($schema['items'], $depth + 1);
        }
        if (isset($schema['required'])) {
            abort_unless(is_array($schema['required']) && count($schema['required']) <= 100, 422, 'workflow_schema_required_invalid');
            foreach ($schema['required'] as $key) {
                abort_unless(is_string($key) && strlen($key) <= 120, 422, 'workflow_schema_required_invalid');
            }
        }
    }

    public static function validate(mixed $value, array $schema, string $path = '$', bool $allowReferences = false, int $depth = 0): void
    {
        if ($depth === 0) {
            self::supported($schema);
        }
        abort_if($depth > 16, 422, 'workflow_schema_depth');
        abort_if(array_diff(array_keys($schema), ['type', 'properties', 'required', 'additionalProperties', 'items', 'enum', 'minimum', 'maximum', 'minItems', 'maxItems', 'minLength', 'maxLength', 'default', 'description', 'title']) !== [], 422, 'workflow_schema_keyword_unsupported');
        if ($allowReferences && is_array($value) && array_keys($value) === ['$ref']) {
            return;
        }
        $type = $schema['type'] ?? null;
        $valid = match ($type) {
            null => true, 'object' => is_array($value) && ($value === [] || ! array_is_list($value)),
            'array' => is_array($value) && array_is_list($value), 'string' => is_string($value),
            'integer' => is_int($value), 'number' => is_int($value) || is_float($value),
            'boolean' => is_bool($value), 'null' => $value === null, default => false,
        };
        abort_unless($valid, 422, 'workflow_schema_type:'.$path);
        if (isset($schema['enum'])) {
            abort_unless(is_array($schema['enum']) && in_array($value, $schema['enum'], true), 422, 'workflow_schema_enum:'.$path);
        }
        if ($type === 'object') {
            foreach ($schema['required'] ?? [] as $key) {
                abort_unless(is_string($key) && array_key_exists($key, $value), 422, 'workflow_schema_required:'.$path);
            }
            foreach ($value as $key => $child) {
                if (isset($schema['properties'][$key])) {
                    self::validate($child, $schema['properties'][$key], $path.'.'.$key, $allowReferences, $depth + 1);
                } elseif (($schema['additionalProperties'] ?? true) === false) {
                    abort(422, 'workflow_schema_additional_property:'.$path);
                }
            }
        }
        if ($type === 'array') {
            abort_if(count($value) < ($schema['minItems'] ?? 0) || count($value) > ($schema['maxItems'] ?? 10000), 422, 'workflow_schema_array_size:'.$path);
            foreach ($value as $index => $child) {
                if (isset($schema['items'])) {
                    self::validate($child, $schema['items'], $path.'.'.$index, $allowReferences, $depth + 1);
                }
            }
        }
        if (is_string($value)) {
            abort_if(mb_strlen($value) < ($schema['minLength'] ?? 0) || mb_strlen($value) > ($schema['maxLength'] ?? 200000), 422, 'workflow_schema_string_size:'.$path);
        }
        if (is_int($value) || is_float($value)) {
            abort_if($value < ($schema['minimum'] ?? -INF) || $value > ($schema['maximum'] ?? INF), 422, 'workflow_schema_range:'.$path);
        }
    }
}
