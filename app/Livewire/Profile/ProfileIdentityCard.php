<?php

namespace App\Livewire\Profile;

use App\Models\User;
use Illuminate\View\View;
use Laravel\Fortify\Contracts\UpdatesUserProfileInformation;
use Livewire\Component;

/** Adapted from RailTime's ProfileIdentityCard; the existing Fortify action owns validation. */
class ProfileIdentityCard extends Component
{
    public string $name = '';

    public string $email = '';

    public function boot(): void
    {
        abort_unless(auth()->user()?->fresh()?->isActive(), 403);
    }

    public function mount(): void
    {
        $this->syncIdentity();
    }

    public function saveIdentity(UpdatesUserProfileInformation $updater): void
    {
        $this->resetErrorBag();
        $updater->update(auth()->user(), ['name' => $this->name, 'email' => $this->email]);
        $this->syncIdentity();
        $this->dispatch('identity-saved');
        session()->flash('identity-status', 'Profil gespeichert.');
        if (! auth()->user()->fresh()->hasVerifiedEmail()) {
            $this->redirectRoute('verification.notice');
        }
    }

    public function render(): View
    {
        return view('livewire.profile.profile-identity-card', [
            'user' => User::withCount(['devices', 'projects'])->findOrFail(auth()->id()),
        ]);
    }

    private function syncIdentity(): void
    {
        $user = auth()->user()->fresh();
        $this->name = $user->name;
        $this->email = $user->email;
    }
}
