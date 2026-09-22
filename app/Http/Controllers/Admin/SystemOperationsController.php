<?php

namespace App\Http\Controllers\Admin;

use App\Models\Device;
use App\Models\DeviceDebugRequest;
use App\Models\LlmRun;
use App\Models\Setting;
use App\Services\InternalModelProfileService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redirect;
use Illuminate\Validation\Rule;

class SystemOperationsController extends AdminController
{
    public function requestDeviceDebug(Request $request, Device $device)
    {
        $this->ensureAdmin($request);
        abort_if($device->revoked_at, 409, 'Gerät ist widerrufen.');
        DB::transaction(function () use ($request, $device) {
            Device::whereKey($device->id)->lockForUpdate()->firstOrFail();
            if (DeviceDebugRequest::where('device_id', $device->id)->whereIn('status', ['pending', 'collecting'])->exists()) {
                return;
            }
            DeviceDebugRequest::create([
                'device_id' => $device->id,
                'user_id' => $device->user_id,
                'requested_by' => $request->user()->id,
                'status' => 'pending',
                'meta' => ['source' => 'admin_dashboard'],
            ]);
        });

        return Redirect::route('admin.page', 'devices')->with('status', 'Debug-Anforderung vorgemerkt. Das Gerät muss online sein und die Diagnose freigegeben haben.');
    }

    public function downloadDeviceDebug(Request $request, DeviceDebugRequest $debugRequest)
    {
        $this->ensureAdmin($request);
        abort_unless($debugRequest->status === 'completed' && is_array($debugRequest->payload), 404);

        $filename = 'luczor-debug-'.$debugRequest->device_id.'-'.now()->format('Ymd-His').'.json';

        return response()->streamDownload(function () use ($debugRequest) {
            echo json_encode($debugRequest->payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }, $filename, ['Content-Type' => 'application/json; charset=UTF-8', 'Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff']);
    }

    public function exportDeviceDebug(Request $request)
    {
        $this->ensureAdmin($request);

        return response()->streamDownload(function () {
            foreach (DeviceDebugRequest::with('device')->where('status', 'completed')->latest()->limit(50)->cursor() as $debug) {
                echo json_encode(['id' => $debug->public_id, 'device' => $debug->device?->device_id,
                    'completed_at' => $debug->completed_at?->toIso8601String(), 'report' => $debug->payload],
                    JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)."\n";
            }
        }, 'luczor-debug-'.now()->format('Ymd-His').'.jsonl', [
            'Content-Type' => 'application/x-ndjson', 'Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public function storeSettings(Request $request)
    {
        $this->ensureAdmin($request);

        $incoming = (array) $request->input('settings', []);

        foreach (Setting::where('key', '!=', InternalModelProfileService::SETTING_KEY)->get() as $setting) {
            $attrs = ['group' => $setting->group, 'label' => $setting->label, 'type' => $setting->type];

            if (! array_key_exists($setting->key, $incoming)) {
                // Unchecked checkboxes are absent -> false.
                if ($setting->type === 'bool') {
                    Setting::putValue($setting->key, false, $attrs);
                }

                continue;
            }

            $raw = $incoming[$setting->key];
            $value = match ($setting->type) {
                'bool' => filter_var($raw, FILTER_VALIDATE_BOOLEAN),
                'number' => is_numeric($raw) ? (float) $raw : 0,
                default => (string) $raw,
            };

            Setting::putValue($setting->key, $value, $attrs);
        }

        return Redirect::route('dashboard')->with('status', 'Einstellungen gespeichert.');
    }

    public function storeInternalModelProfiles(Request $request, InternalModelProfileService $profiles)
    {
        $this->ensureAdmin($request);
        $rules = ['profiles' => ['required', 'array:standard,external_agents']];
        foreach (InternalModelProfileService::MODES as $mode) {
            $rules['profiles.'.$mode] = ['required', 'array:enabled,personality,system_prompt'];
            $rules['profiles.'.$mode.'.enabled'] = ['required', 'boolean'];
            $rules['profiles.'.$mode.'.personality'] = ['present', 'nullable', 'string', 'max:'.InternalModelProfileService::PERSONALITY_LIMIT];
            $rules['profiles.'.$mode.'.system_prompt'] = ['present', 'nullable', 'string', 'max:'.InternalModelProfileService::SYSTEM_PROMPT_LIMIT];
        }
        $data = $request->validateWithBag('internalModels', $rules, [
            'max' => ':attribute darf höchstens :max Zeichen enthalten.',
            'string' => ':attribute muss ein Text sein.',
        ], [
            'profiles.standard.personality' => 'Persönlichkeit im internen Betrieb',
            'profiles.standard.system_prompt' => 'System-Prompt im internen Betrieb',
            'profiles.external_agents.personality' => 'Persönlichkeit im Externagentenmodus',
            'profiles.external_agents.system_prompt' => 'System-Prompt im Externagentenmodus',
        ]);
        $profiles->save($data['profiles']);

        return Redirect::route('admin.page', 'settings')->with('status', 'Profile für interne Modelle global gespeichert.');
    }

    public function exportTelemetry(Request $request)
    {
        $this->ensureAdmin($request);
        $data = $request->validate([
            'format' => ['nullable', Rule::in(['jsonl', 'csv'])],
            'days' => ['nullable', 'integer', 'min:1', 'max:3650'],
        ]);
        $format = $data['format'] ?? 'jsonl';
        $days = (int) ($data['days'] ?? 30);
        $filename = 'luczor-telemetry-'.now()->format('Ymd-His').'.'.$format;

        return response()->streamDownload(function () use ($format, $days) {
            $output = fopen('php://output', 'wb');
            if ($format === 'csv') {
                fputcsv($output, ['request_id', 'created_at', 'task_type', 'selected_by', 'attempt', 'provider', 'model', 'status', 'http_status', 'ttft_ms', 'total_ms', 'input_tokens', 'output_tokens', 'tokens_per_second', 'cost_usd', 'cost_source', 'quality_score', 'test_passed']);
            }

            LlmRun::query()->with(['attempts', 'metrics', 'evaluations', 'toolCalls'])
                ->where('created_at', '>=', now()->subDays($days))->orderBy('id')
                ->chunkById(100, function ($runs) use ($format, $output) {
                    foreach ($runs as $run) {
                        if ($format === 'jsonl') {
                            fwrite($output, json_encode($run->toArray(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)."\n");

                            continue;
                        }
                        foreach ($run->attempts as $attempt) {
                            fputcsv($output, [$run->request_id, $run->created_at?->toIso8601String(), $run->task_type, $run->selected_by, $attempt->attempt_no, $attempt->provider_id, $attempt->model_id, $attempt->status, $attempt->http_status, $attempt->ttft_ms, $attempt->total_ms, $attempt->input_tokens, $attempt->output_tokens, $attempt->tokens_per_second, $attempt->effective_cost, $attempt->cost_source, $run->quality_score, $run->test_passed]);
                        }
                    }
                });
            fclose($output);
        }, $filename, ['Content-Type' => $format === 'csv' ? 'text/csv; charset=UTF-8' : 'application/x-ndjson']);
    }
}
