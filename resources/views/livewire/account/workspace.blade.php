<div wire:poll.8s.visible>
    <x-ui.page title="Alle Geräte. Ein Gespräch." eyebrow="Dein Arbeitsbereich" description="Sprich mit Luczor oder koordiniere deine Geräte, Projekte und Chats.">
        <x-slot:actions><x-ui.button :href="route('account.devices')" variant="secondary">Geräte verwalten</x-ui.button></x-slot:actions>
        <x-ui.tabs id="workspace-sections" :tabs="['conversation' => 'Gespräch', 'chats' => 'Alle Chats', 'devices' => 'Geräte']" active="conversation">
            <x-ui.tab-panel name="conversation">
                <x-ui.panel :title="$activeChat?->title ?? 'Dein Workspace'" :description="'Lokales Modell auf '.($devices->firstWhere('id', $targetDevice)?->name ?? 'einem ausgewählten Gerät')">
                    <x-slot:actions>
                        <x-ui.badge tone="info">{{ $activeChat?->scope === 'personal' ? 'Nur persönliche Erinnerungen' : 'Übergeordnete Steuerung' }}</x-ui.badge>
                        <x-ui.button variant="secondary" wire:click="newChat('workspace')">+ Workspace</x-ui.button>
                        <x-ui.button variant="secondary" wire:click="newChat('personal')">+ Freier Chat</x-ui.button>
                    </x-slot:actions>
                    <div class="flex min-w-0 flex-col" aria-label="Chat">
                        <div class="max-h-[55vh] min-h-[260px] flex-1 space-y-6 overflow-y-auto pb-5 sm:min-h-[320px]" aria-live="polite">
                            @forelse($turns as $turn)
                                @php($job = $turn->deviceJob)
                                @php($status = $job && $job->expires_at?->isPast() && in_array($job->status, ['queued', 'approval_required', 'running']) ? 'expired' : $job?->status)
                                <article wire:key="turn-{{ $turn->id }}" class="space-y-4">
                                    <div class="ml-auto max-w-2xl rounded-2xl bg-white/[0.045] p-4 ring-1 ring-white/5">
                                        <p class="mb-2 text-xs text-slate-400">Du · {{ $turn->created_at->format('H:i') }}</p>
                                        <p class="whitespace-pre-wrap break-words text-sm leading-6">{{ $turn->prompt }}</p>
                                    </div>
                                    <div class="max-w-3xl py-2">
                                        <p class="ui-kicker mb-3">Luczor · {{ $job?->device?->name ?? 'Gerät' }}</p>
                                        @if($status === 'completed' && is_string($job->result['text'] ?? null))
                                            <p class="whitespace-pre-wrap break-words text-sm leading-7 text-slate-200">{{ $job->result['text'] }}</p>
                                            @if($job->result['interrupted'] ?? false)<p class="mt-2 text-xs text-amber-300">Teilfortschritt. Du kannst mit einer weiteren Nachricht fortsetzen.</p>@endif
                                        @else
                                            <p class="text-sm leading-6 {{ in_array($status, ['failed', 'expired']) ? 'text-amber-300' : 'text-slate-400' }}">{{ match($status) { 'approval_required' => 'Wartet auf Freigabe am Zielgerät.', 'queued' => 'Auftrag wartet auf das Zielgerät.', 'running' => 'Das lokale Modell arbeitet …', 'cancelled' => 'Auftrag zurückgezogen.', 'failed' => $job->error ?: 'Der Geräteauftrag ist fehlgeschlagen.', 'expired' => 'Der Geräteauftrag ist abgelaufen. Prüfe die Verbindung und sende erneut.', default => 'Auftrag nicht mehr verfügbar.' } }}</p>
                                        @endif
                                        @if(in_array($status, ['queued', 'approval_required']))<x-ui.button variant="ghost" class="mt-2" wire:click="cancelQueued({{ $turn->id }})">Auftrag zurückziehen</x-ui.button>@endif
                                    </div>
                                </article>
                            @empty
                                <div class="mx-auto flex max-w-lg flex-col justify-center py-9 text-center sm:py-14">
                                    <p class="ui-kicker">{{ $activeChat?->scope === 'personal' ? 'Freier Chat' : 'Dein Workspace' }}</p>
                                    <h3 class="mt-5 text-2xl font-semibold tracking-tight">{{ $activeChat?->scope === 'personal' ? 'Raum für neue Gedanken.' : 'Was möchtest du koordinieren?' }}</h3>
                                    <p class="mt-3 text-sm leading-6 text-slate-400">{{ $activeChat?->scope === 'personal' ? 'Ein unabhängiges Gespräch mit deinen persönlichen Erinnerungen. Projektinhalte, Dateien und andere Chats werden nicht einbezogen.' : 'Lass dir Projekte und Chats zeigen, lies einen ausgewählten Chat oder bereite einen Auftrag vor. Das gewählte Gerät führt die Arbeit lokal aus.' }}</p>
                                </div>
                            @endforelse
                        </div>
                        <form wire:submit="send" class="mt-auto space-y-4 rounded-2xl bg-white/[0.025] p-4 ring-1 ring-white/5">
                            <div class="sm:max-w-sm"><label for="workspace-device" class="ui-label">Antwortendes Gerät</label><x-ui.select id="workspace-device" wire:model="targetDevice"><option value="">Gerät auswählen</option>@foreach($devices->whereNull('revoked_at') as $device)<option value="{{ $device->id }}">{{ $device->name }}{{ $device->last_seen_at?->gt(now()->subMinutes(2)) ? '' : ' (offline)' }}</option>@endforeach</x-ui.select></div>
                            <div><label for="workspace-prompt" class="sr-only">Nachricht</label><x-ui.textarea id="workspace-prompt" class="min-h-[110px] resize-y" wire:model="prompt" maxlength="12000" placeholder="Schreib Luczor …" required /></div>
                            @error('prompt')<p role="alert" class="text-sm text-amber-300">{{ $message }}</p>@enderror
                            @error('targetDevice')<p role="alert" class="text-sm text-amber-300">Bitte ein eigenes Gerät auswählen.</p>@enderror
                            <div class="flex flex-wrap items-center justify-between gap-3"><p class="max-w-lg text-xs leading-5 text-slate-400">Antworten erscheinen nach Abschluss. Bestehende Gerätefreigaben gelten. Keine externen Modelle ohne separate Freigabe.</p><x-ui.button type="submit" class="shrink-0" wire:loading.attr="disabled" wire:target="send" :disabled="!$targetDevice"><span wire:loading.remove wire:target="send">Senden</span><span wire:loading wire:target="send">Wird gesendet …</span></x-ui.button></div>
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
                                <article class="rounded-2xl bg-white/[0.025] p-4" wire:key="device-chat-{{ $deviceChat->id }}"><h3 class="truncate text-sm font-medium">{{ $deviceChat->title ?: 'Projektchat' }}</h3><p class="mt-2 text-xs leading-5 text-slate-400">{{ $chatDevice?->name ?? 'Gerät nicht verbunden' }} · {{ $deviceChat->last_message_at?->locale('de')->diffForHumans() ?? 'Noch keine Nachricht' }}</p>@if($chatDevice && !$chatDevice->revoked_at)<x-ui.button variant="ghost" class="mt-3" wire:click="selectDevice({{ $chatDevice->id }})" x-on:click="$dispatch('ui-tab-select', { id: 'workspace-sections', name: 'conversation' })">Dieses Gerät auswählen</x-ui.button>@endif</article>
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
