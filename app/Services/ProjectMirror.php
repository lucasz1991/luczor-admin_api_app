<?php

namespace App\Services;

use App\Models\Device;
use App\Models\DeviceJob;
use App\Models\Project;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/** Immutable encrypted full-folder snapshots. Names are data, never server filesystem paths. */
class ProjectMirror
{
    public const CHUNK_BYTES = 8_388_608;

    public function __construct(private DeviceLeadership $leadership) {}

    public function owned(Device $device, Project $project): void
    {
        abort_unless((int) $project->user_id === (int) $device->user_id, 404);
    }

    public function head(Project $project): array
    {
        $row = DB::table('project_mirror_heads')->where('project_id', $project->id)->first();

        return ['project_id' => $project->id, 'external_id' => $project->external_id, 'revision' => (int) ($row->revision ?? 0),
            'manifest_id' => $row?->manifest_id, 'chunk_bytes' => self::CHUNK_BYTES, 'schema_version' => 1];
    }

    public function lease(Device $device, Project $project, array $data): array
    {
        $this->owned($device, $project);

        return DB::transaction(function () use ($device, $project, $data) {
            $this->leadership->fence($device, $data['master_epoch']);
            $this->lockProject($project);
            $head = DB::table('project_mirror_heads')->where('project_id', $project->id)->first();
            abort_unless((int) $head->revision === $data['expected_revision'], 409, 'mirror_revision_conflict');
            $id = (int) $head->master_epoch === $data['master_epoch'] && $head->lease_id ? $head->lease_id : (string) Str::uuid();
            $expiry = now()->addSeconds(DeviceLeadership::LEASE_SECONDS);
            DB::table('project_mirror_heads')->where('project_id', $project->id)->update([
                'lease_id' => $id, 'master_epoch' => $data['master_epoch'], 'lease_expires_at' => $expiry, 'updated_at' => now()]);

            return ['lease_id' => $id, 'master_epoch' => $data['master_epoch'], 'expires_at' => $expiry->toIso8601String(), 'revision' => (int) $head->revision];
        }, 3);
    }

    private function lockProject(Project $project): void
    {
        Project::whereKey($project->id)->lockForUpdate()->firstOrFail();
        DB::table('project_mirror_heads')->insertOrIgnore(['project_id' => $project->id, 'revision' => 0, 'created_at' => now(), 'updated_at' => now()]);
    }

    public function putChunk(Device $device, Project $project, string $sha, string $content): array
    {
        $this->owned($device, $project);
        abort_unless(strlen($content) <= self::CHUNK_BYTES && hash_equals($sha, hash('sha256', $content)), 422, 'mirror_chunk_integrity_invalid');

        return DB::transaction(function () use ($project, $sha, $content) {
            $this->lockProject($project);
            $existing = DB::table('project_mirror_chunks')->where('project_id', $project->id)->where('sha256', $sha)->first();
            if ($existing) {
                abort_unless((int) $existing->size === strlen($content), 409, 'mirror_chunk_conflict');

                return ['sha256' => $sha, 'size' => (int) $existing->size, 'replayed' => true];
            }
            $key = 'mirror/'.$project->id.'/'.Str::uuid();
            $disk = Storage::disk('cloud-projects');
            abort_unless($disk->put($key, Crypt::encryptString($content)), 503, 'mirror_storage_unavailable');
            try {
                DB::table('project_mirror_chunks')->insert(['project_id' => $project->id, 'sha256' => $sha,
                    'size' => strlen($content), 'storage_key' => $key, 'created_at' => now()]);
            } catch (\Throwable $error) {
                $disk->delete($key);
                throw $error;
            }

            return ['sha256' => $sha, 'size' => strlen($content), 'replayed' => false];
        }, 3);
    }

    public function chunk(Project $project, string $sha): string
    {
        $row = DB::table('project_mirror_chunks')->where('project_id', $project->id)->where('sha256', $sha)->first();
        abort_unless($row !== null, 404);
        $content = Crypt::decryptString(Storage::disk('cloud-projects')->get($row->storage_key));
        abort_unless(strlen($content) === (int) $row->size && hash_equals($sha, hash('sha256', $content)), 503, 'mirror_stored_chunk_corrupt');

        return $content;
    }

