<?php

namespace App\Livewire\Admin;

use App\Models\LlmRun;
use App\Models\User;
use App\Services\AdminUserManagement;
use Illuminate\View\View;
use Livewire\Attributes\Locked;
use Livewire\Component;

/** RailTime UserProfile identity/sections adapted to Luczor accounts and usage. */
class UserProfile extends Component
{
    #[Locked]
    public int $userId;

    public string $name = '';

    public string $email = '';

    public bool $active = true;

    public string $tab = 'overview';

    public function boot(): void
    {
        app(AdminUserManagement::class)->authorize(auth()->user());
    }

    public function mount(int $userId): void
    {
        $this->userId = $userId;
        $this->syncIdentity(User::findOrFail($userId));
    }

    public function selectTab(string $tab): void
    {
        if (in_array($tab, ['overview', 'account', 'usage'], true)) {
            $this->tab = $tab;
        }
    }

    public function save(AdminUserManagement $manager): void
    {
        $this->resetErrorBag();
        $user = $manager->update(auth()->user(), $this->userId, ['name' => $this->name, 'email' => $this->email, 'status' => $this->active]);
        $this->syncIdentity($user);
        session()->flash('status', 'Benutzerprofil gespeichert. Der Kontostatus gilt für alle zugeordneten Geräte.');
    }

    public function render(): View
    {
        $user = User::withCount(['devices', 'projects'])->findOrFail($this->userId);
        $runs = LlmRun::where('user_id', $user->id)->where('created_at', '>=', now()->subDays(30));
        $usage = (clone $runs)->selectRaw('COUNT(*) AS runs, COALESCE(SUM(estimated_cost_usd), 0) AS estimated_usd, COALESCE(SUM(CASE WHEN estimated_cost_usd IS NULL THEN 1 ELSE 0 END), 0) AS unknown_costs')->first();

        return view('livewire.admin.user-profile', [
            'user' => $user,
            'lastSeen' => $user->devices()->whereNull('revoked_at')->max('last_seen_at'),
            'usage' => $usage,
            'recentRuns' => $this->tab === 'usage' ? (clone $runs)->latest()->limit(8)->get(['id', 'created_at', 'model_id', 'status', 'estimated_cost_usd']) : collect(),
        ]);
    }

    private function syncIdentity(User $user): void
    {
        $this->name = $user->name;
        $this->email = $user->email;
        $this->active = $user->isActive();
    }
}
