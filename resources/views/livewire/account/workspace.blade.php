<div wire:poll.8s.visible>
    <x-ui.page title="Alle Geräte. Ein Gespräch." eyebrow="Dein Arbeitsbereich" description="Sprich mit Luczor oder koordiniere deine Geräte, Projekte und Chats.">
        <x-slot:actions><x-ui.button :href="route('account.devices')" variant="secondary">Geräte verwalten</x-ui.button></x-slot:actions>

        {{-- Device rail: the global remote control for every device. Select a device, or ask it for an overview on hover. --}}
        <div class="ui-device-rail" role="group" aria-label="Gerät auswählen">
            @forelse($devices as $device)
                @php($online = !$device->revoked_at && $device->last_seen_at?->gt(now()->subMinutes(2)))
                @php($initials = collect(preg_split('/\s+/u', trim($device->name)))->filter()->take(2)->map(fn($p) => mb_strtoupper(mb_substr($p, 0, 1)))->implode('') ?: 'G')
                <div wire:key="rail-{{ $device->id }}" @class(['ui-device-pill', 'is-selected' => $targetDevice === $device->id, 'is-revoked' => $device->revoked_at, 'is-busy' => $device->active_jobs_count > 0])>
                    <button type="button" class="ui-device-pill__select" wire:click="selectDevice({{ $device->id }})" @disabled($device->revoked_at) aria-pressed="{{ $targetDevice === $device->id ? 'true' : 'false' }}"
                            title="{{ $device->name }} · {{ $online ? 'Verbunden' : ($device->revoked_at ? 'Zugriff entzogen' : 'Offline') }}">
                        <span class="ui-device-pill__mark" aria-hidden="true">{{ $initials }}<i @class(['ui-device-pill__dot', 'is-online' => $online, 'is-revoked' => $device->revoked_at])></i></span>
                        <span class="ui-device-pill__meta">
                            <span class="ui-device-pill__name">{{ $device->name }}</span>
                            <span class="ui-device-pill__status">{{ $device->revoked_at ? 'Entzogen' : ($online ? 'Verbunden' : 'Offline') }}@if($device->active_jobs_count) · {{ $device->active_jobs_count }} Auftr.@endif</span>
                        </span>
                    </button>
                    @unless($device->revoked_at)
                        <button type="button" class="ui-device-pill__action" wire:click="requestOverview({{ $device->id }})" wire:loading.attr="disabled" title="Übersicht von {{ $device->name }} anfordern" aria-label="Übersicht von {{ $device->name }} anfordern"
                                x-on:click="$dispatch('ui-tab-select', { id: 'workspace-sections', name: 'conversation' })">
                            <svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" aria-hidden="true"><path d="M3 4.5h10M3 8h10M3 11.5h6.5"/></svg>
                        </button>
                    @endunless
                </div>
            @empty
                <a href="{{ route('account.devices') }}" class="ui-device-pill ui-device-pill--empty">
                    <span class="ui-device-pill__mark" aria-hidden="true">+</span>
                    <span class="ui-device-pill__meta"><span class="ui-device-pill__name">Erstes Gerät verbinden</span></span>
                </a>
            @endforelse
        </div>

        <x-ui.tabs id="workspace-sections" :tabs="['conversation' => 'Gespräch', 'chats' => 'Alle Chats', 'devices' => 'Geräte']" active="conversation">
            <x-ui.tab-panel name="conversation">
                @php($currentDevice = $devices->firstWhere('id', $targetDevice))
                <x-ui.panel :title="$activeChat?->title ?? 'Dein Workspace'" :description="'Lokales Modell auf '.($currentDevice?->name ?? 'einem ausgewählten Gerät')">
                    <x-slot:actions>
                        <div class="contents" x-data="{ renaming: false, title: '' }" data-chat-title="{{ $activeChat?->title ?? '' }}">
                            <form x-show="renaming" x-cloak class="ui-inline-edit" x-on:submit.prevent="$wire.renameChat(title); renaming = false" x-on:keydown.escape.prevent="renaming = false">
                                <label for="workspace-chat-title" class="sr-only">Chat-Titel</label>
                                <input id="workspace-chat-title" class="ui-input" x-ref="titleInput" x-model="title" maxlength="80" required />
                                <x-ui.button type="submit" variant="secondary">Speichern</x-ui.button>
                            </form>
                            <div class="ui-panel__actions" x-show="!renaming">
                                    <x-ui.badge tone="info">{{ $activeChat?->scope === 'personal' ? 'Nur persönliche Erinnerungen' : 'Übergeordnete Steuerung' }}</x-ui.badge>
                                    @if($activeChat)<x-ui.button variant="ghost" x-on:click="title = $root.closest('[data-chat-title]').dataset.chatTitle; renaming = true; $nextTick(() => $refs.titleInput.select())">Umbenennen</x-ui.button>@endif
                                    <x-ui.button variant="secondary" wire:click="newChat('workspace')">+ Workspace</x-ui.button>
                                    <x-ui.button variant="secondary" wire:click="newChat('personal')">+ Freier Chat</x-ui.button>
                            </div>
                        </div>
                    </x-slot:actions>
                    @error('title')<p role="alert" class="mb-4 text-sm text-amber-300">{{ $message }}</p>@enderror
                    <div class="flex min-w-0 flex-col" aria-label="Chat" x-data="luczorWorkspaceChat" x-on:workspace-prefill.window="prefill($event.detail.text)">
                        <div class="ui-turns max-h-[55vh] min-h-[260px] flex-1 space-y-6 overflow-y-auto pb-5 sm:min-h-[320px]" aria-live="polite" x-ref="turns">
                            @forelse($turns as $turn)
                                @php($job = $turn->deviceJob)
                                @php($status = $job && $job->expires_at?->isPast() && in_array($job->status, ['queued', 'approval_required', 'running']) ? 'expired' : $job?->status)
                                @php($live = in_array($status, ['queued', 'approval_required', 'running']))
                                @php($progress = $live && is_array($job->progress) ? $job->progress : [])
                                <article wire:key="turn-{{ $turn->id }}" class="ui-turn space-y-4" data-status="{{ $status ?? 'idle' }}">
                                    <div class="ui-turn-user ml-auto max-w-2xl rounded-2xl p-4">
                                        <p class="mb-2 text-xs text-slate-400">Du · {{ $turn->created_at->format('H:i') }}</p>
                                        <p class="whitespace-pre-wrap break-words text-sm leading-6">{{ $turn->prompt }}</p>
                                    </div>
                                    <div class="ui-turn-assistant max-w-3xl py-1" data-status="{{ $status ?? 'idle' }}">
                                        <p class="ui-kicker mb-3">Luczor · {{ $job?->device?->name ?? 'Gerät' }}</p>
                                        @if($status === 'completed' && is_string($job->result['text'] ?? null))
                                            <div class="ui-turn-result">
                                                <p class="whitespace-pre-wrap break-words text-sm leading-7 text-slate-200">{{ $job->result['text'] }}</p>
                                                @if($job->result['interrupted'] ?? false)<p class="mt-2 text-xs text-amber-300">Teilfortschritt. Du kannst mit einer weiteren Nachricht fortsetzen.</p>@endif
                                            </div>
                                        @else
                                            {{-- The live status line polls itself faster than the page while a device is still working. --}}
                                            <div class="ui-turn-status text-sm leading-6 {{ in_array($status, ['failed', 'expired']) ? 'text-amber-300' : 'text-slate-400' }}" @if($liveTurn?->is($turn)) wire:poll.3s.visible="$refresh" @endif>
                                                <p class="flex items-center gap-2.5">
                                                    @if($live)<span class="ui-typing" aria-hidden="true"><i></i><i></i><i></i></span>@endif
                                                    <span>{{ match(($progress['status'] ?? null) ?: $status) { 'approval_required', 'waiting' => 'Wartet auf Freigabe am Zielgerät.', 'queued' => 'Auftrag wartet auf das Zielgerät.', 'running' => 'Das lokale Modell arbeitet …', 'checking' => 'Das Gerät prüft das Ergebnis …', 'cancelled' => 'Auftrag zurückgezogen.', 'failed' => $job->error ?: 'Der Geräteauftrag ist fehlgeschlagen.', 'expired' => 'Der Geräteauftrag ist abgelaufen. Prüfe die Verbindung und sende erneut.', default => 'Auftrag nicht mehr verfügbar.' } }}</span>
                                                </p>
                                                @if(is_string($progress['summary'] ?? null) && trim($progress['summary']) !== '')<p class="ui-turn-progress">{{ mb_substr(trim($progress['summary']), 0, 600) }}</p>@endif
                                            </div>
                                        @endif
                                        @if(in_array($status, ['queued', 'approval_required']))<x-ui.button variant="ghost" class="mt-2" wire:click="cancelQueued({{ $turn->id }})" wire:loading.attr="disabled">Auftrag zurückziehen</x-ui.button>@endif
                                    </div>
                                </article>
                            @empty
                                <div class="mx-auto flex max-w-lg flex-col justify-center py-9 text-center sm:py-14">
                                    <p class="ui-kicker mx-auto">{{ $activeChat?->scope === 'personal' ? 'Freier Chat' : 'Dein Workspace' }}</p>
                                    <h3 class="mt-5 text-2xl font-semibold tracking-tight">{{ $activeChat?->scope === 'personal' ? 'Raum für neue Gedanken.' : 'Was möchtest du koordinieren?' }}</h3>
                                    <p class="mt-3 text-sm leading-6 text-slate-400">{{ $activeChat?->scope === 'personal' ? 'Ein unabhängiges Gespräch mit deinen persönlichen Erinnerungen. Projektinhalte, Dateien und andere Chats werden nicht einbezogen.' : 'Lass dir Projekte und Chats zeigen, lies einen ausgewählten Chat oder bereite einen Auftrag vor. Das gewählte Gerät führt die Arbeit lokal aus.' }}</p>
                                    <div class="ui-chips" aria-label="Schnellstart">
                                        @if($activeChat?->scope === 'personal')
                                            <button type="button" class="ui-chip" x-on:click="prefill('Welche persönlichen Erinnerungen hast du zu mir gespeichert?')">Was weißt du über mich?</button>
                                            <button type="button" class="ui-chip" x-on:click="prefill('Merke dir: ')">Etwas merken</button>
                                            <button type="button" class="ui-chip" x-on:click="prefill('Hilf mir, folgenden Gedanken zu strukturieren: ')">Gedanken ordnen</button>
                                        @else
                                            <button type="button" class="ui-chip" wire:click="requestOverview" wire:loading.attr="disabled" @disabled(!$targetDevice)>Projekte &amp; Chats zeigen</button>
                                            <button type="button" class="ui-chip" x-on:click="prefill('Fasse den zuletzt aktiven Chat auf diesem Gerät kurz zusammen.')">Letzten Chat zusammenfassen</button>
                                            <button type="button" class="ui-chip" x-on:click="prefill('Bereite einen Auftrag für das Projekt „…“ vor: ')">Auftrag vorbereiten</button>
                                        @endif
                                    </div>
                                </div>
                            @endforelse
                            {{-- Optimistic echo: the draft appears immediately while the server creates the turn. --}}
                            <article class="ui-turn ui-turn-ghost space-y-4" wire:loading wire:target="send, requestOverview, readDeviceChat" aria-hidden="true">
                                <div class="ui-turn-user ml-auto max-w-2xl rounded-2xl p-4">
                                    <p class="mb-2 text-xs text-slate-400">Du · gerade eben</p>
                                    <p class="whitespace-pre-wrap break-words text-sm leading-6" x-text="draft() || 'Auftrag wird vorbereitet …'"></p>
                                </div>
                                <div class="ui-turn-assistant max-w-3xl py-1" data-status="queued">
                                    <p class="ui-kicker mb-3">Luczor · {{ $currentDevice?->name ?? 'Gerät' }}</p>
                                    <p class="flex items-center gap-2.5 text-sm leading-6 text-slate-400"><span class="ui-typing" aria-hidden="true"><i></i><i></i><i></i></span><span>Auftrag wird übergeben …</span></p>
                                </div>
                            </article>
                        </div>
                        <form wire:submit="send" class="ui-composer mt-auto space-y-4 rounded-2xl p-4" x-on:submit="stick = true">
                            <div class="flex flex-wrap items-end justify-between gap-3">
                                <div class="w-full sm:max-w-sm"><label for="workspace-device" class="ui-label">Antwortendes Gerät</label><x-ui.select id="workspace-device" wire:model="targetDevice"><option value="">Gerät auswählen</option>@foreach($devices->whereNull('revoked_at') as $device)<option value="{{ $device->id }}">{{ $device->name }}{{ $device->last_seen_at?->gt(now()->subMinutes(2)) ? '' : ' (offline)' }}</option>@endforeach</x-ui.select></div>
                                @if($activeChat?->scope !== 'personal' && $targetDevice)<x-ui.button variant="ghost" wire:click="requestOverview" wire:loading.attr="disabled" title="Projekte und Chats des gewählten Geräts anzeigen lassen">Übersicht anfordern</x-ui.button>@endif
                            </div>
                            <div>
                                <label for="workspace-prompt" class="sr-only">Nachricht</label>
                                <x-ui.textarea id="workspace-prompt" class="min-h-[110px] resize-y" wire:model="prompt" maxlength="12000" placeholder="Schreib Luczor … (Strg + Enter sendet)" required x-ref="prompt" x-on:keydown.ctrl.enter.prevent="submitDraft($el.form)" x-on:keydown.meta.enter.prevent="submitDraft($el.form)" />
                            </div>
                            @error('prompt')<p role="alert" class="ui-turn-result text-sm text-amber-300">{{ $message }}</p>@enderror
                            @error('targetDevice')<p role="alert" class="text-sm text-amber-300">Bitte ein eigenes Gerät auswählen.</p>@enderror
                            <div class="flex flex-wrap items-center justify-between gap-3">
                                <p class="max-w-lg text-xs leading-5 text-slate-400">Antworten erscheinen nach Abschluss. Bestehende Gerätefreigaben gelten. Keine externen Modelle ohne separate Freigabe.</p>
                                <div class="flex items-center gap-3">
                                    <span class="ui-composer__count" x-text="draft().length.toLocaleString('de-DE') + ' / 12.000'"></span>
                                    <x-ui.button type="submit" class="shrink-0" wire:loading.attr="disabled" wire:target="send" :disabled="!$targetDevice"><span wire:loading.remove wire:target="send">Senden</span><span class="inline-flex items-center gap-2" wire:loading wire:target="send"><i class="ui-spinner" aria-hidden="true"></i>Wird gesendet …</span></x-ui.button>
                                </div>
                            </div>
                        </form>
                    </div>
                </x-ui.panel>
            </x-ui.tab-panel>
            <x-ui.tab-panel name="chats">
                <div class="mb-5 flex flex-wrap items-end justify-between gap-4">
                    <div class="w-full sm:max-w-md"><label class="ui-label" for="workspace-search">Chats suchen</label><x-ui.input id="workspace-search" type="search" wire:model.live.debounce.300ms="search" maxlength="120" placeholder="Titel suchen …" /></div>
                    <div class="flex flex-wrap gap-2"><x-ui.button wire:click="newChat('workspace')" x-on:click="$dispatch('ui-tab-select', { id: 'workspace-sections', name: 'conversation' })">+ Workspace</x-ui.button><x-ui.button variant="secondary" wire:click="newChat('personal')" x-on:click="$dispatch('ui-tab-select', { id: 'workspace-sections', name: 'conversation' })">+ Freier Chat</x-ui.button></div>
                </div>
                <div class="grid items-start gap-5 lg:grid-cols-2">
                    <x-ui.panel title="Web-Gespräche" description="Deine Gespräche, die du hier im Browser begonnen hast.">
                        <div class="space-y-2">
                            @forelse($chats as $chat)
                                <x-user-ui.resource-row wire:key="chat-{{ $chat->id }}" wire:click="openChat({{ $chat->id }})" :selected="$chatId === $chat->id" x-on:click="$dispatch('ui-tab-select', { id: 'workspace-sections', name: 'conversation' })">
                                    <span class="block truncate font-medium">{{ $chat->title }}</span><span class="mt-2 block text-xs text-slate-400">{{ $chat->scope === 'personal' ? 'Freier Chat' : 'Workspace' }} · {{ $chat->updated_at->locale('de')->diffForHumans() }}</span>
                                </x-user-ui.resource-row>
                            @empty
                                <x-ui.empty title="Dein erstes Gespräch beginnt hier.">Starte einen Workspace oder einen freien Chat.</x-ui.empty>
                            @endforelse
                        </div>
                        <div class="mt-4">{{ $chats->links(data: ['scrollTo' => false]) }}</div>
                    </x-ui.panel>
                    <x-ui.panel title="Chats deiner Geräte" description="Synchronisierte Titel. Inhalte werden erst durch deinen angeforderten Abruf am ausgewählten Gerät gelesen.">
                        <div class="space-y-4">
                            @forelse($deviceChats as $deviceChat)
                                @php($chatDevice = $devices->firstWhere('device_id', $deviceChat->client_id))
                                <article class="ui-record" wire:key="device-chat-{{ $deviceChat->id }}">
                                    <h3 class="truncate text-sm font-medium">{{ $deviceChat->title ?: 'Projektchat' }}</h3>
                                    <p class="mt-2 text-xs leading-5 text-slate-400">{{ $chatDevice?->name ?? 'Gerät nicht verbunden' }} · {{ $deviceChat->last_message_at?->locale('de')->diffForHumans() ?? 'Noch keine Nachricht' }}</p>
                                    @if($chatDevice && !$chatDevice->revoked_at)
                                        <div class="ui-record__actions">
                                            <x-ui.button variant="secondary" wire:click="readDeviceChat({{ $deviceChat->id }})" wire:loading.attr="disabled" x-on:click="$dispatch('ui-tab-select', { id: 'workspace-sections', name: 'conversation' })">Auf dem Gerät lesen</x-ui.button>
                                            <x-ui.button variant="ghost" wire:click="selectDevice({{ $chatDevice->id }})" x-on:click="$dispatch('ui-tab-select', { id: 'workspace-sections', name: 'conversation' })">Gerät auswählen</x-ui.button>
                                        </div>
                                    @endif
                                </article>
                            @empty
                                <x-ui.empty title="Noch keine synchronisierten Chats.">Projektchats erscheinen nach der Synchronisation deiner Geräte.</x-ui.empty>
                            @endforelse
                        </div>
                        <div class="mt-4">{{ $deviceChats->links(data: ['scrollTo' => false]) }}</div>
                    </x-ui.panel>
                </div>
            </x-ui.tab-panel>
            <x-ui.tab-panel name="devices">
                <p class="mb-5 max-w-3xl text-sm leading-6 text-slate-400">Wähle das Gerät, dessen lokales Modell antworten soll. Für Geräteverwaltung und Master-Zuordnung öffnest du „Geräte verwalten“.</p>
                <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-3" aria-label="Meine Geräte">
                    @forelse($devices as $device)
                        @php($online = !$device->revoked_at && $device->last_seen_at?->gt(now()->subMinutes(2)))
                        <x-user-ui.resource-row wire:key="device-{{ $device->id }}" wire:click="selectDevice({{ $device->id }})" :disabled="(bool)$device->revoked_at" :selected="$targetDevice === $device->id">
                            <span class="flex items-center justify-between gap-3"><span class="truncate font-semibold">{{ $device->name }}</span><x-ui.badge :tone="$device->revoked_at ? 'danger' : ($online ? 'success' : 'neutral')">{{ $device->revoked_at ? 'Zugriff entzogen' : ($online ? 'Verbunden' : 'Offline') }}</x-ui.badge></span>
                            <span class="mt-3 block text-sm text-slate-300">{{ (int)$user->master_device_id === (int)$device->id ? 'Master-Gerät' : 'Eigenständig' }}</span>
                            <span class="mt-2 block text-xs leading-5 text-slate-400">{{ $device->active_jobs_count }} offene Aufträge · {{ $device->last_seen_at?->locale('de')->diffForHumans() ?? 'Noch kein Kontakt' }}</span>
                        </x-user-ui.resource-row>
                    @empty
                        <div class="col-span-full"><x-ui.empty title="Verbinde dein erstes Gerät.">Öffne die Desktop-App unter Einstellungen → Server → Im Browser anmelden. Danach erscheint sie hier.</x-ui.empty></div>
                    @endforelse
                </div>
            </x-ui.tab-panel>
        </x-ui.tabs>
    </x-ui.page>
</div>
