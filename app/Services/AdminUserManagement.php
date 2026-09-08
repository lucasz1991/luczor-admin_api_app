<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

class AdminUserManagement
{
    public function authorize(?User $actor): User
    {
        $actor = $actor?->fresh();
        abort_unless($actor?->isAdmin() && $actor->isActive() && $actor->hasVerifiedEmail(), 403);

        return $actor;
    }

    public function create(User $actor, array $input): User
    {
        $actor = $this->authorize($actor);
        $data = Validator::make($input, [
            'name' => ['required', 'string', 'max:160'],
            'email' => ['required', 'email', 'max:255', Rule::unique('users')],
            'password' => ['required', 'string', 'confirmed', Password::min(12)],
        ])->validate();

        return DB::transaction(function () use ($actor, $data): User {
            $user = User::create($data + ['tenant_id' => $actor->tenant_id, 'role' => 'user', 'status' => true]);
            $this->audit($actor, $user, 'user.created');

            return $user;
        });
    }

    public function update(User $actor, int $userId, array $input): User
    {
        $actor = $this->authorize($actor);

        return DB::transaction(function () use ($actor, $userId, $input): User {
            $user = User::query()->lockForUpdate()->findOrFail($userId);
            abort_if($user->isAdmin(), 403, 'Admin-Konten werden über die bestehende Kontoverwaltung gepflegt.');
            $data = Validator::make($input, [
                'name' => ['required', 'string', 'max:160'],
                'email' => ['required', 'email', 'max:255', Rule::unique('users')->ignore($user->id)],
                'status' => ['required', 'boolean'],
            ])->validate();
            if ($data['email'] !== $user->email) {
                $user->email_verified_at = null;
            }
            $user->fill($data)->save();
            $this->audit($actor, $user, 'user.updated');

            return $user;
        });
    }

    private function audit(User $actor, User $subject, string $eventType): void
    {
        app(AuditLogger::class)->record([
            'actor_user_id' => $actor->id,
            'event_type' => $eventType,
            'outcome' => 'success',
            'payload' => ['subject_user_id' => $subject->id, 'active' => $subject->isActive()],
        ]);
    }
}
