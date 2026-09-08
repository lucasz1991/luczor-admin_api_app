@php
    $settingsGroups = $settings->groupBy('group');
    $settingTabs = $settingsGroups->keys()->mapWithKeys(fn ($group, $index) => ['group-'.$index => ucfirst(str_replace('_', ' ', $group))])->all();
@endphp
@if($settingsGroups->isNotEmpty())
<form method="POST" action="{{ route('dashboard.settings.store') }}" class="space-y-6">@csrf
<x-ui.tabs id="admin-settings" :tabs="$settingTabs">
@foreach($settingsGroups as $group => $groupSettings)
<x-ui.tab-panel name="group-{{ $loop->index }}">
    <x-ui.panel title="{{ $group }}">
        <div class="grid gap-5 md:grid-cols-2">
        @foreach($groupSettings as $s)
            @php $v = $s->value['v'] ?? null; @endphp
            <div class="ui-record">
                <label for="setting-{{ $s->id }}" class="block text-sm font-medium text-slate-200">{{ $s->label ?? $s->key }}</label>
                <div class="font-mono text-[10px] text-slate-600">{{ $s->key }}</div>
                @if($s->key === 'voice_stt_engine')
                    <x-ui.select class="mt-2" id="setting-{{ $s->id }}" name="settings[{{ $s->key }}]">
                        <option value="whisper_local" @selected($v==='whisper_local')>whisper_local (whisper.cpp, Standard)</option>
                        <option value="whisper_rs" @selected($v==='whisper_rs')>whisper_rs (nativer Build, benötigt CMake)</option>
                    </x-ui.select>
                @elseif($s->key === 'hands_free_strategy')
                    <x-ui.select class="mt-2" id="setting-{{ $s->id }}" name="settings[{{ $s->key }}]">
                        <option value="safeword" @selected($v==='safeword')>Safeword (Aktivierungs-/Beendigungsphrase)</option>
                        <option value="continuous" @selected($v==='continuous')>Dauerzuhören (Auto-Abschluss nach Pause)</option>
                    </x-ui.select>
                @elseif($s->key === 'voice_interrupt_mode')
                    <x-ui.select class="mt-2" id="setting-{{ $s->id }}" name="settings[{{ $s->key }}]">
                        <option value="on_speech" @selected($v==='on_speech')>Bei Sprache (Barge-in)</option>
                        <option value="on_activation_phrase" @selected($v==='on_activation_phrase')>Nur bei Aktivierungsphrase</option>
                        <option value="off" @selected($v==='off')>Aus</option>
                    </x-ui.select>
                @elseif($s->type === 'bool')
                    <label class="mt-2 inline-flex items-center gap-2 text-sm text-slate-400"><input type="checkbox" id="setting-{{ $s->id }}" name="settings[{{ $s->key }}]" value="1" @checked($v)> aktiv</label>
                @elseif($s->type === 'number')
                    <x-ui.input class="mt-2" type="number" step="any" id="setting-{{ $s->id }}" name="settings[{{ $s->key }}]" value="{{ $v }}"  />
                @else
                    <x-ui.input class="mt-2" type="text" id="setting-{{ $s->id }}" name="settings[{{ $s->key }}]" value="{{ $v }}"  />
                @endif
            </div>
        @endforeach
        </div>
    </x-ui.panel>
</x-ui.tab-panel>
@endforeach
</x-ui.tabs>
    <div class="mt-6">
<x-ui.button type="submit" variant="primary">Alle Einstellungen speichern</x-ui.button>
</div>
</form>
@else
    <x-ui.empty title="Noch keine Server-Einstellungen vorhanden">Sobald Einstellungen eingerichtet sind, erscheinen sie hier nach Themen gruppiert.</x-ui.empty>
@endif
