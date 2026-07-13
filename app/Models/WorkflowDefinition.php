<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

class WorkflowDefinition extends Model
{
    protected $fillable = ['user_id', 'project_id', 'name', 'version', 'status', 'is_locked', 'definition'];
    protected $casts = ['definition' => 'array', 'is_locked' => 'boolean'];

    protected static function booted(): void
    {
        // Keep the include-graph in sync with the definition's 'workflow' steps.
        static::saved(fn (self $def) => $def->syncDependencies());
    }

    public function runs()
    {
        return $this->hasMany(WorkflowRun::class);
    }

    /** Definitions this one embeds (parent → child). */
    public function includedDefinitions()
    {
        return $this->belongsToMany(self::class, 'workflow_definition_dependencies', 'parent_definition_id', 'child_definition_id');
    }

    /** Definitions that embed this one (child → parent). */
    public function includedByDefinitions()
    {
        return $this->belongsToMany(self::class, 'workflow_definition_dependencies', 'child_definition_id', 'parent_definition_id');
    }

    public function getIsIncludedAttribute(): bool
    {
        return $this->includedByDefinitions()->exists();
    }

    /** Locked explicitly, or embedded by another workflow (must stay stable). */
    public function getIsEditLockedAttribute(): bool
    {
        return (bool) $this->is_locked || $this->is_included;
    }

    /** Rebuild the include-graph pivot from this definition's 'workflow' steps. */
    public function syncDependencies(): void
    {
        if (! $this->exists) {
            return;
        }
        $childIds = collect($this->definition['steps'] ?? [])
            ->filter(fn ($step) => is_array($step) && ($step['type'] ?? null) === 'workflow')
            ->map(fn ($step) => (int) ($step['payload']['workflow_definition_id'] ?? 0))
            ->filter(fn ($id) => $id > 0 && $id !== $this->id)
            ->unique()
            ->values()
            ->all();
        $this->includedDefinitions()->sync($childIds);
    }

    /** BFS: does this definition (transitively) embed $definitionId? Cycle-safe. */
    public function includesDefinition(int $definitionId): bool
    {
        $visited = [];
        $queue = [$this->id];
        while ($queue !== []) {
            $current = array_shift($queue);
            if (isset($visited[$current])) {
                continue;
            }
            $visited[$current] = true;
            $children = DB::table('workflow_definition_dependencies')
                ->where('parent_definition_id', $current)
                ->pluck('child_definition_id')
                ->all();
            foreach ($children as $child) {
                if ((int) $child === $definitionId) {
                    return true;
                }
                $queue[] = (int) $child;
            }
        }

        return false;
    }
}
