<?php

namespace App\Observers;

use App\Models\User;
use App\Services\AccountMemoryErasureService;

final class UserObserver
{
    public function __construct(private AccountMemoryErasureService $memoryErasure) {}

    public function deleting(User $user): void
    {
        $this->memoryErasure->eraseBeforeDelete($user);
    }
}
