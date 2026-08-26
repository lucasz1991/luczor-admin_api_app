<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\ApiActor;
use App\Services\MemoryOrchestrator;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Server memory API. The desktop client calls these with its device key; the
 * server talks to the internal Cognee (never exposed publicly) and the
 * memory_links System-of-Record. Provider/engine endpoints stay internal.
 */
class MemoryController extends Controller
{
    private function ids(Request $request): array
    {
        return [
            'user_id' => $request->user()?->id,
            'tenant_id' => $request->user()?->tenant_id,
            'project_id' => $request->input('project_id'),
            'agent_id' => $request->input('agent_id'),
            'session_id' => $request->input('session_id'),
        ];
    }

    public function remember(Request $request, MemoryOrchestrator $memory, ApiActor $actor)
    {
        $data = $request->validate([
            'content' => ['required', 'string', 'max:8000'],
            'scope' => ['nullable', 'string', 'in:device,private,user,project,workspace,skill,agent,session,global'],
            'project_id' => ['nullable', 'string', 'max:120'],
            'agent_id' => ['nullable', 'string', 'max:120'],
            'feature_key' => ['nullable', 'string', 'max:160'],
            'session_id' => ['nullable', 'string', 'max:120'],
            'type' => ['nullable', 'string', 'max:60'],
            'visibility' => ['nullable', 'string', 'in:private,syncable,public'],
            'importance' => ['nullable', 'numeric', 'min:0', 'max:1'],
            'external_id' => ['nullable', 'string', 'max:190'],
            'write_id' => ['nullable', 'string', 'max:190'],
            'expected_previous_id' => ['nullable', 'integer', 'min:1'],
            'client_id' => ['nullable', 'string', 'max:120'],
            'tags' => ['nullable', 'array'],
            'meta' => ['nullable', 'array'],
            'write_intent' => ['nullable', 'string', 'in:explicit,confirmed,automatic,inferred,system'],
            'retention' => ['nullable', 'string', 'in:session,durable,permanent'],
            'confidence' => ['nullable', 'numeric', 'min:0', 'max:1'],
            'sensitivity' => ['nullable', 'string', 'in:normal,sensitive,secret'],
            'source_type' => ['nullable', 'string', 'max:60'],
            'source_ref' => ['nullable', 'string', 'max:255'],
            'provenance' => ['nullable', 'array'],
            'observed_at' => ['nullable', 'date'],
            'valid_from' => ['nullable', 'date'],
            'valid_until' => ['nullable', 'date', 'after_or_equal:valid_from'],
            'expires_at' => ['nullable', 'date'],
        ]);

        abort_if(($data['scope'] ?? 'project') === 'global' && ! $request->user()?->isAdmin(), 403, 'Only an administrator can publish global memory.');
        $data['write_id'] = $this->writeIdentity($request, $data);

        $project = $actor->project($request, $data['project_id'] ?? null);
        $result = $memory->remember(array_merge($data, [
            'user_id' => $actor->userId($request),
            'tenant_id' => $request->user()?->tenant_id,
            'client_id' => $actor->deviceId($request, $data['client_id'] ?? null),
            'project_ref_id' => $project?->id,
            'scope' => $data['scope'] ?? 'project',
            'meta' => array_merge($data['meta'] ?? [], ['tags' => $data['tags'] ?? []]),
        ]));

        $payload = $result->toArray();

        return response()->json(array_merge([
            'ok' => true,
        ], $payload), $result->decision === 'accepted' ? 201 : 202);
    }

