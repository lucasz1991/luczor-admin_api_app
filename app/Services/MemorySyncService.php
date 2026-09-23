<?php

namespace App\Services;

use App\Models\MemoryLink;
use App\Models\MemoryProjectionOutbox;
use App\Models\User;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

/** SQL is canonical. This journal contains identifiers, never a second copy of memory text. */
class MemorySyncService
{
    public function __construct(private LuczorMemoryService $memory) {}

    public static function capabilities(): array
    {
        return ['memory_metadata_versions' => [1], 'memory_metadata_cas' => true,
            'memory_metadata_scopes' => ['user', 'project', 'workspace', 'skill', 'agent', 'session', 'global'],
            'memory_change_feed' => 1, 'memory_change_scopes' => ['user', 'project'],
            'memory_deletion_receipts' => 1, 'memory_conflicts' => 1];
    }

    public static function serverInstance(): string
    {
        return hash_hmac('sha256', 'luczor-memory-sync-v1', (string) config('app.key'));
    }

    private function scopeKey(string $scope, array $ids): string
    {
        return hash_hmac('sha256', json_encode([$ids['user_id'], $ids['tenant_id'] ?? null, $scope,
            $scope === 'project' ? ($ids['project_id'] ?? null) : null], JSON_THROW_ON_ERROR), (string) config('app.key'));
    }

    public function changes(string $scope, array $ids, ?string $cursor, int $limit = 50): array
    {
        $key = $this->scopeKey($scope, $ids);
        $after = 0;
        if ($cursor !== null) {
            try {
                $decoded = json_decode(Crypt::decryptString($cursor), true, 32, JSON_THROW_ON_ERROR);
                if (($decoded['version'] ?? null) !== 1 || ($decoded['scope'] ?? null) !== $key
                    || ! is_int($decoded['sequence'] ?? null) || $decoded['sequence'] < 0) {
                    throw new \RuntimeException('Invalid cursor binding.');
                }
                $after = $decoded['sequence'];
            } catch (Throwable) {
                throw ValidationException::withMessages(['cursor' => 'Invalid memory cursor for this account and scope. Restart without a cursor.']);
            }
        }

        return DB::transaction(function () use ($scope, $ids, $key, $after, $limit, $cursor): array {
            DB::table('memory_sync_scopes')->insertOrIgnore(['id' => $key, 'user_id' => $ids['user_id'], 'sequence' => 0]);
            $state = DB::table('memory_sync_scopes')->where('id', $key)->lockForUpdate()->first();
            // Reconcile against canonical SQL, including direct maintenance writes and bulk revocations.
            // A per-scope row lock serializes sequence assignment with commit, avoiding auto-ID gaps.
            $current = [];
            foreach ($this->memory->syncQuery($scope, $ids)->orderBy('id')->cursor() as $link) {
                $payload = $this->payload($link);
                if ($payload !== null) {
                    $current[$link->logicalExternalId()] = ['link' => $link, 'payload' => $payload];
                }
            }
            $entries = DB::table('memory_sync_entries')->where('scope_key', $key)->get()->keyBy('record_id');
            $sequence = (int) $state->sequence;
            foreach ($current as $id => $item) {
                $versionPayload = $item['payload'];
                // Projection workers update the SQL timestamp without changing canonical memory.
                unset($versionPayload['updated_at']);
                $fingerprint = hash('sha256', json_encode($versionPayload, JSON_THROW_ON_ERROR));
                $old = $entries->get($id);
                if ($old && hash_equals($old->fingerprint, $fingerprint)) {
                    continue;
                }
                DB::table('memory_sync_entries')->updateOrInsert(['scope_key' => $key, 'record_key' => hash('sha256', (string) $id)],
                    ['record_id' => $id, 'memory_link_id' => $item['link']->id, 'fingerprint' => $fingerprint]);
                DB::table('memory_sync_changes')->insert(['scope_key' => $key, 'sequence' => ++$sequence,
                    'record_id' => $id, 'memory_link_id' => $item['link']->id, 'operation' => 'upsert']);
            }
            foreach ($entries as $id => $old) {
                if (isset($current[$id])) {
                    continue;
                }
                DB::table('memory_sync_changes')->insert(['scope_key' => $key, 'sequence' => ++$sequence,
                    'record_id' => $id, 'memory_link_id' => $old->memory_link_id, 'operation' => 'delete']);
                DB::table('memory_sync_entries')->where('id', $old->id)->delete();
            }
            DB::table('memory_sync_scopes')->where('id', $key)->update(['sequence' => $sequence]);
            if ($after > $sequence) {
                throw ValidationException::withMessages(['cursor' => 'Memory cursor is ahead of canonical state. Restart without a cursor.']);
            }
            $rows = DB::table('memory_sync_changes')->where('scope_key', $key)->where('sequence', '>', $after)
                ->orderBy('sequence')->limit(max(1, min(100, $limit)) + 1)->get();
            $more = $rows->count() > $limit;
            $changes = [];
            $responseBytes = 0;
            foreach ($rows->take($limit) as $row) {
                $item = $current[$row->record_id] ?? null;
                // Never return superseded/deleted content, even when replaying an old cursor.
                $payload = $row->operation === 'upsert' && $item && $item['link']->id === (int) $row->memory_link_id
                    ? $item['payload'] : null;
                $change = ['sequence' => (int) $row->sequence, 'operation' => $payload ? 'upsert' : 'delete',
                    'record_id' => $row->record_id, 'source_record_id' => (string) $row->memory_link_id,
                    ...($payload ? ['memory' => $payload] : [])];
                $bytes = strlen(json_encode($change, JSON_THROW_ON_ERROR));
                if ($changes !== [] && $responseBytes + $bytes > 1024 * 1024) {
                    $more = true;
                    break;
                }
                $changes[] = $change;
                $responseBytes += $bytes;
                $after = (int) $row->sequence;
            }

            return ['version' => 1, 'cursor' => Crypt::encryptString(json_encode(['version' => 1, 'scope' => $key, 'sequence' => $after], JSON_THROW_ON_ERROR)),
                'has_more' => $more, 'changes' => $changes, 'reset' => $cursor === null];
        }, 3);
    }

