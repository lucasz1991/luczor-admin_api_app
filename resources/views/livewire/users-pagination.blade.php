<nav class="flex flex-wrap items-center justify-between gap-3" aria-label="Seitenauswahl Benutzerliste">
    <p class="text-xs text-slate-400">{{ $paginator->firstItem() ?? 0 }}–{{ $paginator->lastItem() ?? 0 }} von {{ $paginator->total() }} Benutzern</p>
    @if($paginator->hasPages())
        <div class="flex items-center gap-3">
            <button type="button" wire:click="previousPage('{{ $paginator->getPageName() }}')" wire:loading.attr="disabled" @disabled($paginator->onFirstPage()) class="rounded-lg border border-slate-700 px-3 py-2 text-sm text-slate-300 hover:bg-slate-800 disabled:cursor-default disabled:opacity-40" aria-label="Vorherige Seite">←</button>
            <span class="text-xs text-slate-400">{{ $paginator->currentPage() }} / {{ $paginator->lastPage() }}</span>
            <button type="button" wire:click="nextPage('{{ $paginator->getPageName() }}')" wire:loading.attr="disabled" @disabled(!$paginator->hasMorePages()) class="rounded-lg border border-slate-700 px-3 py-2 text-sm text-slate-300 hover:bg-slate-800 disabled:cursor-default disabled:opacity-40" aria-label="Nächste Seite">→</button>
        </div>
    @endif
</nav>
