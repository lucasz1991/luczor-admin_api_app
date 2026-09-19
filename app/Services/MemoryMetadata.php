<?php

namespace App\Services;

use App\Models\MemoryLink;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/** Versioned retrieval annotations. Authority, ownership and content stay on the canonical memory. */
final class MemoryMetadata
{
    public const VERSION = 1;

    public const POLICY = 'memory-metadata-v1';

    public const KINDS = ['unknown', 'fact', 'preference', 'decision', 'rule', 'hypothesis', 'observation'];

    public const OVERRIDES = ['kind', 'interest', 'categories', 'tags', 'importance'];

    public static function normalizeInput(array $data): array
    {
        $data['_importance_provided'] ??= isset($data['importance']) || isset($data['priority']);
        // Server-owned scheduling state is never accepted as client evidence.
        if (is_array($data['provenance'] ?? null)) {
            unset($data['provenance']['memory_metadata_input_revision']);
        }
        if (is_array($data['meta'] ?? null) && array_key_exists('memory_metadata', $data['meta'])) {
            $data['meta']['memory_metadata'] = $data['meta']['memory_metadata'] === null
                ? null : self::normalize($data['meta']['memory_metadata']);
        }
        if (isset($data['tags'])) {
            $tags = Validator::make(['tags' => $data['tags']], ['tags' => ['array', 'max:32'], 'tags.*' => ['string', 'max:80']])->validate()['tags'];
            $data['meta'] = array_replace($data['meta'] ?? [], ['tags' => $tags]);
        }
        if (is_array($data['meta'] ?? null) && array_key_exists('tags', $data['meta'])) {
            Validator::make(['tags' => $data['meta']['tags']], ['tags' => ['array', 'max:32'], 'tags.*' => ['required', 'string', 'max:80']])->validate();
        }

        return $data;
    }