    public function create(Device $device, Project $project, array $data): array
    {
        $this->owned($device, $project);

        return DB::transaction(function () use ($device, $project, $data) {
            $proposal = (bool) ($data['proposal'] ?? false);
            if (! $proposal) {
                $this->leadership->fence($device, (int) ($data['master_epoch'] ?? 0));
            } else {
                $job = DeviceJob::where('user_id', $device->user_id)->where('device_id', $device->id)
                    ->where('project_id', $project->id)->where('public_id', $data['job_id'] ?? '')->where('protocol_version', 2)->firstOrFail();
                abort_unless(in_array($job->status, ['running', 'completed', 'failed', 'cancelling'], true), 409, 'mirror_proposal_job_not_started');
            }
            $this->lockProject($project);
            $hash = CoordinatedDeviceJobs::hash($data);
            $existing = DB::table('project_mirror_manifests')->where('project_id', $project->id)->where('device_id', $device->id)
                ->where('operation_id', $data['operation_id'])->first();
            if ($existing) {
                abort_unless(hash_equals($existing->request_hash, $hash), 409, 'mirror_operation_conflict');

                return $this->metadata($existing);
            }
            $head = $this->head($project);
            abort_unless($data['base_revision'] <= $head['revision'], 409, 'mirror_base_revision_unknown');
            $id = (string) Str::uuid();
            DB::table('project_mirror_manifests')->insert(['id' => $id, 'project_id' => $project->id, 'device_id' => $device->id,
                'operation_id' => $data['operation_id'], 'request_hash' => $hash, 'base_revision' => $data['base_revision'],
                'master_epoch' => $data['master_epoch'] ?? null, 'job_id' => $data['job_id'] ?? null,
                'status' => 'draft', 'created_at' => now(), 'updated_at' => now()]);
            $this->append($device, $project, $id, ['operation_id' => $data['operation_id'], 'entries' => $data['entries'] ?? []]);
            if (! ($data['draft'] ?? false)) {
                $this->seal($project, $id, $proposal ? 'proposed' : 'sealed');
                if (! $proposal) {
                    return $this->publish($device, $project, $id, ['operation_id' => (string) Str::uuid(),
                        'master_epoch' => $data['master_epoch'], 'lease_id' => $data['lease_id'] ?? '', 'expected_revision' => $data['base_revision']]);
                }
            }

            return $this->metadata($this->manifest($project, $id));
        }, 3);
    }

    public function append(Device $device, Project $project, string $id, array $data): array
    {
        return DB::transaction(function () use ($device, $project, $id, $data) {
            $this->owned($device, $project);
            $this->lockProject($project);
            $manifest = $this->manifest($project, $id);
            abort_unless((int) $manifest->device_id === (int) $device->id, 404);
            $hash = CoordinatedDeviceJobs::hash($data);
            if ($this->operation($id, $data['operation_id'], 'append', $hash)) {
                return $this->metadata($manifest);
            }
            abort_unless($manifest->status === 'draft', 409, 'mirror_manifest_immutable');
            $count = 0;
            $bytes = 0;
            foreach ($data['entries'] as $entry) {
                $this->validateEntry($project, $entry);
                $pathHash = hash('sha256', $entry['path']); // Exact names; case variants are retained.
                abort_if(DB::table('project_mirror_entries')->where('manifest_id', $id)->where('path_hash', $pathHash)->exists(), 409, 'mirror_duplicate_path');
                DB::table('project_mirror_entries')->insert(['manifest_id' => $id, 'path_hash' => $pathHash,
                    'entry_hash' => CoordinatedDeviceJobs::hash($entry),
                    'entry' => Crypt::encryptString(AutomationGrantService::canonicalJson($entry))]);
                $count++;
                $bytes += $entry['type'] === 'file' ? $entry['size'] : 0;
            }
            DB::table('project_mirror_manifests')->where('id', $id)->update(['entry_count' => $manifest->entry_count + $count,
                'total_bytes' => $manifest->total_bytes + $bytes, 'updated_at' => now()]);

            return $this->metadata($this->manifest($project, $id));
        }, 3);
    }