    /** @param array<string,mixed> $data */
    private function writeIdentity(Request $request, array $data): string
    {
        $writeId = trim((string) ($data['write_id'] ?? ''));
        if ($writeId !== '') {
            return $writeId;
        }

        $header = trim((string) $request->header('Idempotency-Key', ''));
        if ($header !== '') {
            if (mb_strlen($header) > 190) {
                throw ValidationException::withMessages([
                    'write_id' => 'The Idempotency-Key header must not exceed 190 characters.',
                ]);
            }

            return $header;
        }

        $externalId = trim((string) ($data['external_id'] ?? ''));
        if ($externalId !== '') {
            return 'external:'.hash('sha256', $externalId);
        }

        throw ValidationException::withMessages([
            'write_id' => 'A write_id, external_id, or Idempotency-Key header is required.',
        ]);
    }

    public function recall(Request $request, MemoryOrchestrator $memory)
    {
        $data = $request->validate([
            'query' => ['nullable', 'string', 'max:2000'],
            'scope' => ['nullable', 'string', 'in:device,private,user,project,workspace,skill,agent,session,global'],
            'project_id' => ['nullable', 'string', 'max:120'],
            'agent_id' => ['nullable', 'string', 'max:120'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:20'],
        ]);

        abort_if(($data['scope'] ?? 'project') === 'global' && ! $request->user()?->isAdmin(), 403, 'Global memory is administrator-managed.');

        $items = $memory->recall(
            $data['query'] ?? '',
            $data['scope'] ?? 'project',
            $this->ids($request),
            (int) ($data['limit'] ?? 6)
        );

        return response()->json(['data' => $items]);
    }

    public function forget(Request $request, MemoryOrchestrator $memory)
    {
        $data = $request->validate([
            'external_id' => ['required', 'string', 'max:190'],
            'scope' => ['nullable', 'string', 'in:device,private,user,project,workspace,skill,agent,session,global'],
            'project_id' => ['nullable', 'string', 'max:120'],
            'client_id' => ['nullable', 'string', 'max:120'],
        ]);

        abort_if(($data['scope'] ?? 'project') === 'global' && ! $request->user()?->isAdmin(), 403, 'Global memory is administrator-managed.');

        $forgotten = $memory->forget(
            $data['scope'] ?? 'project',
            $data['external_id'],
            $this->ids($request)
        );

        return response()->json([
            'ok' => true,
            'forgotten' => $forgotten,
            'already_absent' => ! $forgotten,
        ]);
    }

    public function improve(Request $request, MemoryOrchestrator $memory)
    {
        $data = $request->validate([
            'scope' => ['nullable', 'string', 'in:device,private,user,project,workspace,skill,agent,session,global'],
            'project_id' => ['nullable', 'string', 'max:120'],
        ]);

        abort_if(($data['scope'] ?? 'project') === 'global' && ! $request->user()?->isAdmin(), 403, 'Global memory is administrator-managed.');

        $scheduled = $memory->improve($data['scope'] ?? 'project', $this->ids($request));

        return response()->json(['ok' => true, 'scheduled' => $scheduled]);
    }

    public function promote(Request $request, MemoryOrchestrator $memory, ApiActor $actor)
    {
        $data = $request->validate([
            'external_id' => ['required', 'string', 'max:190'],
            'scope' => ['nullable', 'string', 'in:device,private,user,project,workspace,skill,agent,session,global'],
            'project_id' => ['nullable', 'string', 'max:120'],
            'agent_id' => ['nullable', 'string', 'max:120'],
            'session_id' => ['nullable', 'string', 'max:120'],
            'client_id' => ['nullable', 'string', 'max:120'],
        ]);
        $scope = $data['scope'] ?? 'project';
        abort_if($scope === 'global' && ! $request->user()?->isAdmin(), 403, 'Only an administrator can publish global memory.');

        $link = $memory->promote($data['external_id'], array_merge($this->ids($request), [
            'scope' => $scope,
            'client_id' => $actor->deviceId($request, $data['client_id'] ?? null),
        ]));
        abort_unless($link !== null, 404, 'Memory candidate not found.');

        return response()->json(['ok' => true, 'data' => [
            'id' => $link->logicalExternalId(),
            'status' => $link->status,
            'projection_status' => $link->projection_status,
        ]]);
    }
}
