<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\LuczorMemoryArchive;
use App\Models\LuczorMessageArchive;
use App\Models\LuczorProjectArchive;
use App\Models\LuczorSummaryArchive;
use App\Services\ApiActor;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;

class SyncController extends Controller
{
    private const ARCHIVES = [
        'projects' => [LuczorProjectArchive::class, 'project'],
        'messages' => [LuczorMessageArchive::class, 'message'],
        'memories' => [LuczorMemoryArchive::class, 'memory'],
        'summaries' => [LuczorSummaryArchive::class, 'summary'],
    ];

    public function push(Request $request, ApiActor $actor)
    {
        $data = $request->validate([
            'client_id' => ['required', 'string', 'max:120'],
            'projects' => ['array'],
            'messages' => ['array'],
            'memories' => ['array'],
            'summaries' => ['array'],
        ]);

        $userId = $actor->userId($request);
        $clientId = $actor->deviceId($request, $data['client_id'], true);
        $counts = [];

        foreach (self::ARCHIVES as $bucket => [$modelClass, $entityType]) {
            $items = Arr::get($data, $bucket, []);
            $counts[$bucket] = 0;

            foreach ($items as $item) {
                $externalId = (string) Arr::get($item, 'id', Arr::get($item, 'external_id', ''));
                if ($externalId === '') {
                    continue;
                }

                /** @var class-string<Model> $modelClass */
                $projectExternalId = $entityType === 'project'
                    ? $externalId
                    : (string) Arr::get($item, 'projectId', Arr::get($item, 'project_id', ''));
                $project = $actor->project(
                    $request,
                    $projectExternalId,
                    (string) Arr::get($item, 'name', $projectExternalId),
                    $entityType === 'project'
                );

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
                        'updated_at_client' => $this->clientTime(Arr::get($item, 'updatedAt', Arr::get($item, 'ts'))),
                    ]
                );

                $counts[$bucket]++;
            }
        }

        return response()->json([
            'ok' => true,
            'counts' => $counts,
            'cursor' => now()->toISOString(),
        ]);
    }

    public function pull(Request $request, ApiActor $actor)
    {
        $validated = $request->validate([
            'since' => ['nullable', 'date'],
        ]);

        $userId = $actor->userId($request);
        $since = isset($validated['since']) ? Carbon::parse($validated['since']) : null;
        $payload = [];

        foreach (self::ARCHIVES as $bucket => [$modelClass]) {
            $query = $modelClass::query()->where('user_id', $userId)->orderBy('updated_at');
            if ($since) {
                $query->where('updated_at', '>', $since);
            }
            $payload[$bucket] = $query->limit(500)->get();
        }

        return response()->json([
            'data' => $payload,
            'cursor' => now()->toISOString(),
        ]);
    }

    private function clientTime($value): ?Carbon
    {
        if (! $value) {
            return null;
        }

        if (is_numeric($value)) {
            $number = (int) $value;
            return $number > 9999999999 ? Carbon::createFromTimestampMs($number) : Carbon::createFromTimestamp($number);
        }

        return Carbon::parse($value);
    }
}
