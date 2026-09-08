<div class="mx-auto max-w-screen-2xl space-y-6 p-4 md:p-6" wire:poll.8s.visible>
    <header class="flex flex-wrap items-end justify-between gap-4">
        <div><p class="text-xs uppercase tracking-widest text-sky-400">Dein Arbeitsbereich</p><h1 class="mt-2 text-3xl font-semibold tracking-tight">Alle Geräte. Ein Gespräch.</h1><p class="mt-2 max-w-2xl text-sm leading-6 text-slate-400">Wähle, welches deiner Geräte antwortet. Im Workspace steuerst du dessen Projekte und Chats; im freien Chat bleiben nur deine persönlichen Erinnerungen und dieses Gespräch.</p></div>
        <a class="luczor-btn" href="{{ route('account.devices') }}">Geräte verwalten</a>
    </header>
    <section class="grid gap-3 sm:grid-cols-2 xl:grid-cols-4" aria-label="Meine Geräte">
        @forelse($devices as $device)
            @php($online = !$device->revoked_at && $device->last_seen_at?->gt(now()->subMinutes(2)))
            <button type="button" wire:key="device-{{ $device->id }}" wire:click="selectDevice({{ $device->id }})" @disabled($device->revoked_at) class="luczor-card p-4 text-left transition hover:border-sky-500 {{ $targetDevice === $device->id ? 'ring-1 ring-sky-400' : '' }}" aria-pressed="{{ $targetDevice === $device->id ? 'true' : 'false' }}">
                <div class="flex items-center justify-between gap-2"><span class="truncate font-semibold">{{ $device->name }}</span><span class="h-2 w-2 shrink-0 rounded-full {{ $online ? 'bg-emerald-400' : 'bg-slate-600' }}" aria-hidden="true"></span></div>
                <p class="mt-2 text-xs text-slate-400">{{ $device->revoked_at ? 'Zugriff entzogen' : ($online ? 'Verbunden' : 'Offline') }} · {{ (int)$user->master_device_id === (int)$device->id ? 'Master-Gerät' : 'Eigenständig' }}</p>
                <p class="mt-3 text-xs text-slate-500">{{ $device->active_jobs_count }} offene Aufträge · {{ $device->last_seen_at?->locale('de')->diffForHumans() ?? 'Noch kein Kontakt' }}</p>
            </button>
        @empty
            <div class="luczor-card col-span-full p-5 text-sm text-slate-400">Verbinde die Desktop-App unter Einstellungen → Server → Im Browser anmelden. Danach erscheint sie hier.</div>
        @endforelse
    </section>
    <div class="grid gap-5 xl:grid-cols-[290px_minmax(0,1fr)]">
        <aside class="luczor-card space-y-5 p-4">
            <div class="grid grid-cols-2 gap-2"><button type="button" class="luczor-btn" wire:click="newChat('workspace')">+ Workspace</button><button type="button" class="luczor-btn" wire:click="newChat('personal')">+ Freier Chat</button></div>
            <label class="block text-xs text-slate-400">Chats suchen<input type="search" class="luczor-input mt-2 w-full" wire:model.live.debounce.300ms="search" maxlength="120" placeholder="Titel suchen …"></label>
            <section><h2 class="mb-3 text-xs uppercase tracking-wider text-slate-500">Web-Gespräche</h2><div class="space-y-1">
                @forelse($chats as $chat)<button type="button" wire:key="chat-{{ $chat->id }}" wire:click="openChat({{ $chat->id }})" class="w-full rounded-lg p-3 text-left {{ $chatId === $chat->id ? 'bg-sky-500/10 text-sky-200' : 'hover:bg-white/5' }}"><span class="block truncate text-sm">{{ $chat->title }}</span><span class="mt-1 block text-xs text-slate-500">{{ $chat->scope === 'personal' ? 'Freier Chat' : 'Workspace' }} · {{ $chat->updated_at->locale('de')->diffForHumans() }}</span></button>@empty<p class="text-sm text-slate-500">Dein erstes Gespräch beginnt hier.</p>@endforelse
            </div><div class="mt-3">{{ $chats->links(data: ['scrollTo' => false]) }}</div></section>
            <section class="border-t border-white/10 pt-5"><h2 class="text-xs uppercase tracking-wider text-slate-500">Chats deiner Geräte</h2><p class="mb-3 mt-2 text-xs leading-5 text-slate-500">Synchronisierte Titel. Inhalte werden erst durch einen ausdrücklich angeforderten Abruf am ausgewählten Gerät gelesen.</p>
                @forelse($deviceChats as $deviceChat)
                    @php($chatDevice = $devices->firstWhere('device_id', $deviceChat->client_id))
                    <div class="border-b border-white/5 py-3"><p class="truncate text-sm">{{ $deviceChat->title ?: 'Projektchat' }}</p><p class="mt-1 text-xs text-slate-500">{{ $chatDevice?->name ?? 'Gerät nicht verbunden' }} · {{ $deviceChat->last_message_at?->locale('de')->diffForHumans() ?? 'Noch keine Nachricht' }}</p>@if($chatDevice && !$chatDevice->revoked_at)<button type="button" class="mt-2 text-xs text-sky-400 hover:underline" wire:click="selectDevice({{ $chatDevice->id }})">Dieses Gerät auswählen</button>@endif</div>
                @empty<p class="text-sm text-slate-500">Noch keine synchronisierten Chats.</p>@endforelse
                <div class="mt-3">{{ $deviceChats->links(data: ['scrollTo' => false]) }}</div>
            </section>
        </aside>
        <section class="luczor-card flex min-h-[620px] min-w-0 flex-col overflow-hidden" aria-label="Chat">
            <header class="border-b border-white/10 p-5"><div class="flex flex-wrap items-center justify-between gap-3"><h2 class="truncate font-semibold">{{ $activeChat?->title ?? 'Dein Workspace' }}</h2><span class="rounded-full bg-sky-500/10 px-3 py-1 text-xs text-sky-300">{{ $activeChat?->scope === 'personal' ? 'Nur persönliche Erinnerungen' : 'Übergeordnete Steuerung' }}</span></div><p class="mt-2 text-xs text-slate-500">Lokales Modell auf {{ $devices->firstWhere('id', $targetDevice)?->name ?? 'einem ausgewählten Gerät' }} · bestehende Gerätefreigaben gelten</p></header>
            <div class="max-h-[65vh] flex-1 space-y-6 overflow-y-auto p-5" aria-live="polite">
                @forelse($turns as $turn)
                    @php($job = $turn->deviceJob)
                    @php($status = $job && $job->expires_at?->isPast() && in_array($job->status, ['queued', 'approval_required', 'running']) ? 'expired' : $job?->status)
                    <article wire:key="turn-{{ $turn->id }}" class="space-y-3"><div class="ml-auto max-w-2xl rounded-2xl border border-white/10 bg-white/5 p-4"><p class="mb-2 text-xs text-slate-500">Du · {{ $turn->created_at->format('H:i') }}</p><p class="whitespace-pre-wrap break-words text-sm leading-6">{{ $turn->prompt }}</p></div>
                    <div class="max-w-3xl py-2"><p class="mb-2 text-xs text-sky-400">Luczor · {{ $job?->device?->name ?? 'Gerät' }}</p>
                        @if($status === 'completed' && is_string($job->result['text'] ?? null))<p class="whitespace-pre-wrap break-words text-sm leading-7 text-slate-200">{{ $job->result['text'] }}</p>@if($job->result['interrupted'] ?? false)<p class="mt-2 text-xs text-amber-400">Teilfortschritt. Du kannst mit einer weiteren Nachricht fortsetzen.</p>@endif
                        @else<p class="text-sm {{ in_array($status, ['failed','expired']) ? 'text-amber-400' : 'text-slate-400' }}">{{ match($status) { 'approval_required' => 'Wartet auf Freigabe am Zielgerät.', 'queued' => 'Auftrag wartet auf das Zielgerät.', 'running' => 'Das lokale Modell arbeitet …', 'cancelled' => 'Auftrag zurückgezogen.', 'failed' => $job->error ?: 'Der Geräteauftrag ist fehlgeschlagen.', 'expired' => 'Der Geräteauftrag ist abgelaufen. Prüfe die Verbindung und sende erneut.', default => 'Auftrag nicht mehr verfügbar.' } }}</p>@endif
                        @if(in_array($status, ['queued', 'approval_required']))<button type="button" class="mt-2 text-xs text-slate-500 hover:text-slate-200" wire:click="cancelQueued({{ $turn->id }})">Auftrag zurückziehen</button>@endif
                    </div></article>
                @empty
                    <div class="mx-auto flex h-full max-w-lg flex-col justify-center py-20 text-center"><span class="text-4xl text-sky-400" aria-hidden="true">✦</span><h3 class="mt-5 text-2xl font-semibold">{{ $activeChat?->scope === 'personal' ? 'Raum für neue Gedanken.' : 'Was möchtest du koordinieren?' }}</h3><p class="mt-3 text-sm leading-6 text-slate-400">{{ $activeChat?->scope === 'personal' ? 'Ein unabhängiges Gespräch. Projektinhalte, Dateien und andere Chats werden nicht einbezogen.' : 'Lass dir Projekte und Chats zeigen, lies einen ausgewählten Chat oder bereite einen Auftrag vor. Das gewählte Gerät führt die Arbeit lokal aus.' }}</p></div>
                @endforelse
            </div>
            <form wire:submit="send" class="mt-auto border-t border-white/10 p-4">
                <label class="mb-3 block text-xs text-slate-400">Antwortendes Gerät<select class="luczor-input ml-2 max-w-full" wire:model="targetDevice"><option value="">Gerät auswählen</option>@foreach($devices->whereNull('revoked_at') as $device)<option value="{{ $device->id }}">{{ $device->name }}{{ $device->last_seen_at?->gt(now()->subMinutes(2)) ? '' : ' (offline)' }}</option>@endforeach</select></label>
                <label for="workspace-prompt" class="sr-only">Nachricht</label><textarea id="workspace-prompt" class="luczor-input min-h-[110px] w-full resize-y" wire:model="prompt" maxlength="12000" placeholder="Schreib Luczor …" required></textarea>
                @error('prompt')<p role="alert" class="mt-2 text-sm text-amber-400">{{ $message }}</p>@enderror @error('targetDevice')<p role="alert" class="mt-2 text-sm text-amber-400">Bitte ein eigenes Gerät auswählen.</p>@enderror
                <div class="mt-3 flex items-center justify-between gap-3"><p class="text-xs leading-5 text-slate-500">Antworten erscheinen nach Abschluss. Keine externen Modelle ohne separate Freigabe.</p><button type="submit" class="luczor-btn shrink-0" wire:loading.attr="disabled" wire:target="send" @disabled(!$targetDevice)><span wire:loading.remove wire:target="send">Senden ↗</span><span wire:loading wire:target="send">Wird gesendet …</span></button></div>
            </form>
        </section>
    </div>
</div>
