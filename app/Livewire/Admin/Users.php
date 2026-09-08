<?php

namespace App\Livewire\Admin;

use App\Models\User;
use App\Services\AdminUserManagement;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\View\View;
use Livewire\Component;
use Livewire\WithPagination;

/** Adapted from RailTime Admin/Employees: filter toolbar, guarded sort and pagination. */
class Users extends Component
{
    use WithPagination;

    public string $search = '';

    public string $role = '';

    public string $accountStatus = '';

    public string $sortBy = 'created_at';

    public string $sortDir = 'desc';

    public int $perPage = 15;

    public bool $creating = false;

    public array $newUser = ['name' => '', 'email' => '', 'password' => '', 'password_confirmation' => ''];

    private const SORTABLE_COLUMNS = ['name', 'email', 'created_at'];

    public function boot(): void
    {
        app(AdminUserManagement::class)->authorize(auth()->user());
    }

    public function updated(string $property): void
    {
        if (in_array($property, ['search', 'role', 'accountStatus', 'perPage'], true)) {
            $this->resetPage();
        }
    }

    public function sort(string $column): void
    {
        if (! in_array($column, self::SORTABLE_COLUMNS, true)) {
            return;
        }
        $this->sortDir = $this->sortBy === $column && $this->sortDir === 'asc' ? 'desc' : 'asc';
        $this->sortBy = $column;
        $this->resetPage();
    }

    public function resetFilters(): void
    {
        $this->reset(['search', 'role', 'accountStatus']);
        $this->resetPage();
    }

    public function openCreate(): void
    {
        $this->resetErrorBag();
        $this->reset('newUser');
        $this->creating = true;
    }

    public function closeCreate(): void
    {
        $this->reset(['creating', 'newUser']);
        $this->resetErrorBag();
    }

    public function createUser(AdminUserManagement $manager): void
    {
        try {
            $user = $manager->create(auth()->user(), $this->newUser);
        } finally {
            // Never retain password fields in the next Livewire snapshot.
            $this->newUser['password'] = '';
            $this->newUser['password_confirmation'] = '';
        }
        $this->closeCreate();
        session()->flash('status', 'Benutzer angelegt. Der Nutzer bestätigt seine E-Mail beim Anmelden.');
        $this->redirectRoute('admin.users.show', ['user' => $user->id]);
    }

    public function render(): View
    {
        $base = User::query()
            ->withCount(['devices', 'projects'])
            ->withMax(['devices as last_device_seen_at' => fn (Builder $query) => $query->whereNull('revoked_at')], 'last_seen_at');

        $search = mb_substr(trim($this->search), 0, 160);
        $base->when($search !== '', function (Builder $query) use ($search): void {
            // LIKE metacharacters remain literal so a filter cannot accidentally show every account.
            $pattern = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $search).'%';
            $query->where(fn (Builder $match) => $match->whereRaw("name LIKE ? ESCAPE '!'", [$pattern])->orWhereRaw("email LIKE ? ESCAPE '!'", [$pattern]));
        });
        $base->when(in_array($this->role, ['user', 'admin'], true), function (Builder $query): void {
            $this->role === 'admin' ? $query->whereIn('role', ['admin', 'superadmin']) : $query->where('role', 'user');
        });
        $base->when(in_array($this->accountStatus, ['active', 'inactive'], true), fn (Builder $query) => $query->where('status', $this->accountStatus === 'active'));

        $sortBy = in_array($this->sortBy, self::SORTABLE_COLUMNS, true) ? $this->sortBy : 'created_at';
        $sortDir = $this->sortDir === 'asc' ? 'asc' : 'desc';
        $perPage = in_array($this->perPage, [15, 30, 50], true) ? $this->perPage : 15;

        return view('livewire.admin.users', [
            'users' => $base->orderBy($sortBy, $sortDir)->orderBy('id')->paginate($perPage),
            'counts' => [
                'total' => User::count(),
                'active' => User::where('status', true)->count(),
                'inactive' => User::where('status', false)->count(),
            ],
        ]);
    }
}
