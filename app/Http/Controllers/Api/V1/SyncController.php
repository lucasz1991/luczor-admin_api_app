<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\LuczorMemoryArchive;
use App\Models\LuczorMessageArchive;
use App\Models\LuczorProjectArchive;
use App\Models\LuczorSummaryArchive;
use App\Services\ApiActor;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use JsonException;
use Throwable;

class SyncController extends Controller
{
    private const ARCHIVES = [
        'projects' => [LuczorProjectArchive::class, 'project'],
        'messages' => [LuczorMessageArchive::class, 'message'],
        'memories' => [LuczorMemoryArchive::class, 'memory'],
        'summaries' => [LuczorSummaryArchive::class, 'summary'],
    ];

    private const MAX_BATCH_BYTES = 5_242_880;

    private const MAX_BUCKET_ITEMS = 500;

    private const MAX_ITEM_BYTES = 262_144;

    private const MAX_ITEM_DEPTH = 16;

    private const DEFAULT_PULL_LIMIT = 500;

    public function push(Request $request, ApiActor $actor): JsonResponse
    {
        $data = $this->validatePush($request);
        $userId = $actor->userId($request);
        $clientId = $actor->deviceId($request, $data['client_id'], true);

        $counts = DB::transaction(function () use ($request, $actor, $data, $userId, $clientId) {
            $counts = [];

            foreach (self::ARCHIVES as $bucket => [$modelClass, $entityType]) {
                $items = Arr::get($data, $bucket, []);
                $counts[$bucket] = 0;

                foreach ($items as $item) {
                    $externalId = (string) (Arr::get($item, 'id') ?? Arr::get($item, 'external_id'));
                    $projectExternalId = $entityType === 'project'
                        ? $externalId
                        : (string) (Arr::get($item, 'projectId') ?? Arr::get($item, 'project_id', ''));
                    $project = $actor->project(
                        $request,
                        $projectExternalId,
                        (string) Arr::get($item, 'name', $projectExternalId),
                        $entityType === 'project'
                    );

                    /** @var class-string<Model> $modelClass */
                    $modelClass::updateOrCreate(
                        [
                            'user_id' => $userId,
                            'client_id' => $clientId,
                            'entity_type' => $entityType,
                            'external_id' => $externalId,
                        ],
                        [
                            'project_ref_id' => $project?->id,
                            'payload' => $item,
                            'created_at_client' => $this->clientTime(Arr::get($item, 'createdAt')),
                            'updated_at_client' => $this->clientTime(
                                Arr::get($item, 'updatedAt') ?? Arr::get($item, 'ts')
                            ),
                        ]
                    );

                    $counts[$bucket]++;
                }
            }

            return $counts;
        }, 3);

        return response()->json([
            'ok' => true,
            'counts' => $counts,
            'cursor' => now()->toISOString(),
        ]);
    }