    private function validateEntry(Project $project, array $entry): void
    {
        $path = $entry['path'];
        abort_if($path === '' || str_starts_with($path, '/') || str_contains($path, "\0")
            || count(array_intersect(explode('/', $path), ['', '.', '..'])) > 0, 422, 'mirror_relative_path_required');
        // Backslashes, case variants, .env, .git, reserved names and binary data are preserved as opaque source data.
        if ($entry['type'] !== 'file') {
            return;
        }
        $size = 0;
        $hash = hash_init('sha256');
        foreach ($entry['chunks'] as $chunk) {
            $content = $this->chunk($project, $chunk['sha256']);
            abort_unless(strlen($content) === $chunk['size'], 422, 'mirror_chunk_size_invalid');
            $size += strlen($content);
            hash_update($hash, $content);
        }
        abort_unless($size === $entry['size'] && hash_equals($entry['sha256'], hash_final($hash)), 422, 'mirror_file_integrity_invalid');
    }

    public function seal(Project $project, string $id, string $status = 'proposed'): void
    {
        $hash = hash_init('sha256');
        foreach (DB::table('project_mirror_entries')->where('manifest_id', $id)->orderBy('path_hash')->cursor() as $row) {
            hash_update($hash, Crypt::decryptString($row->entry)."\n");
        }
        DB::table('project_mirror_manifests')->where('project_id', $project->id)->where('id', $id)->where('status', 'draft')
            ->update(['status' => $status, 'manifest_hash' => hash_final($hash), 'updated_at' => now()]);
    }

    public function propose(Device $device, Project $project, string $id): array
    {
        return DB::transaction(function () use ($device, $project, $id) {
            $this->owned($device, $project);
            $this->lockProject($project);
            $manifest = $this->manifest($project, $id);
            abort_unless((int) $manifest->device_id === (int) $device->id, 404);
            $this->seal($project, $id);

            return $this->metadata($this->manifest($project, $id));
        }, 3);
    }

    public function publish(Device $device, Project $project, string $id, array $data): array
    {
        return DB::transaction(function () use ($device, $project, $id, $data) {
            $this->owned($device, $project);
            $this->leadership->fence($device, $data['master_epoch']);
            $this->lockProject($project);
            $manifest = $this->manifest($project, $id);
            $hash = CoordinatedDeviceJobs::hash($data);
            if ($this->operation($id, $data['operation_id'], 'publish', $hash)) {
                return $this->metadata($manifest->merged_manifest_id ? $this->manifest($project, $manifest->merged_manifest_id) : $manifest);
            }
            abort_unless(in_array($manifest->status, ['draft', 'proposed', 'sealed'], true), 409, 'mirror_manifest_already_published');
            $head = DB::table('project_mirror_heads')->where('project_id', $project->id)->first();
            abort_unless($head->lease_id === $data['lease_id'] && (int) $head->master_epoch === $data['master_epoch']
                && $head->lease_expires_at && Carbon::parse($head->lease_expires_at)->isFuture(), 409, 'mirror_lease_expired');
            abort_unless((int) $head->revision === $data['expected_revision'], 409, 'mirror_revision_conflict');
            if ((int) $manifest->base_revision !== $data['expected_revision']) {
                abort_unless($manifest->status === 'proposed', 409, 'mirror_revision_conflict');
                $id = $this->mergeProposal($device, $project, $manifest, $head, $data);
            }
            $this->seal($project, $id, 'sealed');
            DB::table('project_mirror_manifests')->where('id', $id)->update(['status' => 'published', 'revision' => $head->revision + 1, 'updated_at' => now()]);
            DB::table('project_mirror_heads')->where('project_id', $project->id)->update(['manifest_id' => $id, 'revision' => $head->revision + 1, 'updated_at' => now()]);

            return $this->metadata($this->manifest($project, $id));
        }, 3);
    }

    private function operation(string $manifest, string $id, string $action, string $hash): bool
    {
        $previous = DB::table('project_mirror_operations')->where('manifest_id', $manifest)->where('operation_id', $id)->first();
        if ($previous) {
            abort_unless($previous->action === $action && hash_equals($previous->request_hash, $hash), 409, 'mirror_operation_conflict');

            return true;
        }
        DB::table('project_mirror_operations')->insert(['manifest_id' => $manifest, 'operation_id' => $id, 'action' => $action, 'request_hash' => $hash]);

        return false;
    }

    public function manifest(Project $project, string $id): object
    {
        $row = DB::table('project_mirror_manifests')->where('project_id', $project->id)->where('id', $id)->first();
        abort_unless($row !== null, 404);

        return $row;
    }