    public static function normalize(mixed $value): array
    {
        $rules = [
            'value' => ['required', 'array:version,kind,interest,categories,files,evidence,classification,overrides'],
            'value.version' => ['required', Rule::in([1])],
            'value.kind' => ['required', Rule::in(self::KINDS)],
            'value.interest' => ['present', 'nullable', 'numeric', 'min:0', 'max:1'],
            'value.categories' => ['present', 'array', 'max:8'],
            'value.categories.*' => ['array:id,path'],
            'value.categories.*.id' => ['required', 'string', 'max:4096'],
            'value.categories.*.path' => ['required', 'array', 'min:1', 'max:4'],
            'value.categories.*.path.*' => ['required', 'string', 'max:80'],
            'value.files' => ['present', 'array', 'max:32'],
            'value.files.*' => ['array:projectId,repositoryId,path,relation,revision,verifiedAt'],
            'value.files.*.projectId' => ['sometimes', 'string', 'max:512'],
            'value.files.*.repositoryId' => ['sometimes', 'string', 'max:512'],
            'value.files.*.path' => ['required', 'string', 'max:2048'],
            'value.files.*.relation' => ['required', Rule::in(['mentioned', 'related', 'evidence'])],
            'value.files.*.revision' => ['sometimes', 'string', 'max:512'],
            'value.files.*.verifiedAt' => ['sometimes', 'integer', 'min:0'],
            'value.evidence' => ['required', 'array:status,verifiedAt,sources'],
            'value.evidence.status' => ['required', Rule::in(['unknown', 'user_stated', 'inferred', 'source_backed', 'conflicting', 'stale'])],
            'value.evidence.verifiedAt' => ['present', 'nullable', 'integer', 'min:0'],
            'value.evidence.sources' => ['present', 'array', 'max:32'],
            'value.evidence.sources.*' => ['array:kind,id,role,revision,conversationId,projectId,observedAt'],
            'value.evidence.sources.*.kind' => ['required', Rule::in(['chat', 'memory', 'file', 'user'])],
            'value.evidence.sources.*.id' => ['required', 'string', 'max:512'],
            'value.evidence.sources.*.role' => ['sometimes', Rule::in(['user', 'assistant', 'tool'])],
            'value.evidence.sources.*.revision' => ['sometimes', 'string', 'max:512'],
            'value.evidence.sources.*.conversationId' => ['sometimes', 'string', 'max:512'],
            'value.evidence.sources.*.projectId' => ['sometimes', 'string', 'max:512'],
            'value.evidence.sources.*.observedAt' => ['sometimes', 'integer', 'min:0'],
            'value.classification' => ['required', 'array:origin,updatedAt,policy,inputRevision,modelId'],
            'value.classification.origin' => ['required', Rule::in(['capture', 'dream', 'user'])],
            'value.classification.updatedAt' => ['required', 'integer', 'min:0'],
            'value.classification.policy' => ['required', Rule::in([self::POLICY])],
            'value.classification.inputRevision' => ['sometimes', 'string', 'max:512'],
            'value.classification.modelId' => ['sometimes', 'string', 'max:512'],
            'value.overrides' => ['present', 'array', 'max:5'],
            'value.overrides.*' => [Rule::in(self::OVERRIDES)],
        ];
        $metadata = Validator::make(['value' => $value], $rules)->validate()['value'];
        if (($metadata['version'] ?? null) !== 1 || ($metadata['interest'] !== null && ! is_int($metadata['interest']) && ! is_float($metadata['interest']))) {
            throw ValidationException::withMessages(['meta.memory_metadata' => 'Metadata numbers must be JSON numbers.']);
        }
        $metadata['overrides'] = array_values(array_unique($metadata['overrides']));
        self::validateStructure($metadata);
        foreach ([$metadata['categories'], $metadata['files'], $metadata['evidence']['sources'], $metadata['overrides']] as $list) {
            abort_unless(array_is_list($list), 422, 'Metadata lists must be JSON arrays.');
        }
        foreach ($metadata['categories'] as $category) {
            abort_unless(array_is_list($category['path']), 422, 'Category paths must be JSON arrays.');
        }
        $metadata['categories'] = array_values(array_column(array_map(fn (array $category) => self::category($category['path']), $metadata['categories']), null, 'id'));
        foreach ($metadata['files'] as $file) {
            if ($file['relation'] === 'evidence' && (! isset($file['revision'], $file['verifiedAt']) || trim($file['revision']) === '')) {
                throw ValidationException::withMessages(['meta.memory_metadata.files' => 'Evidence requires a checked revision and timestamp.']);
            }
        }
        if ($metadata['evidence']['status'] === 'source_backed' && ($metadata['evidence']['verifiedAt'] === null
            || ! collect($metadata['files'])->contains(fn ($file) => $file['relation'] === 'evidence'))) {
            throw ValidationException::withMessages(['meta.memory_metadata.evidence' => 'Source-backed evidence requires a checked file revision.']);
        }

        return $metadata;
    }

    public static function inputRevision(MemoryLink $link): string
    {
        $metadata = $link->meta['memory_metadata'] ?? self::initial($link);
        $overrides = $metadata['overrides'] ?? [];
        $manual = [];
        foreach ($overrides as $field) {
            $manual[$field] = match ($field) {
                'importance' => (float) $link->importance,
                'tags' => $link->meta['tags'] ?? [],
                default => $metadata[$field] ?? null,
            };
        }

        $provenance = $link->provenance ?? [];
        unset($provenance['captured_at'], $provenance['memory_metadata_input_revision']);

        return hash('sha256', json_encode([$link->content_hash, $link->scope, $link->project_id,
            $link->source_type, $link->source_ref, $link->confidence, $link->valid_from, $link->valid_until,
            $link->expires_at, $link->observed_at, $link->sensitivity, $link->retention, $provenance,
            $manual, $metadata['evidence'] ?? null, $metadata['files'] ?? []], JSON_THROW_ON_ERROR));
    }

    public static function invalidate(array $metadata): array
    {
        foreach (['kind' => 'unknown', 'interest' => null, 'categories' => []] as $field => $default) {
            if (! in_array($field, $metadata['overrides'] ?? [], true)) {
                $metadata[$field] = $default;
            }
        }
        $metadata['evidence']['status'] = 'stale';
        $metadata['evidence']['verifiedAt'] = null;
        $metadata['files'] = array_map(function (array $file) {
            unset($file['verifiedAt']);
            if ($file['relation'] === 'evidence') {
                $file['relation'] = 'related';
            }

            return $file;
        }, $metadata['files']);
        unset($metadata['classification']['inputRevision']);

        return $metadata;
    }

