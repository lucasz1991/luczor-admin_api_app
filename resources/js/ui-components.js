/** Adapted from RailTime's accordion tabs: one owner, keyboard focus, scoped persistence. */
export function createTabs({ id, initial, names, forceActive = false }) {
    return {
        tabsId: id,
        openTab: String(initial),
        names: names.map(String),
        invalidHandler: null,
        invalidBatchPending: false,
        invalidResetTimer: null,
        init() {
            try {
                const remembered = sessionStorage.getItem(`luczor.tabs:${location.pathname}:${id}`);
                if (!forceActive && this.names.includes(remembered)) this.openTab = remembered;
            } catch { /* Storage is optional; tabs remain operable. */ }
            this.invalidHandler = event => {
                if (this.invalidBatchPending) return;
                this.invalidBatchPending = true;
                this.openContainingPanel(event.target);
                // Browsers can drain microtasks between native invalid callbacks.
                // Keep the first invalid panel selected for the whole validation task.
                this.invalidResetTimer = setTimeout(() => { this.invalidBatchPending = false; }, 0);
            };
            this.$root.addEventListener('invalid', this.invalidHandler, true);
            this.$nextTick(() => {
                if (!forceActive) this.openHash();
                const error = this.$root.querySelector('[aria-invalid="true"], [data-validation-error]');
                if (error) this.openContainingPanel(error);
            });
        },
        destroy() {
            this.$root.removeEventListener('invalid', this.invalidHandler, true);
            clearTimeout(this.invalidResetTimer);
        },
        selectTab(name, focus = false) {
            if (!this.names.includes(String(name))) return;
            this.openTab = String(name);
            try { sessionStorage.setItem(`luczor.tabs:${location.pathname}:${id}`, this.openTab); } catch { /* Optional. */ }
            if (focus) this.$nextTick(() => {
                const button = document.getElementById(`${id}-tab-${this.openTab}`);
                button?.focus({ preventScroll: true });
                button?.scrollIntoView({ block: 'nearest', inline: 'nearest', behavior: 'auto' });
            });
        },
        moveTab(offset) {
            const index = this.names.indexOf(this.openTab);
            this.selectTab(this.names[(index + offset + this.names.length) % this.names.length], true);
        },
        moveToBoundary(last) { this.selectTab(this.names[last ? this.names.length - 1 : 0], true); },
        openContainingPanel(target) {
            const panels = Array.from(this.$root.querySelectorAll('[data-ui-tab-panel]'));
            const panel = panels.find(item => item.contains(target) && item.closest('[data-ui-tabs]') === this.$root);
            if (panel) {
                this.selectTab(panel.dataset.uiTabPanel);
                // Native form validation needs visibility synchronously, before its focus attempt.
                panel.style.display = '';
                panel.inert = false;
                panel.removeAttribute('x-cloak');
            }
        },
        openHash() {
            let anchor;
            try { anchor = decodeURIComponent(location.hash.slice(1)); } catch { return; }
            if (!anchor) return;
            const target = document.getElementById(anchor);
            if (target && this.$root.contains(target)) this.openContainingPanel(target);
            if (anchor.startsWith(`${id}-`)) this.selectTab(anchor.slice(id.length + 1).replace(/^panel-/, ''));
        },
    };
}

/** Workspace remote control: keeps the newest turn in view and prefills quick prompts into the composer. */
export function createWorkspaceChat() {
    return {
        stick: true,
        observer: null,
        init() {
            const turns = this.$refs.turns;
            if (!turns) return;
            this.scrollToEnd(false);
            turns.addEventListener('scroll', () => {
                this.stick = turns.scrollHeight - turns.scrollTop - turns.clientHeight < 96;
            }, { passive: true });
            // Livewire morphs new turns and status lines in place; follow them only while the reader sits at the end.
            this.observer = new MutationObserver(() => { if (this.stick) this.scrollToEnd(true); });
            this.observer.observe(turns, { childList: true, subtree: true, characterData: true });
        },
        destroy() { this.observer?.disconnect(); },
        scrollToEnd(smooth) {
            const turns = this.$refs.turns;
            if (!turns) return;
            const reduced = window.matchMedia?.('(prefers-reduced-motion: reduce)').matches;
            requestAnimationFrame(() => turns.scrollTo({ top: turns.scrollHeight, behavior: smooth && !reduced ? 'smooth' : 'auto' }));
        },
        draft() {
            return String(this.$wire?.prompt ?? this.$refs.prompt?.value ?? '');
        },
        prefill(text) {
            const prompt = this.$refs.prompt;
            if (!prompt) return;
            prompt.value = text;
            // wire:model listens for input, so the Livewire draft follows without a network round trip.
            prompt.dispatchEvent(new Event('input', { bubbles: true }));
            prompt.focus({ preventScroll: true });
            prompt.setSelectionRange(text.length, text.length);
            prompt.scrollIntoView({ block: 'nearest', behavior: 'smooth' });
        },
        submitDraft(form) {
            if (this.draft().trim() === '' || form.querySelector('[type=submit]')?.disabled) return;
            this.stick = true;
            form.requestSubmit();
        },
    };
}

export function registerUiComponents(Alpine) {
    Alpine.data('luczorTabs', createTabs);
    Alpine.data('luczorWorkspaceChat', createWorkspaceChat);
}
