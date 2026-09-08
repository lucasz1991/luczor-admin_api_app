{{-- Planning-Engine --}}
<x-ui.panel title="Planning-Engine (Ziel → Workflow-Entwurf)">
    <p class="mt-1 text-xs text-slate-500">Erzeugt aus einem Ziel einen validierten Workflow-Entwurf (Kontext → LLM → Review, optional mit Geräte-Recherche). Danach im Board verfeinern.</p>
    <form class="mt-4 grid gap-3 md:grid-cols-[1fr_auto_auto]" method="POST" action="{{ route('dashboard.workflows.plan') }}">@csrf
        <label class="ui-field">Ziel
<x-ui.input name="goal" placeholder="Ziel (z. B. „Wettbewerber-Preise recherchieren und zusammenfassen“)" required maxlength="500" />
</label>
        <label class="inline-flex items-center gap-2 self-center text-xs text-slate-400"><input type="checkbox" name="include_research" value="1"> Geräte-Recherche</label>
        <x-ui.button type="submit" variant="primary">Planen &amp; öffnen</x-ui.button>
    </form>
</x-ui.panel>

