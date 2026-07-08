<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\LuczorMemoryArchive;
use App\Models\LuczorMessageArchive;
use App\Models\LuczorProjectArchive;
use App\Models\LuczorSummaryArchive;
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

    public function push(Request $request)
    {
        $data = $request->validate([
            'client_id' => ['required', 'string', 'max:120'],
            'projects' => ['array'],
            'messages' => ['array'],
            'memories' => ['array'],
            'summaries' => ['array'],
        ]);

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
                $modelClass::updateOrCreate(
                    [
                        'client_id' => $data['client_id'],
                        'entity_type' => $entityType,
                        'external_id' => $externalId,
                    ],
                    [
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

    public function pull(Request $request)
    {
        $validated = $request->validate([
            'since' => ['nullable', 'date'],
        ]);

        $since = isset($validated['since']) ? Carbon::parse($validated['since']) : null;
        $payload = [];

        foreach (self::ARCHIVES as $bucket => [$modelClass]) {
            $query = $modelClass::query()->orderBy('updated_at');
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
