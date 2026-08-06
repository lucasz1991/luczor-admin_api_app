<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Device;
use App\Models\NotificationPreference;
use App\Services\ApiActor;
use App\Services\AppNotificationService;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class NotificationPreferenceController extends Controller
{
    public function show(Request $request, ApiActor $actor, AppNotificationService $notifications)
    {
        $data = $request->validate([
            'client_id' => ['required', 'string', 'max:120'],
        ]);
        $device = $this->currentDevice($request, $actor, $data['client_id']);

        return response()->json([
            'data' => $notifications->preferencesFor((int) $device->user_id),
        ]);
    }

    public function update(Request $request, ApiActor $actor, AppNotificationService $notifications)
    {
        $data = $request->validate([
            'client_id' => ['required', 'string', 'max:120'],
            'enabled' => ['sometimes', 'boolean'],
            'categories' => ['sometimes', 'array'],
            'categories.*' => ['boolean'],
        ]);
        $categories = $data['categories'] ?? [];
        $unsupported = array_diff(array_keys($categories), NotificationPreference::CATEGORIES);
        if ($unsupported !== []) {
            throw ValidationException::withMessages([
                'categories' => ['Unsupported notification categories: '.implode(', ', $unsupported)],
            ]);
        }

        $device = $this->currentDevice($request, $actor, $data['client_id']);
        $enabled = array_key_exists('enabled', $data) ? (bool) $data['enabled'] : null;

        return response()->json([
            'data' => $notifications->updatePreferences(
                (int) $device->user_id,
                $enabled,
                $categories,
            ),
        ]);
    }

    private function currentDevice(Request $request, ApiActor $actor, string $clientId): Device
    {
        $deviceId = $actor->deviceId($request, $clientId, true);

        return Device::query()
            ->where('device_id', $deviceId)
            ->where('user_id', $actor->userId($request))
            ->whereNull('revoked_at')
            ->firstOrFail();
    }
}