    public function pull(Request $request, ApiActor $actor): JsonResponse
    {
        $validated = $request->validate($this->pullRules());
        $userId = $actor->userId($request);
        $limit = (int) ($validated['limit'] ?? self::DEFAULT_PULL_LIMIT);
        $since = isset($validated['since']) ? Carbon::parse($validated['since']) : null;
        $inputCursors = array_filter($validated['cursors'] ?? [], fn ($value) => $value !== null && $value !== '');

        if ($inputCursors !== [] && count($inputCursors) !== count(self::ARCHIVES)) {
            throw ValidationException::withMessages([
                'cursors' => 'A continuation request must include the cursor for every sync bucket.',
            ]);
        }

        $decodedCursors = [];
        $snapshotId = null;
        $snapshotAt = null;

        foreach ($inputCursors as $bucket => $cursor) {
            $decoded = $this->decodeCursor($bucket, $cursor);
            $decodedCursors[$bucket] = $decoded;
            $snapshotId ??= $decoded['snapshot_id'];
            $snapshotAt ??= Carbon::parse($decoded['snapshot_at']);

            if ($decoded['snapshot_id'] !== $snapshotId || ! Carbon::parse($decoded['snapshot_at'])->equalTo($snapshotAt)) {
                throw ValidationException::withMessages([
                    'cursors' => 'All bucket cursors must belong to the same sync snapshot.',
                ]);
            }

            $cursorSince = $decoded['since'] === null ? null : Carbon::parse($decoded['since']);
            if ($since !== null && ($cursorSince === null || ! $cursorSince->equalTo($since))) {
                throw ValidationException::withMessages([
                    'since' => 'The since value does not match the continuation cursor.',
                ]);
            }
            $since ??= $cursorSince;
        }

        $snapshotId ??= (string) Str::uuid();
        $snapshotAt ??= now();
        $payload = [];
        $continuation = [];
        $hasMore = false;

        foreach (self::ARCHIVES as $bucket => [$modelClass]) {
            $cursor = $decodedCursors[$bucket] ?? null;
            $boundary = $cursor === null
                ? $this->snapshotBoundary($modelClass, $userId, $since, $snapshotAt)
                : $this->cursorTuple($cursor, 'boundary');
            $position = $cursor === null ? null : $this->cursorTuple($cursor, 'position');

            if ($boundary === null) {
                $models = collect();
            } else {
                /** @var Builder $query */
                $query = $modelClass::query()
                    ->where('user_id', $userId)
                    ->when($since, fn (Builder $query) => $query->where('updated_at', '>', $since))
                    ->where(function (Builder $query) use ($boundary) {
                        $query->where('updated_at', '<', $boundary['updated_at'])
                            ->orWhere(function (Builder $query) use ($boundary) {
                                $query->where('updated_at', $boundary['updated_at'])
                                    ->where('id', '<=', $boundary['id']);
                            });
                    })
                    ->when($position, function (Builder $query) use ($position) {
                        $query->where(function (Builder $query) use ($position) {
                            $query->where('updated_at', '>', $position['updated_at'])
                                ->orWhere(function (Builder $query) use ($position) {
                                    $query->where('updated_at', $position['updated_at'])
                                        ->where('id', '>', $position['id']);
                                });
                        });
                    })
                    ->orderBy('updated_at')
                    ->orderBy('id');

                $models = $query->limit($limit + 1)->get();
            }

            $bucketHasMore = $models->count() > $limit;
            $models = $models->take($limit)->values();
            $last = $models->last();
            if ($last instanceof Model) {
                $position = [
                    'updated_at' => Carbon::parse($last->getAttribute('updated_at')),
                    'id' => (int) $last->getKey(),
                ];
            }

            $payload[$bucket] = $models;
            $continuation[$bucket] = [
                'has_more' => $bucketHasMore,
                'cursor' => $this->encodeCursor(
                    bucket: $bucket,
                    snapshotId: $snapshotId,
                    snapshotAt: $snapshotAt,
                    since: $since,
                    boundary: $boundary,
                    position: $position,
                ),
            ];
            $hasMore = $hasMore || $bucketHasMore;
        }

        return response()->json([
            'data' => $payload,
            // Kept for older clients. New clients must drain `continuation`
            // before persisting this snapshot timestamp as their next `since`.
            'cursor' => $snapshotAt->toISOString(),
            'has_more' => $hasMore,
            'continuation' => $continuation,
        ]);
    }

    /** @return array<string, mixed> */
    private function validatePush(Request $request): array
    {
        if (strlen($request->getContent()) > self::MAX_BATCH_BYTES) {
            throw ValidationException::withMessages([
                'payload' => 'The sync batch may not exceed 5 MiB.',
            ]);
        }

        $rules = [
            'client_id' => ['required', 'string', 'max:120'],
        ];

        foreach (array_keys(self::ARCHIVES) as $bucket) {
            $rules[$bucket] = ['sometimes', 'array', 'max:'.self::MAX_BUCKET_ITEMS];
            $rules[$bucket.'.*'] = ['required', 'array', 'max:100'];
            $rules[$bucket.'.*.id'] = ['nullable', 'string', 'max:120', 'regex:/\S/', 'required_without:'.$bucket.'.*.external_id'];
            $rules[$bucket.'.*.external_id'] = ['nullable', 'string', 'max:120', 'regex:/\S/', 'required_without:'.$bucket.'.*.id'];
            $rules[$bucket.'.*.projectId'] = ['nullable', 'string', 'max:120', 'regex:/\S/'];
            $rules[$bucket.'.*.project_id'] = ['nullable', 'string', 'max:120', 'regex:/\S/'];
            $rules[$bucket.'.*.name'] = ['nullable', 'string', 'max:255'];
            $rules[$bucket.'.*.createdAt'] = $this->clientTimestampRules();
            $rules[$bucket.'.*.updatedAt'] = $this->clientTimestampRules();
            $rules[$bucket.'.*.ts'] = $this->clientTimestampRules();
        }

        $validator = Validator::make($request->all(), $rules);
        $validator->after(function ($validator) use ($request) {
            foreach (array_keys(self::ARCHIVES) as $bucket) {
                foreach ((array) $request->input($bucket, []) as $index => $item) {
                    if (! is_array($item)) {
                        continue;
                    }

                    try {
                        $bytes = strlen(json_encode($item, JSON_THROW_ON_ERROR));
                    } catch (JsonException) {
                        $validator->errors()->add($bucket.'.'.$index, 'The sync item must contain valid JSON data.');

                        continue;
                    }

                    if ($bytes > self::MAX_ITEM_BYTES) {
                        $validator->errors()->add($bucket.'.'.$index, 'A sync item may not exceed 256 KiB.');
                    }
                    if ($this->arrayDepth($item) > self::MAX_ITEM_DEPTH) {
                        $validator->errors()->add($bucket.'.'.$index, 'A sync item may not exceed 16 nested levels.');
                    }
                }
            }
        });

        return $validator->validate();
    }