    public function metadata(object $row): array
    {
        return ['manifest_id' => $row->id, 'project_id' => (int) $row->project_id, 'revision' => $row->revision === null ? null : (int) $row->revision,
            'base_revision' => (int) $row->base_revision, 'status' => $row->status, 'manifest_hash' => $row->manifest_hash,
            'entry_count' => (int) $row->entry_count, 'total_bytes' => (int) $row->total_bytes, 'job_id' => $row->job_id,
            'merged_manifest_id' => $row->merged_manifest_id,
            'device_id' => Device::whereKey($row->device_id)->value('device_id')];
    }

    /** Three-way merge by exact entry identity. Divergent overlapping changes never overwrite the head. */
    private function mergeProposal(Device $device, Project $project, object $proposal, object $head, array $data): string
    {
        $base = $proposal->base_revision == 0 ? null : DB::table('project_mirror_manifests')->where('project_id', $project->id)->where('revision', $proposal->base_revision)->first();
        abort_if($proposal->base_revision != 0 && ! $base, 409, 'mirror_base_revision_unknown');
        $id = (string) Str::uuid();
        DB::table('project_mirror_manifests')->insert(['id' => $id, 'project_id' => $project->id, 'device_id' => $device->id,
            'operation_id' => (string) Str::uuid(), 'request_hash' => CoordinatedDeviceJobs::hash($data),
            'base_revision' => $head->revision, 'master_epoch' => $data['master_epoch'], 'status' => 'draft',
            'created_at' => now(), 'updated_at' => now()]);
        $ids = array_values(array_filter([$base?->id, $proposal->id, $head->manifest_id]));
        $paths = DB::table('project_mirror_entries')->whereIn('manifest_id', $ids)->select('path_hash')->distinct();
        $query = DB::query()->fromSub($paths, 'paths');
        foreach (['b' => $base?->id, 'p' => $proposal->id, 'c' => $head->manifest_id] as $alias => $manifestId) {
            $query->leftJoin('project_mirror_entries as '.$alias, fn ($join) => $join->on($alias.'.path_hash', '=', 'paths.path_hash')->where($alias.'.manifest_id', $manifestId ?? ''));
        }
        $query->select(['paths.path_hash', 'b.entry_hash as base_hash', 'p.entry_hash as proposal_hash', 'c.entry_hash as current_hash',
            'p.entry as proposal_entry', 'c.entry as current_entry', 'b.entry as base_entry']);
        $last = '';
        $count = 0;
        $bytes = 0;
        while (true) {
            $rows = (clone $query)->where('paths.path_hash', '>', $last)->orderBy('paths.path_hash')->limit(500)->get();
            if ($rows->isEmpty()) {
                break;
            }
            foreach ($rows as $row) {
                $last = $row->path_hash;
                if ($row->proposal_hash !== $row->base_hash && $row->current_hash !== $row->base_hash && $row->proposal_hash !== $row->current_hash) {
                    $entry = json_decode(Crypt::decryptString($row->proposal_entry ?? $row->current_entry ?? $row->base_entry), true);
                    throw new HttpResponseException(response()->json([
                        'code' => 'mirror_merge_conflict', 'message' => 'Overlapping file changes require a master decision.',
                        'data' => ['paths' => [$entry['path']], 'proposal_manifest_id' => $proposal->id, 'current_manifest_id' => $head->manifest_id,
                            'base_manifest_id' => $base?->id, 'current_revision' => (int) $head->revision]], 409));
                }
                $useProposal = $row->proposal_hash !== $row->base_hash;
                $encrypted = $useProposal ? $row->proposal_entry : $row->current_entry;
                $entryHash = $useProposal ? $row->proposal_hash : $row->current_hash;
                if ($encrypted !== null) {
                    $entry = json_decode(Crypt::decryptString($encrypted), true);
                    DB::table('project_mirror_entries')->insert(['manifest_id' => $id, 'path_hash' => $row->path_hash,
                        'entry_hash' => $entryHash, 'entry' => $encrypted]);
                    $count++;
                    $bytes += $entry['type'] === 'file' ? $entry['size'] : 0;
                }
            }
        }
        DB::table('project_mirror_manifests')->where('id', $id)->update(['entry_count' => $count, 'total_bytes' => $bytes]);
        DB::table('project_mirror_manifests')->where('id', $proposal->id)->update(['status' => 'accepted', 'merged_manifest_id' => $id, 'updated_at' => now()]);

        return $id;
    }
}
