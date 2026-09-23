<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\ApiActor;
use App\Services\MemoryOrchestrator;
use App\Services\MemorySyncService;
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
            'priority' => ['nullable', 'string', 'in:background,normal,high,critical'],
            'external_id' => ['nullable', 'string', 'max:190'],
            'write_id' => ['nullable', 'string', 'max:190'],
            'expected_previous_id' => ['nullable', 'integer', 'min:1'],
            'client_id' => ['nullable', 'string', 'max:120'],
            'tags' => ['nullable', 'array', 'max:32'],
            'tags.*' => ['string', 'max:80'],
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
            // Keep the historic request fingerprint for offline pre-upgrade retries.
            'meta' => array_replace(['tags' => []], $data['meta'] ?? []),
            '_tags_omitted' => ! isset($data['tags']) && ! array_key_exists('tags', $data['meta'] ?? []),
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

    public function forget(Request $request, MemoryOrchestrator $memory, MemorySyncService $sync)
    {
        $data = $request->validate([
            'external_id' => ['required', 'string', 'max:190'],
            'scope' => ['nullable', 'string', 'in:device,private,user,project,workspace,skill,agent,session,global'],
            'project_id' => ['nullable', 'string', 'max:120'],
            'client_id' => ['nullable', 'string', 'max:120'],
        ]);

        abort_if(($data['scope'] ?? 'project') === 'global' && ! $request->user()?->isAdmin(), 403, 'Global memory is administrator-managed.');

        if (in_array($data['scope'] ?? 'project', ['user', 'project'], true)) {
            return response()->json($sync->forget($data['scope'] ?? 'project', $this->ids($request), $data['external_id'], $memory));
        }

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

    public function analyze(Request $request, MemoryOrchestrator $memory)
    {
        $data = $request->validate([
            'scope' => ['required', 'string', 'in:user,project'],
            'project_id' => ['required_if:scope,project', 'nullable', 'string', 'max:120'],
        ]);

        return response()->json(['data' => $memory->analyze($data['scope'], $this->ids($request))]);
    }

    public function maintenanceStatus(Request $request, MemoryOrchestrator $memory, ApiActor $actor)
    {
        $data = $request->validate([
            'scope' => ['required', 'in:user,project'],
            'project_id' => ['required_if:scope,project', 'nullable', 'string', 'max:120'],
        ]);
        $actor->project($request, $data['project_id'] ?? null);

        return response()->json(['data' => $memory->maintenanceStatus($data['scope'], $this->ids($request)),
            'capabilities' => ['memory_metadata_versions' => [1], 'memory_metadata_cas' => true]]);
    }

    public function capabilities()
    {
        return response()->json(['capabilities' => MemorySyncService::capabilities(),
            'server_instance' => MemorySyncService::serverInstance()])
            ->header('Cache-Control', 'private, max-age=300')->header('Vary', 'Authorization, X-Api-Key');
    }

    public function changes(Request $request, MemorySyncService $sync)
    {
        $data = $request->validate(['scope' => ['required', 'in:user,project'],
            'project_id' => ['required_if:scope,project', 'nullable', 'string', 'max:120'],
            'cursor' => ['nullable', 'string', 'max:4096'], 'limit' => ['nullable', 'integer', 'min:1', 'max:100']]);

        return response()->json($sync->changes($data['scope'], $this->ids($request), $data['cursor'] ?? null, $data['limit'] ?? 50))
            ->header('Cache-Control', 'no-store');
    }

    public function deletionReceipt(Request $request, MemorySyncService $sync)
    {
        $data = $request->validate(['receipt_id' => ['required', 'uuid']]);

        return response()->json(['data' => $sync->receipt($data['receipt_id'], (int) $request->user()->id)])
            ->header('Cache-Control', 'no-store');
    }

    public function maintenanceSources(Request $request, MemoryOrchestrator $memory, ApiActor $actor)
    {
        $data = $request->validate([
            'scope' => ['required', 'in:user,project'],
            'project_id' => ['required_if:scope,project', 'nullable', 'string', 'max:120'],
            'after' => ['nullable', 'integer', 'min:0'],
        ]);
        $actor->project($request, $data['project_id'] ?? null);

        return response()->json(['data' => $memory->maintenanceSources($data['scope'], $this->ids($request), (int) ($data['after'] ?? 0))]);
    }

    public function applyMaintenance(Request $request, MemoryOrchestrator $memory, ApiActor $actor)
    {
        $data = $request->validate([
            'scope' => ['required', 'in:user,project'],
            'project_id' => ['required_if:scope,project', 'nullable', 'string', 'max:120'],
            'request_id' => ['required', 'string', 'max:190'], 'model_id' => ['required', 'string', 'max:128'],
            'consent' => ['sometimes', 'boolean'],
            'quality' => ['sometimes', 'array'], 'quality.passed' => ['required_with:quality', 'boolean'],
            'quality.policy' => ['required_with:quality', 'in:luczor-maintenance-v1'], 'quality.model_id' => ['required_with:quality', 'string', 'max:128'],
            'sources' => ['required', 'array', 'min:1', 'max:12'],
            'sources.*.id' => ['required', 'integer', 'min:1'], 'sources.*.revision' => ['required', 'regex:/^[a-f0-9]{64}$/D'],
            'operations' => ['required', 'array', 'min:1', 'max:8'],
            'operations.*.operation' => ['required', 'in:add,rewrite,merge,conflict,noop,annotate'],
            'operations.*.targets' => ['present', 'array', 'max:12'], 'operations.*.targets.*' => ['integer', 'min:1'],
            'operations.*.sources' => ['present', 'array', 'max:12'], 'operations.*.sources.*' => ['integer', 'min:1'],
            'operations.*.content' => ['present', 'nullable', 'string', 'max:6000'], 'operations.*.reason' => ['present', 'nullable', 'string', 'max:400'],
            'operations.*.metadata' => ['sometimes', 'array'],
        ]);
        $data['operations'] = array_map(fn (array $operation) => array_replace($operation, [
            'content' => $operation['content'] ?? '', 'reason' => $operation['reason'] ?? '',
        ]), $data['operations']);
        $project = $actor->project($request, $data['project_id'] ?? null);
        $ids = array_merge($this->ids($request), ['client_id' => $actor->deviceId($request, null), 'project_ref_id' => $project?->id]);

        return response()->json(['data' => $memory->applyMaintenance($data, $ids)]);
    }

    public function maintenanceReceipt(Request $request, MemoryOrchestrator $memory, ApiActor $actor)
    {
        $data = $request->validate(['scope' => ['required', 'in:user,project'],
            'project_id' => ['required_if:scope,project', 'nullable', 'string', 'max:120'],
            'request_id' => ['required', 'string', 'max:190']]);
        $actor->project($request, $data['project_id'] ?? null);

        return response()->json(['data' => $memory->maintenanceReceipt($data['scope'], $this->ids($request), $data['request_id'])]);
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
