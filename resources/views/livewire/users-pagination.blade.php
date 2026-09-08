<nav class="flex flex-wrap items-center justify-between gap-3" aria-label="Seitenauswahl Benutzerliste">
    <p class="text-xs text-slate-400">{{ $paginator->firstItem() ?? 0 }}–{{ $paginator->lastItem() ?? 0 }} von {{ $paginator->total() }} Benutzern</p>
    @if($paginator->hasPages())
        <div class="flex items-center gap-3">
            <x-ui.button variant="secondary" type="button" wire:click="previousPage('{{ $paginator->getPageName() }}')" wire:loading.attr="disabled" :disabled="$paginator->onFirstPage()" aria-label="Vorherige Seite">←</x-ui.button>
            <span class="text-xs text-slate-400">{{ $paginator->currentPage() }} / {{ $paginator->lastPage() }}</span>
            <x-ui.button variant="secondary" type="button" wire:click="nextPage('{{ $paginator->getPageName() }}')" wire:loading.attr="disabled" :disabled="!$paginator->hasMorePages()" aria-label="Nächste Seite">→</x-ui.button>
        </div>
    @endif
</nav>
