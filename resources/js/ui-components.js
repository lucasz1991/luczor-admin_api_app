/** Adapted from RailTime's accordion tabs: one owner, keyboard focus, scoped persistence. */
export function createTabs({ id, initial, names, forceActive = false }) {
    return {
        tabsId: id,
        openTab: String(initial),
        names: names.map(String),
        invalidHandler: null,
        init() {
            try {
                const remembered = sessionStorage.getItem(`luczor.tabs:${location.pathname}:${id}`);
                if (!forceActive && this.names.includes(remembered)) this.openTab = remembered;
            } catch { /* Storage is optional; tabs remain operable. */ }
            this.invalidHandler = event => this.openContainingPanel(event.target);
            this.$root.addEventListener('invalid', this.invalidHandler, true);
            this.$nextTick(() => {
                this.openHash();
                const error = this.$root.querySelector('[aria-invalid="true"], [data-validation-error]');
                if (error) this.openContainingPanel(error);
            });
        },
        destroy() {
            this.$root.removeEventListener('invalid', this.invalidHandler, true);
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

export function registerUiComponents(Alpine) {
    Alpine.data('luczorTabs', createTabs);
}
