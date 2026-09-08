<?php

namespace App\Services;

use App\Models\Device;
use App\Models\User;
use App\Models\WebWorkspaceChat;
use App\Models\WebWorkspaceTurn;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;

class WebWorkspaceService
{
    public function createChat(User $user, string $scope): WebWorkspaceChat
    {
        abort_unless($user->isActive(), 403);
        abort_unless(in_array($scope, ['workspace', 'personal'], true), 422);

        return WebWorkspaceChat::create(['user_id' => $user->id, 'scope' => $scope, 'title' => $scope === 'personal' ? 'Neuer freier Chat' : 'Neuer Workspace-Chat']);
    }

    public function submit(User $user, int $chatId, int $deviceId, string $prompt, string $submissionId): WebWorkspaceTurn
    {
        abort_unless($user->isActive(), 403);
        validator(compact('prompt', 'submissionId'), ['prompt' => ['required', 'string', 'max:12000'], 'submissionId' => ['required', 'uuid']])->validate();

        return DB::transaction(function () use ($user, $chatId, $deviceId, $prompt, $submissionId) {
            $chat = WebWorkspaceChat::where('user_id', $user->id)->lockForUpdate()->findOrFail($chatId);
            if ($existing = $chat->turns()->where('submission_id', $submissionId)->first()) {
                return $existing;
            }
            abort_if(RateLimiter::tooManyAttempts('workspace-chat:'.$user->id, 12), 429, 'Bitte einen Moment warten.');
            $device = Device::where('user_id', $user->id)->whereNull('revoked_at')->findOrFail($deviceId);
            abort_if($chat->turns()->whereHas('deviceJob', fn ($query) => $query->whereIn('status', ['approval_required', 'queued', 'running'])->where('expires_at', '>', now()))->exists(), 409, 'In diesem Chat läuft bereits ein Auftrag.');
            $history = [];
            foreach ($chat->turns()->with('deviceJob')->latest('id')->limit(4)->get()->reverse() as $turn) {
                if ($turn->deviceJob?->status !== 'completed' || ! is_string($turn->deviceJob->result['text'] ?? null)) {
                    continue;
                }
                $history[] = ['role' => 'user', 'content' => mb_substr($turn->prompt, 0, 1500)];
                $history[] = ['role' => 'assistant', 'content' => mb_substr($turn->deviceJob->result['text'], 0, 1500)];
            }
            $memories = app(MemoryOrchestrator::class)->recall($prompt, 'user', ['user_id' => $user->id, 'tenant_id' => $user->tenant_id], 4);
            $personalMemories = array_map(fn ($memory) => [
                'id' => (string) $memory['id'],
                'content' => mb_substr((string) $memory['content'], 0, 1200),
                'priority' => in_array($memory['priority'] ?? '', ['background', 'normal', 'high', 'critical'], true) ? $memory['priority'] : 'normal',
            ], $memories);
            $payload = [
                'chat_id' => $chat->id, 'scope' => $chat->scope, 'prompt' => trim($prompt),
                'history' => $history, 'personal_memories' => $personalMemories,
            ];
            while (mb_strlen(json_encode($payload, JSON_UNESCAPED_UNICODE)) > 21500 && count($payload['history']) >= 2) {
                array_splice($payload['history'], 0, 2);
            }
            $job = app(DeviceJobService::class)->createForAccount($user, $device, $payload);
            $turn = $chat->turns()->create(['submission_id' => $submissionId, 'device_job_id' => $job->id, 'prompt' => trim($prompt)]);
            if ($chat->turns()->count() === 1) {
                $chat->title = mb_substr(preg_replace('/\s+/u', ' ', trim($prompt)), 0, 80);
            }
            $chat->touch();
            $chat->save();
            RateLimiter::hit('workspace-chat:'.$user->id, 60);

            return $turn;
        });
    }

    public function cancelQueued(User $user, int $chatId, int $turnId): void
    {
        abort_unless($user->isActive(), 403);
        $chat = WebWorkspaceChat::where('user_id', $user->id)->findOrFail($chatId);
        $turn = $chat->turns()->findOrFail($turnId);
        DB::transaction(function () use ($user, $turn) {
            $job = $turn->deviceJob()->lockForUpdate()->firstOrFail();
            abort_unless((int) $job->user_id === (int) $user->id, 404);
            abort_unless(in_array($job->status, ['queued', 'approval_required'], true), 409, 'Laufende Aufträge bitte direkt am Gerät stoppen.');
            $job->update(['status' => 'cancelled', 'finished_at' => now()]);
            app(AuditLogger::class)->record(['actor_user_id' => $user->id, 'device_id' => $job->device_id, 'device_job_id' => $job->id, 'event_type' => 'device_job.cancelled', 'outcome' => 'cancelled']);
        });
    }
}
