<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Conversation;
use App\Models\DeviceJob;
use App\Models\LuczorMessageArchive;
use App\Services\CoordinatedDeviceJobs;
use App\Services\DeviceLeadership;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class ConversationContentController extends Controller
{
    public function show(Request $request, string $externalId, DeviceLeadership $leadership)
    {
        $device = $leadership->device($request);
        $conversation = Conversation::where('user_id', $device->user_id)->where('external_id', $externalId)->firstOrFail();
        $data = $request->validate(['after' => 'sometimes|integer|min:0', 'limit' => 'sometimes|integer|between:1,200']);
        $messages = LuczorMessageArchive::where('conversation_ref_id', $conversation->id)->where('user_id', $device->user_id)
            ->where('conversation_sequence', '>', $data['after'] ?? 0)->orderBy('conversation_sequence')->limit($data['limit'] ?? 200)->get();

        return response()->json(['data' => ['conversation_id' => $externalId, 'revision' => $conversation->revision,
            'messages' => $messages->map(fn ($message) => $message->payload + ['sequence' => $message->conversation_sequence]),
            'next_cursor' => $messages->last()->conversation_sequence ?? ($data['after'] ?? 0)]])->header('Cache-Control', 'private, no-store');
    }

    public function store(Request $request, string $externalId, DeviceLeadership $leadership)
    {
        $device = $leadership->device($request);
        abort_if(strlen($request->getContent()) > 2_097_152, 413, 'conversation_batch_too_large');
        $body = json_decode($request->getContent(), true, 16);
        $data = Validator::make(is_array($body) ? $body : [], ['expected_revision' => 'required|integer|min:0',
            'messages' => 'required|array|between:1,100', 'messages.*' => 'required|array:id,role,content,created_at,job_id',
            'messages.*.id' => 'required|uuid|distinct:strict', 'messages.*.role' => 'required|in:user,assistant',
            'messages.*.content' => 'present|string|max:200000', 'messages.*.created_at' => 'required|integer|between:0,253402300799000',
            'messages.*.job_id' => 'sometimes|nullable|uuid'])->validate();

        return DB::transaction(function () use ($device, $externalId, $data) {
            $conversation = Conversation::where('user_id', $device->user_id)->where('external_id', $externalId)->lockForUpdate()->firstOrFail();
            $new = [];
            foreach ($data['messages'] as $message) {
                $hash = CoordinatedDeviceJobs::hash($message);
                $old = LuczorMessageArchive::where('conversation_ref_id', $conversation->id)->where('external_id', $message['id'])->first();
                if ($old) {
                    abort_unless(hash_equals($old->content_hash, $hash), 409, 'conversation_message_conflict');

                    continue;
                }
                if (! empty($message['job_id'])) {
                    DeviceJob::where('user_id', $device->user_id)->where('public_id', $message['job_id'])
                        ->where('conversation_external_id', $externalId)->firstOrFail();
                }
                $new[] = [$message, $hash];
            }
            if ($new !== []) {
                abort_unless($conversation->revision === $data['expected_revision'], 409, 'conversation_revision_conflict');
                $sequence = (int) LuczorMessageArchive::where('conversation_ref_id', $conversation->id)->max('conversation_sequence');
                foreach ($new as [$message, $hash]) {
                    LuczorMessageArchive::create(['user_id' => $device->user_id, 'client_id' => $device->device_id,
                        'project_ref_id' => $conversation->project_ref_id, 'entity_type' => 'conversation_message_v1',
                        'external_id' => $message['id'], 'conversation_ref_id' => $conversation->id, 'conversation_sequence' => ++$sequence,
                        'content_hash' => $hash, 'payload' => $message, 'created_at_client' => Carbon::createFromTimestampMs($message['created_at']),
                        'updated_at_client' => now()]);
                }
                $conversation->forceFill(['revision' => $conversation->revision + 1, 'last_message_at' => now()])->save();
            }

            return response()->json(['data' => ['conversation_id' => $externalId, 'revision' => $conversation->revision, 'replayed' => $new === []]]);
        }, 3);
    }

    public function update(Request $request, string $externalId, DeviceLeadership $leadership)
    {
        $device = $leadership->device($request);
        $data = $request->validate(['expected_revision' => 'required|integer|min:0', 'title' => 'sometimes|string|max:200', 'archived' => 'sometimes|boolean']);

        return DB::transaction(function () use ($device, $externalId, $data) {
            $conversation = Conversation::where('user_id', $device->user_id)->where('external_id', $externalId)->lockForUpdate()->firstOrFail();
            abort_unless($conversation->revision === $data['expected_revision'], 409, 'conversation_revision_conflict');
            $conversation->forceFill(['title' => $data['title'] ?? $conversation->title,
                'archived_at' => array_key_exists('archived', $data) ? ($data['archived'] ? now() : null) : $conversation->archived_at,
                'revision' => $conversation->revision + 1])->save();

            return response()->json(['data' => $conversation]);
        }, 3);
    }
}
