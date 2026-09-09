<?php

namespace App\Services;

/** Stable occurrence paths distinguish reused definitions and repeated local step keys. */
class WorkflowSnapshotIdentity
{
    public static function annotate(array $snapshot, array $path = []): array
    {
        $snapshot['graph_path'] = $path;
        foreach ($snapshot['children'] ?? [] as $key => $child) {
            $snapshot['children'][$key] = self::annotate($child, [...$path, 'workflow:'.$key]);
        }
        foreach ($snapshot['controls'] ?? [] as $key => $bodies) {
            foreach ($bodies as $index => $body) {
                $snapshot['controls'][$key][$index] = self::annotate($body, [...$path, 'control:'.$key.':'.$index]);
            }
        }

        return $snapshot;
    }

    public static function entries(array $snapshot): array
    {
        $snapshot = self::annotate($snapshot, $snapshot['graph_path'] ?? []);
        $entries = [$snapshot];
        foreach ($snapshot['children'] ?? [] as $child) {
            $entries = array_merge($entries, self::entries($child));
        }
        foreach ($snapshot['controls'] ?? [] as $bodies) {
            foreach ($bodies as $body) {
                $entries = array_merge($entries, self::entries($body));
            }
        }

        return $entries;
    }

    public static function steps(array $snapshot): array
    {
        $steps = [];
        foreach (self::entries($snapshot) as $entry) {
            foreach ($entry['definition']['steps'] ?? $entry['steps'] ?? [] as $step) {
                $steps[] = array_merge($step, ['_definition_id' => $entry['definition_id'] ?? null, '_graph_path' => $entry['graph_path']]);
            }
        }

        return $steps;
    }
}