    /** @return array<string, array<int, mixed>> */
    private function pullRules(): array
    {
        $rules = [
            'since' => ['nullable', 'date'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:'.self::DEFAULT_PULL_LIMIT],
            'cursors' => ['nullable', 'array:'.implode(',', array_keys(self::ARCHIVES))],
        ];

        foreach (array_keys(self::ARCHIVES) as $bucket) {
            $rules['cursors.'.$bucket] = ['nullable', 'string', 'max:2048'];
        }

        return $rules;
    }

    /** @return array<int, mixed> */
    private function clientTimestampRules(): array
    {
        return [
            'nullable',
            function (string $attribute, mixed $value, callable $fail) {
                try {
                    $timestamp = $this->clientTime($value);
                    if ($timestamp === null || $timestamp->year < 1970 || $timestamp->year > 3000) {
                        $fail('The '.$attribute.' field must be a valid timestamp.');
                    }
                } catch (Throwable) {
                    $fail('The '.$attribute.' field must be a valid timestamp.');
                }
            },
        ];
    }

    /**
     * @param  class-string<Model>  $modelClass
     * @return array{updated_at: Carbon, id: int}|null
     */
    private function snapshotBoundary(string $modelClass, int $userId, ?Carbon $since, Carbon $snapshotAt): ?array
    {
        $model = $modelClass::query()
            ->where('user_id', $userId)
            ->where('updated_at', '<=', $snapshotAt)
            ->when($since, fn (Builder $query) => $query->where('updated_at', '>', $since))
            ->orderByDesc('updated_at')
            ->orderByDesc('id')
            ->first(['id', 'updated_at']);

        if (! $model) {
            return null;
        }

        return [
            'updated_at' => Carbon::parse($model->getAttribute('updated_at')),
            'id' => (int) $model->getKey(),
        ];
    }

    /**
     * @param  array{updated_at: Carbon, id: int}|null  $boundary
     * @param  array{updated_at: Carbon, id: int}|null  $position
     */
    private function encodeCursor(
        string $bucket,
        string $snapshotId,
        Carbon $snapshotAt,
        ?Carbon $since,
        ?array $boundary,
        ?array $position,
    ): string {
        $payload = [
            'version' => 1,
            'bucket' => $bucket,
            'snapshot_id' => $snapshotId,
            'snapshot_at' => $snapshotAt->toISOString(),
            'since' => $since?->toISOString(),
            'boundary_at' => $boundary === null ? null : $boundary['updated_at']->toISOString(),
            'boundary_id' => $boundary['id'] ?? null,
            'position_at' => $position === null ? null : $position['updated_at']->toISOString(),
            'position_id' => $position['id'] ?? null,
        ];

        return Crypt::encryptString(json_encode($payload, JSON_THROW_ON_ERROR));
    }

    /** @return array<string, mixed> */
    private function decodeCursor(string $bucket, string $cursor): array
    {
        try {
            $decoded = json_decode(Crypt::decryptString($cursor), true, flags: JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            throw ValidationException::withMessages([
                'cursors.'.$bucket => 'The sync continuation cursor is invalid or expired.',
            ]);
        }

        if (! is_array($decoded)
            || ($decoded['version'] ?? null) !== 1
            || ($decoded['bucket'] ?? null) !== $bucket
            || ! is_string($decoded['snapshot_id'] ?? null)
            || ! is_string($decoded['snapshot_at'] ?? null)
        ) {
            throw ValidationException::withMessages([
                'cursors.'.$bucket => 'The sync continuation cursor is invalid or belongs to another bucket.',
            ]);
        }

        return $decoded;
    }

    /** @return array{updated_at: Carbon, id: int}|null */
    private function cursorTuple(array $cursor, string $prefix): ?array
    {
        $timestamp = $cursor[$prefix.'_at'] ?? null;
        $id = $cursor[$prefix.'_id'] ?? null;
        if ($timestamp === null && $id === null) {
            return null;
        }

        if (! is_string($timestamp) || ! is_int($id) || $id < 1) {
            throw ValidationException::withMessages([
                'cursors' => 'The sync continuation cursor contains an invalid keyset position.',
            ]);
        }

        try {
            return ['updated_at' => Carbon::parse($timestamp), 'id' => $id];
        } catch (Throwable) {
            throw ValidationException::withMessages([
                'cursors' => 'The sync continuation cursor contains an invalid timestamp.',
            ]);
        }
    }

    private function arrayDepth(array $value, int $depth = 1): int
    {
        $maxDepth = $depth;
        foreach ($value as $child) {
            if (is_array($child)) {
                $maxDepth = max($maxDepth, $this->arrayDepth($child, $depth + 1));
            }
        }

        return $maxDepth;
    }

    private function clientTime(mixed $value): ?Carbon
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (is_numeric($value)) {
            $number = (int) $value;

            return $number > 9_999_999_999
                ? Carbon::createFromTimestampMs($number)
                : Carbon::createFromTimestamp($number);
        }

        return Carbon::parse($value);
    }
}