    public function payload(MemoryLink $row): ?array
    {
        if (! MemoryProjectionPolicy::isActiveWithinValidity($row)
            || ! in_array($row->visibility, ['syncable', 'public'], true) || $row->sensitivity !== 'normal') {
            return null;
        }
        $payload = ['id' => $row->logicalExternalId(), 'content' => $row->summary, 'content_hash' => $row->content_hash,
            'scope' => $row->scope, 'project_id' => $row->project_id, 'type' => $row->type, 'status' => $row->status,
            'visibility' => $row->visibility, 'retention' => $row->retention, 'sensitivity' => $row->sensitivity,
            'importance' => (float) $row->importance, 'confidence' => (float) $row->confidence,
            'tags' => $row->meta['tags'] ?? [], 'updated_at' => $row->updated_at?->toIso8601String(),
            'feature_key' => $row->feature_key, 'source' => 'sql', 'source_record_id' => (string) $row->id,
            'provenance' => $row->provenance, 'meta' => $row->meta, 'recorded_at' => $row->recorded_at?->toIso8601String(),
            'valid_from' => $row->valid_from?->toIso8601String(), 'valid_until' => $row->valid_until?->toIso8601String(),
            'expires_at' => $row->expires_at?->toIso8601String()];
        $policyPayload = [...$payload, 'external_id' => $row->logicalExternalId(), 'source_type' => $row->source_type, 'source_ref' => $row->source_ref];

        return MemoryDlp::containsSecretInMemoryPayload($policyPayload) || MemoryDlp::containsLocalOnlySourceInMemoryPayload($policyPayload) ? null : $payload;
    }

    public function forget(string $scope, array $ids, string $externalId, MemoryOrchestrator $orchestrator): array
    {
        return DB::transaction(function () use ($scope, $ids, $externalId, $orchestrator): array {
            abort_unless(User::query()->whereKey($ids['user_id'])->lockForUpdate()->first(), 401);
            $query = $this->memory->syncQuery($scope, $ids);
            $seed = (clone $query)->where(fn ($q) => $q->where('external_id', $externalId)->orWhere('meta->source_external_id', $externalId))->first();
            $memoryIds = [];
            if ($seed) {
                $logical = $seed->logicalExternalId();
                $feature = $seed->feature_key ?: ($seed->meta['memory_key'] ?? null);
                $memoryIds = (clone $query)->where(function ($q) use ($logical, $feature): void {
                    $q->where('external_id', $logical)->orWhere('meta->source_external_id', $logical);
                    if ($feature) {
                        $q->orWhere('feature_key', $feature)->orWhere('meta->memory_key', $feature);
                    }
                })->pluck('id')->all();
            }
            $forgotten = $orchestrator->forget($scope, $externalId, $ids);
            $identity = hash('sha256', $this->scopeKey($scope, $ids).'|'.$externalId);
            $receipt = $memoryIds === [] ? DB::table('memory_deletion_receipts')->where('identity_key', $identity)->orderByDesc('memory_version')->latest('created_at')->first() : null;
            if (! $receipt) {
                $id = (string) Str::uuid();
                DB::table('memory_deletion_receipts')->insert(['id' => $id, 'user_id' => $ids['user_id'], 'identity_key' => $identity,
                    'memory_version' => $memoryIds === [] ? 0 : max($memoryIds),
                    'memory_ids' => json_encode($memoryIds, JSON_THROW_ON_ERROR), 'created_at' => now(), 'updated_at' => now()]);
            } else {
                $id = $receipt->id;
            }

            return ['ok' => true, 'forgotten' => $forgotten, 'already_absent' => ! $forgotten,
                'deletion_receipt' => $this->receipt($id, (int) $ids['user_id'])];
        }, 3);
    }

    public function receipt(string $id, int $userId): array
    {
        $receipt = DB::table('memory_deletion_receipts')->where('id', $id)->where('user_id', $userId)->first();
        abort_unless($receipt, 404);
        $rows = MemoryProjectionOutbox::query()->where('user_id', $userId)
            ->whereIn('memory_link_id', json_decode($receipt->memory_ids, true, 512, JSON_THROW_ON_ERROR))->get();
        $status = $rows->contains(fn ($row) => $row->status === 'failed') ? 'blocked'
            : ($rows->contains(fn ($row) => $row->status !== 'done') ? 'projection_pending' : 'complete');

        return ['id' => $id, 'status' => $status, 'canonical_erased' => true,
            'projection_outbox_ids' => $rows->map(fn ($row) => (string) $row->id)->all()];
    }
}