    public static function patch(MemoryLink $link, array $patch, string $modelId): array
    {
        $data = Validator::make(['patch' => $patch], [
            'patch' => ['array:kind,interest,categories,tags,importance'],
            'patch.kind' => ['sometimes', Rule::in(self::KINDS)],
            'patch.interest' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:1'],
            'patch.categories' => ['sometimes', 'array', 'max:8'],
            'patch.categories.*' => ['array', 'min:1', 'max:4'],
            'patch.categories.*.*' => ['required', 'string', 'max:80'],
            'patch.tags' => ['sometimes', 'array', 'max:32'],
            'patch.tags.*' => ['string', 'max:80'],
            'patch.importance' => ['sometimes', 'numeric', 'min:0', 'max:1'],
        ])->validate()['patch'];
        self::validateStructure($data);
        foreach (['interest', 'importance'] as $field) {
            if (isset($data[$field]) && ! is_int($data[$field]) && ! is_float($data[$field])) {
                throw ValidationException::withMessages(['metadata.'.$field => 'Classification scores must be JSON numbers.']);
            }
        }
        foreach (['categories', 'tags'] as $field) {
            if (isset($data[$field])) {
                abort_unless(array_is_list($data[$field]), 422, 'Classification lists must be JSON arrays.');
            }
        }
        $metadata = $link->meta['memory_metadata'] ?? self::initial($link);
        foreach ($data as $field => $value) {
            if (in_array($field, $metadata['overrides'], true)) {
                unset($data[$field]);

                continue;
            }
            if ($field === 'categories') {
                $value = array_map(fn (array $path) => self::category($path), $value);
            }
            if ($field === 'interest' && $link->source_type !== 'user'
                && ! collect($metadata['evidence']['sources'])->contains(fn ($source) => ($source['role'] ?? '') === 'user')) {
                continue;
            }
            if (! in_array($field, ['tags', 'importance'], true)) {
                $metadata[$field] = $value;
            }
        }
        $metadata['classification'] = ['origin' => 'dream', 'updatedAt' => now()->getTimestampMs(),
            'policy' => self::POLICY, 'inputRevision' => self::inputRevision($link), 'modelId' => $modelId];
        $meta = array_replace($link->meta ?? [], ['memory_metadata' => self::normalize($metadata)]);
        if (array_key_exists('tags', $data)) {
            $meta['tags'] = array_values(array_unique(array_merge($data['tags'], array_values(array_intersect(
                $link->meta['tags'] ?? [], ['maintenance-derived', 'idle-optimization', 'checkpoint'])))));
        }

        return ['meta' => $meta, 'importance' => $data['importance'] ?? $link->importance];
    }

    public static function initial(MemoryLink $link): array
    {
        return ['version' => 1, 'kind' => 'unknown', 'interest' => null, 'categories' => [], 'files' => [],
            'evidence' => ['status' => $link->source_type === 'user' ? 'user_stated' : 'inferred', 'verifiedAt' => null, 'sources' => []],
            'classification' => ['origin' => 'capture', 'updatedAt' => now()->getTimestampMs(), 'policy' => self::POLICY], 'overrides' => []];
    }

    public static function category(array $path): array
    {
        $aliases = ['softwareentwicklung' => 'Software', 'software' => 'Software', 'programming' => 'Software',
            'backend' => 'Backend', 'frontend' => 'Frontend', 'datenbanken' => 'Datenbanken', 'databases' => 'Datenbanken',
            'dokumentation' => 'Dokumentation', 'documentation' => 'Dokumentation', 'laravel' => 'Laravel',
            'livewire' => 'Livewire', 'php' => 'PHP', 'vue' => 'Vue', 'vue.js' => 'Vue', 'typescript' => 'TypeScript',
            'javascript' => 'JavaScript', 'tauri' => 'Tauri'];
        $labels = array_map(function (string $part) use ($aliases) {
            $label = preg_replace('/\s+/u', ' ', trim($part));
            $label = class_exists(\Normalizer::class) ? \Normalizer::normalize($label, \Normalizer::FORM_C) : $label;

            return $aliases[mb_strtolower($label)] ?? $label;
        }, $path);

        return ['id' => implode('/', array_map(fn (string $part) => rawurlencode(mb_strtolower($part)), $labels)), 'path' => $labels];
    }

