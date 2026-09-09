<?php

namespace App\Services;

/** Literal, versioned project environment; execution never grants global package installation. */
class WorkflowScriptEnvironment
{
    public static function assertLiteral(mixed $value): void
    {
        if (! is_array($value)) {
            return;
        }
        abort_if(array_key_exists('$ref', $value), 422, 'Bindings cannot change the approved script environment.');
        foreach ($value as $child) {
            self::assertLiteral($child);
        }
    }

    public static function validate(string $task, mixed $value): void
    {
        self::assertLiteral($value);
        abort_unless(is_array($value) && ! array_is_list($value) && ($value['version'] ?? null) === 1
            && array_diff(array_keys($value), ['version', 'runtime_version', 'dependencies', 'lock_path', 'lock_sha256']) === []
            && is_string($value['runtime_version'] ?? null) && preg_match('/^[0-9]+\.[0-9]+\.[0-9]+$/D', $value['runtime_version'])
            && is_array($value['dependencies'] ?? null) && array_is_list($value['dependencies']) && count($value['dependencies']) <= 64,
            422, 'workflow_script_environment_invalid');
        $names = [];
        foreach ($value['dependencies'] as $dependency) {
            $pattern = $task === 'node.run' ? '/^(?:@[a-z0-9][a-z0-9._-]*\/)?[a-z0-9][a-z0-9._-]*$/D' : '/^[A-Za-z0-9][A-Za-z0-9._-]*$/D';
            abort_unless(is_array($dependency) && array_diff(array_keys($dependency), ['name', 'version']) === []
                && is_string($dependency['name'] ?? null) && strlen($dependency['name']) <= 160 && preg_match($pattern, $dependency['name'])
                && is_string($dependency['version'] ?? null) && strlen($dependency['version']) <= 60
                && preg_match('/^(?:0|[1-9][0-9]*)\.(?:0|[1-9][0-9]*)\.(?:0|[1-9][0-9]*)(?:[-+][A-Za-z0-9.-]+)?$/D', $dependency['version']),
                422, 'workflow_script_dependency_invalid');
            $name = $task === 'python.run' ? preg_replace('/[_.-]+/', '-', strtolower($dependency['name'])) : $dependency['name'];
            abort_if(isset($names[$name]), 422, 'workflow_script_dependency_duplicate');
            $names[$name] = true;
        }
        if ($value['dependencies'] !== [] || array_key_exists('lock_path', $value) || array_key_exists('lock_sha256', $value)) {
            abort_unless(is_string($value['lock_path'] ?? null) && strlen($value['lock_path']) >= 1 && strlen($value['lock_path']) <= 500
                && is_string($value['lock_sha256'] ?? null) && preg_match('/^[a-f0-9]{64}$/D', $value['lock_sha256']), 422, 'workflow_script_lock_invalid');
            foreach (preg_split('/[\\\\\/]/', $value['lock_path']) as $part) {
                abort_if($part === '' || $part === '.' || $part === '..' || preg_match('/[:\x00-\x1f]/', $part), 422, 'workflow_script_lock_invalid');
            }
        }
    }
}
