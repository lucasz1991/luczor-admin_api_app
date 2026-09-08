<?php

namespace App\Livewire\Account;

use App\Models\Conversation;
use App\Models\Device;
use App\Models\User;
use App\Models\WebWorkspaceChat;
use App\Services\WebWorkspaceService;
use Illuminate\Support\Str;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Livewire\WithPagination;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

class Workspace extends Component
{
    use WithPagination;

    #[Locked]
    public ?int $chatId = null;

    #[Locked]
    public string $submissionId;

    public ?int $targetDevice = null;

    public string $prompt = '';

    public string $search = '';

    public function mount(): void
    {
        $user = $this->actor();
        $this->chatId = WebWorkspaceChat::where('user_id', $user->id)->latest('updated_at')->value('id');
        $this->targetDevice = Device::where('user_id', $user->id)->whereNull('revoked_at')->orderByRaw('CASE WHEN id = ? THEN 0 ELSE 1 END', [$user->master_device_id ?? 0])->latest('last_seen_at')->value('id');
        $this->submissionId = (string) Str::uuid();
    }

    public function newChat(string $scope): void
    {
        $this->chatId = app(WebWorkspaceService::class)->createChat($this->actor(), $scope)->id;
        $this->prompt = '';
        $this->submissionId = (string) Str::uuid();
        $this->resetErrorBag();
    }

    public function openChat(int $chatId): void
    {
        $this->chatId = WebWorkspaceChat::where('user_id', $this->actor()->id)->findOrFail($chatId)->id;
        $this->prompt = '';
        $this->submissionId = (string) Str::uuid();
        $this->resetErrorBag();
    }

    public function selectDevice(int $deviceId): void
    {
        $this->targetDevice = Device::where('user_id', $this->actor()->id)->whereNull('revoked_at')->findOrFail($deviceId)->id;
    }

    public function updatedSearch(): void
    {
        $this->resetPage('webChats');
        $this->resetPage('deviceChats');
    }

    public function send(): void
    {
        $user = $this->actor();
        $this->validate(['prompt' => ['required', 'string', 'max:12000'], 'targetDevice' => ['required', 'integer']]);
        if (! $this->chatId) {
            $this->chatId = app(WebWorkspaceService::class)->createChat($user, 'workspace')->id;
        }
        try {
            app(WebWorkspaceService::class)->submit($user, $this->chatId, $this->targetDevice, $this->prompt, $this->submissionId);
            $this->prompt = '';
            $this->submissionId = (string) Str::uuid();
        } catch (HttpExceptionInterface $exception) {
            if (! in_array($exception->getStatusCode(), [409, 422, 429, 503], true)) {
                throw $exception;
            }
            $this->addError('prompt', $exception->getStatusCode() === 503 ? 'Die Gerätesignierung ist auf dem Server noch nicht verfügbar.' : $exception->getMessage());
        }
    }

    public function cancelQueued(int $turnId): void
    {
        $user = $this->actor();
        abort_unless($this->chatId !== null, 404);
        try {
            app(WebWorkspaceService::class)->cancelQueued($user, $this->chatId, $turnId);
        } catch (HttpExceptionInterface $exception) {
            if ($exception->getStatusCode() !== 409) {
                throw $exception;
            }
            $this->addError('prompt', $exception->getMessage());
        }
    }

    private function actor(): User
    {
        $user = auth()->user();
        abort_unless($user instanceof User && $user->isActive(), 403);

        return $user;
    }

    public function render()
    {
        $user = $this->actor();
        $devices = Device::where('user_id', $user->id)->withCount(['jobs as active_jobs_count' => fn ($query) => $query->whereIn('status', ['queued', 'approval_required', 'running'])->where('expires_at', '>', now())])->latest('last_seen_at')->get();
        $search = mb_substr($this->search, 0, 120);
        $chats = WebWorkspaceChat::where('user_id', $user->id)->when($search !== '', fn ($query) => $query->where('title', 'like', '%'.$search.'%'))->latest('updated_at')->paginate(15, pageName: 'webChats');
        $deviceChats = Conversation::where('user_id', $user->id)->whereNull('archived_at')->when($search !== '', fn ($query) => $query->where('title', 'like', '%'.$search.'%'))->latest('last_message_at')->paginate(15, pageName: 'deviceChats');
        $activeChat = $this->chatId ? WebWorkspaceChat::where('user_id', $user->id)->findOrFail($this->chatId) : null;
        $turns = $activeChat?->turns()->with('deviceJob.device')->latest('id')->limit(30)->get()->reverse() ?? collect();

        return view('livewire.account.workspace', compact('user', 'devices', 'chats', 'deviceChats', 'activeChat', 'turns'))->layout('layouts.app');
    }
}
