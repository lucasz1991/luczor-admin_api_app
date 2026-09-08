<x-app-layout>
    <div data-dashboard-role="{{ $isAdmin ? 'admin' : 'customer' }}">
    @if (session('status'))
        <div class="mb-6 rounded-md border border-emerald-400/30 bg-emerald-400/10 p-3 text-sm text-emerald-100" role="status">{{ session('status') }}</div>
    @endif

    @if (session('plain_api_key'))
        <div class="mb-6 rounded-md border border-cyan-400/30 bg-cyan-400/10 p-4" role="status" data-dashboard-api-key-reveal>
            <div class="text-sm font-semibold text-cyan-100">Neuer API Key, nur jetzt sichtbar:</div>
            <code class="mt-2 block break-all rounded bg-slate-950 p-3 text-sm text-cyan-200">{{ session('plain_api_key') }}</code>
        </div>
    @endif

    @if ($errors->any())
        <div class="mb-6 rounded-md border border-rose-400/30 bg-rose-400/10 p-4 text-sm text-rose-100" role="alert">
            <ul class="list-disc pl-5">
                @foreach ($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif


        @if ($isAdmin)
            @php
                $toolGroups = ['operations' => 'Betrieb', 'access' => 'Zugänge & Provider', 'routing' => 'Modelle & Routing', 'optimizer' => 'Optimierung', 'agents' => 'Agenten'];
                $openAdminToolGroup = array_key_exists(old('_dashboard_tool_group', ''), $toolGroups) ? old('_dashboard_tool_group') : 'operations';
            @endphp
            <x-ui.page title="Luczor Admin Control" eyebrow="Systemübersicht" description="Betrieb, Kosten und Einrichtung an einem Ort. Wähle einen Bereich für die Details.">
                <x-slot:actions>
                    <x-ui.button variant="secondary" :href="route('account.workspace')">Mein Workspace</x-ui.button>
                    <x-ui.button :href="route('admin.users.index')">Benutzer verwalten</x-ui.button>
                </x-slot:actions>
                <x-ui.tabs id="admin-dashboard" :tabs="['overview' => 'Überblick', 'configuration' => 'Konfiguration']" :active="$errors->any() ? 'configuration' : 'overview'" :force-active="$errors->any()">
                    <x-ui.tab-panel name="overview">
                        @include('dashboard.partials.admin-command-center')
                    </x-ui.tab-panel>
                    <x-ui.tab-panel name="configuration">
                        <div data-dashboard-action="advanced-configuration">
                            <x-ui.tabs id="dashboard-configuration" :tabs="$toolGroups" :active="$openAdminToolGroup" :force-active="$errors->any()">
                                @foreach ($toolGroups as $group => $label)
                                    <x-ui.tab-panel :name="$group">
                                        @include('dashboard.partials.configuration-'.$group)
                                    </x-ui.tab-panel>
                                @endforeach
                            </x-ui.tabs>
                        </div>
                    </x-ui.tab-panel>
                </x-ui.tabs>
            </x-ui.page>
        @else
            @include('dashboard.partials.member-overview')
        @endif
    </div>
</x-app-layout>
