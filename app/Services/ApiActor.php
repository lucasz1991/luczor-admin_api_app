<?php

namespace App\Services;

use App\Models\ApiKey;
use App\Models\Project;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;

/** Resolves the authenticated user/device and enforces resource ownership. */
class ApiActor
{
    public function userId(Request $request): int
    {
        abort_unless($request->user(), 401, 'Authenticated user required.');

        return (int) $request->user()->id;
    }

    public function deviceId(Request $request, ?string $claimedDeviceId = null, bool $required = false): ?string
    {
        /** @var ApiKey|null $apiKey */
        $apiKey = $request->attributes->get('apiKey');
        abort_unless($apiKey instanceof ApiKey, 401, 'Device API key required.');

        $claimedDeviceId = trim((string) $claimedDeviceId) ?: null;
        $boundDeviceId = trim((string) $apiKey->device_id) ?: null;

        if ($claimedDeviceId && $boundDeviceId && ! hash_equals($boundDeviceId, $claimedDeviceId)) {
            abort(403, 'The API key is bound to another device.');
        }

        if ($claimedDeviceId && ! $boundDeviceId) {
            // A device key may be enrolled once. Later requests are immutable.
            ApiKey::query()->whereKey($apiKey->id)->whereNull('device_id')->update([
                'device_id' => $claimedDeviceId,
                'device_name' => $apiKey->device_name ?: 'Luczor device',
            ]);
            $apiKey->refresh();
            $boundDeviceId = $apiKey->device_id;
        }

        if ($required && ! $boundDeviceId) {
            abort(422, 'Register this device before using this endpoint.');
        }

        return $boundDeviceId;
    }

    public function assertOwned(Request $request, Model $resource, string $userColumn = 'user_id'): void
    {
        if ($request->user()?->isAdmin()) {
            return;
        }

        abort_unless((int) $resource->getAttribute($userColumn) === $this->userId($request), 404);
    }

    public function project(Request $request, ?string $externalId, ?string $name = null, bool $create = false): ?Project
    {
        $externalId = trim((string) $externalId);
        if ($externalId === '') {
            return null;
        }

        $query = Project::query()->where('external_id', $externalId);
        if (! $request->user()?->isAdmin()) {
            $query->where('user_id', $this->userId($request));
        }

        $project = $query->first();
        if ($project || ! $create) {
            return $project;
        }

        return Project::create([
            'user_id' => $this->userId($request),
            'external_id' => $externalId,
            'name' => $name ?: $externalId,
            'status' => 'active',
        ]);
    }
}
