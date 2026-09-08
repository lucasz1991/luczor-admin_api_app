<form method="POST" action="{{ route('dashboard.settings.store') }}">@csrf
@forelse($settings->groupBy('group') as $group => $groupSettings)
    <x-ui.panel title="{{ $group }}">
        <div class="mt-4 grid gap-4 md:grid-cols-2 xl:grid-cols-3">
        @foreach($groupSettings as $s)
            @php $v = $s->value['v'] ?? null; @endphp
            <div class="rounded border border-slate-800 p-3">
                <div class="text-sm text-slate-200">{{ $s->label ?? $s->key }}</div>
                <div class="font-mono text-[10px] text-slate-600">{{ $s->key }}</div>
                @if($s->key === 'voice_stt_engine')
                    <x-ui.select class=" mt-2" name="settings[{{ $s->key }}]">
                        <option value="whisper_local" @selected($v==='whisper_local')>whisper_local (whisper.cpp, Standard)</option>
                        <option value="whisper_rs" @selected($v==='whisper_rs')>whisper_rs (nativer Build, benötigt CMake)</option>
                    </x-ui.select>
                @elseif($s->key === 'hands_free_strategy')
                    <x-ui.select class=" mt-2" name="settings[{{ $s->key }}]">
                        <option value="safeword" @selected($v==='safeword')>Safeword (Aktivierungs-/Beendigungsphrase)</option>
                        <option value="continuous" @selected($v==='continuous')>Dauerzuhören (Auto-Abschluss nach Pause)</option>
                    </x-ui.select>
                @elseif($s->key === 'voice_interrupt_mode')
                    <x-ui.select class=" mt-2" name="settings[{{ $s->key }}]">
                        <option value="on_speech" @selected($v==='on_speech')>Bei Sprache (Barge-in)</option>
                        <option value="on_activation_phrase" @selected($v==='on_activation_phrase')>Nur bei Aktivierungsphrase</option>
                        <option value="off" @selected($v==='off')>Aus</option>
                    </x-ui.select>
                @elseif($s->type === 'bool')
                    <label class="mt-2 inline-flex items-center gap-2 text-sm text-slate-400"><input type="checkbox" name="settings[{{ $s->key }}]" value="1" @checked($v)> aktiv</label>
                @elseif($s->type === 'number')
                    <x-ui.input class="mt-2" type="number" step="any" name="settings[{{ $s->key }}]" value="{{ $v }}" aria-label="settings[{{ $s->key }}]" />
                @else
                    <x-ui.input class="mt-2" type="text" name="settings[{{ $s->key }}]" value="{{ $v }}" aria-label="settings[{{ $s->key }}]" />
                @endif
            </div>
        @endforeach
        </div>
    </x-ui.panel>
@empty
    <x-ui.panel><p class="text-slate-400">Noch keine Server-Einstellungen vorhanden.</p></x-ui.panel>
@endforelse
    <div class="mt-6"><x-ui.button class="" type="submit" variant="primary">Alle Einstellungen speichern</x-ui.button></div>
</form>
