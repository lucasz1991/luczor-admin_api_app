<?php

namespace App\Services;

use App\Models\Policy;
use App\Models\Repository;

class GitWritePolicy
{
    public function assertBranchWritable(Repository $repository, string $branch, bool $force = false): void
    {
        abort_if($force, 422, 'Force pushes are forbidden by Luczor policy.');
        if ($branch !== $repository->default_branch) {
            return;
        }

        $allowsDefault = Policy::query()
            ->where('type', 'git')
            ->where('active', true)
            ->where(fn ($query) => $query->whereNull('user_id')->orWhere('user_id', $repository->user_id))
            ->where(fn ($query) => $query->whereNull('project_id')->orWhere('project_id', $repository->project_id))
            ->get()
            ->contains(fn (Policy $policy) => (bool) (($policy->rules ?? [])['allow_default_branch_writes'] ?? false));

        abort_unless($allowsDefault, 403, 'Direct writes to the default branch require an explicit git policy.');
    }
}