    /** Merge source annotations without turning a synthesis into verified evidence. */
    public static function merge(array $links): array
    {
        $entries = array_map(fn (MemoryLink $link) => $link->meta['memory_metadata'] ?? self::initial($link), $links);
        $metadata = self::initial($links[0]);
        $metadata['evidence']['status'] = collect($entries)->contains(fn ($entry) => $entry['evidence']['status'] === 'conflicting') ? 'conflicting' : 'inferred';
        $metadata['kind'] = count(array_unique(array_column($entries, 'kind'))) === 1 ? $entries[0]['kind'] : 'unknown';
        $metadata['interest'] = collect($entries)->pluck('interest')->filter(fn ($value) => $value !== null)->max();
        foreach (['categories', 'files'] as $field) {
            $metadata[$field] = array_values(array_unique(array_merge(...array_column($entries, $field)), SORT_REGULAR));
        }
        $metadata['evidence']['sources'] = array_values(array_unique(array_merge(...array_map(fn ($entry) => $entry['evidence']['sources'], $entries)), SORT_REGULAR));
        $tags = array_values(array_unique(array_merge(...array_map(fn ($link) => $link->meta['tags'] ?? [], $links))));
        $importance = max(array_map(fn ($link) => (float) $link->importance, $links));
        foreach (self::OVERRIDES as $field) {
            $values = [];
            foreach ($entries as $index => $entry) {
                if (in_array($field, $entry['overrides'], true)) {
                    $values[] = match ($field) {
                        'tags' => $links[$index]->meta['tags'] ?? [], 'importance' => (float) $links[$index]->importance,
                        default => $entry[$field],
                    };
                }
            }
            abort_if(count(array_unique(array_map(fn ($value) => json_encode($value, JSON_THROW_ON_ERROR), $values))) > 1, 422, 'Conflicting manual metadata overrides.');
            if ($values !== []) {
                $metadata['overrides'][] = $field;
                if ($field === 'tags') {
                    $tags = $values[0];
                } elseif ($field === 'importance') {
                    $importance = $values[0];
                } else {
                    $metadata[$field] = $values[0];
                }
            }
        }
        $tags = array_values(array_unique([...$tags, 'maintenance-derived']));
        abort_if(count($tags) > 32, 422, 'Merged tags exceed metadata bounds.');

        return ['meta' => ['memory_metadata' => self::normalize($metadata), 'tags' => $tags], 'importance' => $importance];
    }

    public static function searchText(MemoryLink $link): string
    {
        $metadata = $link->meta['memory_metadata'] ?? [];
        $parts = [$link->summary, $link->feature_key ?? '', ...(is_array($link->meta['tags'] ?? null) ? $link->meta['tags'] : [])];
        foreach ($metadata['categories'] ?? [] as $category) {
            $parts = [...$parts, ...($category['path'] ?? [])];
        }
        foreach ($metadata['files'] ?? [] as $file) {
            $parts[] = $file['path'] ?? '';
        }

        return mb_strtolower(implode(' ', array_filter($parts, 'is_string')));
    }

    private static function validateStructure(array $value): void
    {
        foreach ($value as $key => $item) {
            if (is_array($item)) {
                self::validateStructure($item);
            } elseif (is_string($item) && (trim($item) === '' || preg_match('/[\x00-\x1f]/u', $item) !== 0)) {
                throw ValidationException::withMessages(['meta.memory_metadata' => 'Metadata strings must be nonempty and contain no control characters.']);
            }
            if (in_array($key, ['verifiedAt', 'observedAt', 'updatedAt'], true) && $item !== null && ! is_int($item)) {
                throw ValidationException::withMessages(['meta.memory_metadata' => 'Metadata timestamps must be JSON integers.']);
            }
        }
    }
}
