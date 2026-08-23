    <script>
    (function () {
        'use strict';
        const CSRF = @js(csrf_token());
        const LOCKED = @js((bool) $workflowEditing->is_edit_locked);
        const UPDATE_URL = @js(route('dashboard.workflows.update', $workflowEditing));
        const CATALOG = @js(collect($taskCatalog)->keyBy('key'));
        const DEFINITIONS = @js($workflowDefinitions->map(fn ($d) => ['id' => $d->id, 'name' => $d->name, 'status' => $d->status])->values());
        const SELF_ID = @js($workflowEditing->id);
        const INITIAL = @js($workflowEditing->definition ?? ['steps' => []]);
        const INITIAL_NAME = @js($workflowEditing->name);

        const KIND_DOT = {ai: 'bg-violet-400', data: 'bg-slate-400', control: 'bg-amber-400', device: 'bg-sky-400', browser: 'bg-sky-400', memory: 'bg-emerald-400', workflow: 'bg-fuchsia-400', file: 'bg-blue-400', api: 'bg-teal-400', code: 'bg-rose-400', agent: 'bg-indigo-400'};
        const KIND_LABEL = {ai: 'AI', data: 'Daten', control: 'Steuerung', device: 'Gerät', browser: 'Browser', memory: 'Memory', workflow: 'Workflow', file: 'Datei', api: 'API', code: 'Code', agent: 'Agent'};
        const OUTCOMES = [['success', 'Bei Erfolg'], ['failed', 'Bei Fehler'], ['partial', 'Bei Teilstatus'], ['timeout', 'Bei Timeout']];
        const esc = (v) => String(v ?? '').replace(/[&<>"']/g, (c) => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
        const $ = (sel, root) => (root || document).querySelector(sel);
        const $$ = (sel, root) => Array.from((root || document).querySelectorAll(sel));

        // ── State ──────────────────────────────────────────────────────────
        let state = {lists: [], cards: []};   // cards: {key,type,title,params,routes,requires_approval,max_attempts,list,manualDeps}
        let dirty = false, showRoutes = true, editingCard = null, modalMode = null, dragCard = null, dragList = null;

        function slug(text) {
            const base = String(text || 'task').toLowerCase().replace(/[äöüß]/g, (c) => ({ä:'ae',ö:'oe',ü:'ue',ß:'ss'}[c])).replace(/[^a-z0-9_.-]+/g, '-').replace(/^-+|-+$/g, '') || 'task';
            let key = base, n = 2;
            while (state.cards.some((c) => c.key === key)) key = base + '-' + n++;
            return key;
        }
        function listCards(listKey) { return state.cards.filter((c) => c.list === listKey); }

        function loadDefinition(def) {
            const steps = Array.isArray(def.steps) ? def.steps : [];
            let lists = Array.isArray(def.lists) ? def.lists.filter((l) => l && l.key) : [];
            if (!lists.length) lists = [{key: 'liste-1', name: 'Ablauf'}];
            const listKeys = new Set(lists.map((l) => l.key));
            const cards = steps.map((s) => {
                const payload = typeof s.payload === 'object' && s.payload ? {...s.payload} : {};
                const routes = typeof s.routes === 'object' && s.routes ? s.routes : (typeof payload.routes === 'object' && payload.routes ? payload.routes : {});
                delete payload.routes;
                const list = listKeys.has(payload.list) ? payload.list : lists[0].key;
                const title = typeof payload.title === 'string' ? payload.title : '';
                delete payload.list; delete payload.title;
                return {key: s.key, type: s.type, title, params: payload, routes: JSON.parse(JSON.stringify(routes)),
                    requires_approval: !!s.requires_approval, max_attempts: s.max_attempts || 2, list,
                    manualDeps: Array.isArray(s.depends_on) ? s.depends_on : []};
            });
            // Kette erkennen: entspricht depends_on genau der Board-Reihenfolge, wird automatisch verkettet.
            let prev = null, isChain = true;
            const ordered = [];
            lists.forEach((l) => cards.filter((c) => c.list === l.key).forEach((c) => ordered.push(c)));
            ordered.forEach((c) => {
                const expected = prev ? [prev.key] : [];
                if (JSON.stringify(c.manualDeps) !== JSON.stringify(expected)) isChain = false;
                prev = c;
            });
            ordered.forEach((c) => { if (isChain) c.manualDeps = null; });
            if (!isChain) {
                // Karten einzeln prüfen: nur abweichende behalten ihre manuellen Abhängigkeiten.
                prev = null;
                ordered.forEach((c) => {
                    const expected = prev ? [prev.key] : [];
                    if (JSON.stringify(c.manualDeps) === JSON.stringify(expected)) c.manualDeps = null;
                    prev = c;
                });
            }
            state = {lists, cards};
            $('[data-ed-dag-note]').classList.toggle('hidden', !state.cards.some((c) => c.manualDeps));
        }

        function serialize() {
            const steps = [];
            let prev = null;
            state.lists.forEach((l) => listCards(l.key).forEach((c) => {
                const payload = {...c.params, title: c.title || undefined, list: c.list};
                Object.keys(payload).forEach((k) => payload[k] === undefined || payload[k] === '' ? delete payload[k] : null);
                const step = {key: c.key, type: c.type, depends_on: c.manualDeps ?? (prev ? [prev.key] : []),
                    requires_approval: !!c.requires_approval, max_attempts: Number(c.max_attempts) || 2, payload};
                const routes = {};
                Object.entries(c.routes || {}).forEach(([o, r]) => { if (r && r.type) routes[o] = r; });
                if (Object.keys(routes).length) step.routes = routes;
                steps.push(step);
                prev = c;
            }));
            return {lists: state.lists, steps};
        }

        // ── Rendering ──────────────────────────────────────────────────────
        function cardBadges(c) {
            const def = CATALOG[c.type] || {};
            const out = [];
            if (def.runner === 'client') out.push('<span class="rounded-full bg-sky-400/10 px-1.5 text-[9px] font-semibold text-sky-300 ring-1 ring-sky-400/30">Client</span>');
            if (c.requires_approval || def.requires_approval) out.push('<span class="rounded-full bg-amber-400/10 px-1.5 text-[9px] font-semibold text-amber-200 ring-1 ring-amber-400/30">Freigabe</span>');
            if (c.type === 'workflow') out.push('<span class="rounded-full bg-fuchsia-400/10 px-1.5 text-[9px] font-semibold text-fuchsia-300 ring-1 ring-fuchsia-400/30">Eingebetteter Workflow</span>');
            if (c.manualDeps) out.push('<span class="rounded-full bg-amber-400/10 px-1.5 text-[9px] font-semibold text-amber-200 ring-1 ring-amber-400/30" title="Manuelle Abhängigkeiten: ' + esc((c.manualDeps || []).join(', ') || 'keine') + '">DAG</span>');
            const ends = [];
            Object.entries(c.routes || {}).forEach(([o, r]) => {
                if (r.type === 'end') ends.push(o + ' → Ende');
                if (r.type === 'fail') ends.push(o + ' → Fehler');
            });
            if (ends.length) out.push('<span class="rounded-full bg-slate-400/10 px-1.5 text-[9px] text-slate-400 ring-1 ring-slate-500/30">' + esc(ends.join(' · ')) + '</span>');
            return out.join('');
        }

        function cardHtml(c, idx) {
            const def = CATALOG[c.type] || {label: c.type, kind: 'data'};
            const dot = KIND_DOT[def.kind] || 'bg-slate-400';
            const failed = c.routes && c.routes.failed && c.routes.failed.type;
            return '<div class="relative">' +
                '<div data-drop-zone data-list="' + esc(c.list) + '" data-pos="' + idx + '" class="h-3 rounded transition-all"></div>' +
                '<div data-card="' + esc(c.key) + '" draggable="' + (!LOCKED) + '" class="group relative cursor-grab rounded-lg border border-slate-800 bg-slate-900/60 px-3 py-2.5 shadow-sm transition hover:-translate-y-px hover:border-cyan-400/40 hover:shadow-md">' +
                '<div class="flex items-center gap-2"><span class="text-slate-600">⠿</span><span class="h-2 w-2 shrink-0 rounded-full ' + dot + '"></span>' +
                '<b class="min-w-0 flex-1 truncate text-sm text-slate-100">' + esc(c.title || def.label || c.key) + '</b>' +
                (LOCKED ? '' : '<details data-card-menu class="relative"><summary class="cursor-pointer list-none rounded px-1.5 text-slate-400 hover:bg-slate-800">⋮</summary>' +
                    '<div class="absolute right-0 z-30 mt-1 w-40 rounded border border-slate-700 bg-slate-950 p-1 shadow-xl">' +
                    '<button data-action="edit-card" data-key="' + esc(c.key) + '" class="block w-full rounded px-2 py-1.5 text-left text-xs text-cyan-100 hover:bg-cyan-400/10">Bearbeiten</button>' +
                    '<button data-action="remove-card" data-key="' + esc(c.key) + '" class="block w-full rounded px-2 py-1.5 text-left text-xs text-rose-300 hover:bg-rose-400/10">Entfernen</button></div></details>') +
                '</div>' +
                '<div class="mt-1 flex items-center gap-1.5 pl-6 font-mono text-[10px] text-slate-500"><span>' + esc(c.type) + '</span><span>·</span><span>' + esc(c.max_attempts) + '×</span></div>' +
                '<div class="mt-1.5 flex flex-wrap gap-1 pl-6">' + cardBadges(c) + '</div>' +
                '<span class="absolute -left-1 top-1/2 h-2 w-2 -translate-y-1/2 rounded-full border-2 border-slate-950 bg-slate-500"></span>' +
                '<span data-port-success class="absolute -right-1 top-[40%] h-2.5 w-2.5 rounded-full border-2 border-slate-950 bg-emerald-400"></span>' +
                (failed ? '<span data-port-failed class="absolute -right-1 top-[68%] h-2.5 w-2.5 rounded-full border-2 border-slate-950 bg-rose-400"></span>' : '') +
                '</div></div>';
        }

        function render() {
            const board = $('[data-ed-board]');
            board.innerHTML = state.lists.map((l) => {
                const cards = listCards(l.key);
                return '<div data-list-col="' + esc(l.key) + '" class="w-[296px] min-w-[296px] max-w-[296px] shrink-0">' +
                    '<div data-list-header="' + esc(l.key) + '" draggable="' + (!LOCKED) + '" class="flex items-center justify-between gap-2 rounded-xl border border-cyan-400/30 bg-cyan-400/10 px-4 py-2.5">' +
                    '<div class="min-w-0"><b class="block truncate text-sm text-cyan-100">' + esc(l.name) + '</b><span class="text-[10px] uppercase tracking-wide text-slate-500">' + cards.length + ' Tasks</span></div>' +
                    '<div class="flex items-center gap-1">' + (LOCKED ? '' : '<span class="cursor-grab text-slate-500" title="Liste ziehen">⠿</span>' +
                    '<details data-card-menu class="relative"><summary class="cursor-pointer list-none rounded px-1.5 text-slate-400 hover:bg-slate-800">⋮</summary>' +
                    '<div class="absolute right-0 z-30 mt-1 w-44 rounded border border-slate-700 bg-slate-950 p-1 shadow-xl">' +
                    '<button data-action="rename-list" data-key="' + esc(l.key) + '" class="block w-full rounded px-2 py-1.5 text-left text-xs text-cyan-100 hover:bg-cyan-400/10">Umbenennen</button>' +
                    '<button data-action="remove-list" data-key="' + esc(l.key) + '" class="block w-full rounded px-2 py-1.5 text-left text-xs text-rose-300 hover:bg-rose-400/10">Liste entfernen</button></div></details>') + '</div></div>' +
                    '<div data-list-body="' + esc(l.key) + '" class="mt-2 min-h-[120px] rounded-xl border border-dashed border-slate-800 p-2 transition">' +
                    cards.map((c, i) => cardHtml(c, i)).join('') +
                    '<div data-drop-zone data-list="' + esc(l.key) + '" data-pos="' + cards.length + '" class="h-3 rounded transition-all"></div>' +
                    (LOCKED ? '' : '<button data-action="add-card" data-key="' + esc(l.key) + '" class="mt-1 w-full rounded border border-dashed border-slate-700 px-2 py-1.5 text-xs text-slate-400 hover:border-cyan-400/40 hover:text-cyan-200">+ Task am Listenende</button>') +
                    '</div></div>';
            }).join('') +
            (LOCKED ? '' : '<button data-action="add-list" class="min-h-[180px] w-[280px] shrink-0 rounded-xl border border-dashed border-slate-700 text-sm text-slate-400 hover:border-cyan-400/40 hover:text-cyan-200">+ Neue Liste anlegen</button>');
            $('[data-ed-count-lists]').textContent = state.lists.length;
            $('[data-ed-count-cards]').textContent = state.cards.length;
            $('[data-ed-json]').value = JSON.stringify(serialize(), null, 2);
            requestAnimationFrame(drawRoutes);
        }

        // ── Routen-Overlay (vereinfachtes AUF refreshRouteLines) ───────────
        function drawRoutes() {
            const svg = $('[data-ed-svg]'), g = $('[data-ed-svg-lines]'), surface = $('[data-ed-surface]');
            const board = $('[data-ed-board]');
            svg.setAttribute('width', board.scrollWidth);
            svg.setAttribute('height', Math.max(board.scrollHeight, surface.clientHeight));
            if (!showRoutes) { g.innerHTML = ''; return; }
            const sRect = surface.getBoundingClientRect();
            const sx = surface.scrollLeft, sy = surface.scrollTop;
            const pos = (el) => { const r = el.getBoundingClientRect(); return {left: r.left - sRect.left + sx, right: r.right - sRect.left + sx, top: r.top - sRect.top + sy, h: r.height, w: r.width}; };
            let lane = 0, lines = '';
            state.cards.forEach((c) => {
                Object.entries(c.routes || {}).forEach(([outcome, r]) => {
                    if (!r || r.type !== 'step' || !r.step_key) return;
                    const srcEl = $('[data-card="' + CSS.escape(c.key) + '"]');
                    const dstEl = $('[data-card="' + CSS.escape(r.step_key) + '"]');
                    if (!srcEl || !dstEl) return;
                    const s = pos(srcEl), d = pos(dstEl);
                    const isFail = outcome !== 'success' && outcome !== 'partial';
                    const y1 = s.top + s.h * (isFail ? 0.68 : 0.40);
                    const y2 = d.top + d.h * 0.5;
                    const x1 = s.right, x2 = d.left;
                    let path;
                    if (c.key === r.step_key) {
                        path = 'M' + x1 + ',' + y1 + ' h14 v' + (s.h * 0.35) + ' h-' + (s.w + 22) + ' v-' + (s.h * 0.6) + ' h8';
                    } else if ((state.cards.find((x) => x.key === r.step_key) || {}).list === c.list || Math.abs(x2 - x1) < 40) {
                        const lx = x1 + 18 + (lane % 5) * 6;
                        path = 'M' + x1 + ',' + y1 + ' H' + lx + ' V' + y2 + ' H' + x2;
                    } else {
                        const ly = 14 + (lane % 7) * 7;
                        path = 'M' + x1 + ',' + y1 + ' h10 V' + ly + ' H' + (x2 - 12) + ' V' + y2 + ' H' + x2;
                    }
                    lane++;
                    const color = isFail ? '#fb7185' : '#10b981';
                    const marker = isFail ? 'url(#wf-arrow-red)' : 'url(#wf-arrow-green)';
                    lines += '<path d="' + path + '" fill="none" stroke="' + color + '" stroke-width="2" stroke-opacity="0.9" stroke-linejoin="round"' + (isFail ? ' stroke-dasharray="6 5"' : '') + ' marker-end="' + marker + '"/>';
                });
            });
            g.innerHTML = lines;
        }

        // ── Bibliothek ─────────────────────────────────────────────────────
        let activeGroup = null;
        function renderLibrary() {
            const groups = {};
            Object.values(CATALOG).forEach((t) => { (groups[t.kind] = groups[t.kind] || []).push(t); });
            const keys = Object.keys(groups);
            if (!activeGroup || !groups[activeGroup]) activeGroup = keys[0];
            $('[data-ed-library-tabs]').innerHTML = keys.map((k) =>
                '<button data-lib-tab="' + esc(k) + '" class="whitespace-nowrap border-b-2 px-2.5 py-1.5 text-xs font-semibold ' + (k === activeGroup ? 'border-cyan-400 text-cyan-200' : 'border-transparent text-slate-500 hover:text-slate-300') + '">' + esc(KIND_LABEL[k] || k) + ' <span class="rounded-full bg-slate-800 px-1.5 text-[9px]">' + groups[k].length + '</span></button>').join('');
            $('[data-ed-library-cards]').innerHTML = groups[activeGroup].map((t) => {
                const allowed = t.allowed_in_definition;
                return '<div data-lib-card="' + esc(t.key) + '" draggable="' + (allowed && !LOCKED) + '" class="rounded-xl border p-3 shadow-sm transition ' + (allowed ? 'cursor-grab border-slate-800 bg-slate-900/60 hover:border-cyan-400/40 hover:shadow-md' : 'cursor-not-allowed border-slate-800/60 bg-slate-900/30 opacity-50') + '">' +
                    '<div class="flex items-center gap-2"><span class="h-2 w-2 rounded-full ' + (KIND_DOT[t.kind] || 'bg-slate-400') + '"></span><b class="text-sm text-slate-100">' + esc(t.label) + '</b>' +
                    '<span class="ml-auto rounded-full bg-slate-800 px-2 py-0.5 text-[9px] text-slate-400">' + esc(t.runner) + '</span></div>' +
                    '<div class="mt-1 font-mono text-[10px] text-slate-500">' + esc(t.key) + '</div>' +
                    (allowed ? '' : '<div class="mt-1 text-[10px] text-amber-200/70">Geplant — Executor folgt (P15), noch nicht in Definitionen erlaubt.</div>') +
                    '</div>';
            }).join('');
        }
        function toggleLibrary(open) {
            $('[data-ed-library]').classList.toggle('translate-x-full', !open);
            $('[data-ed-library-edge]').classList.toggle('hidden', open);
        }

        // ── Karten-Modal ───────────────────────────────────────────────────
        function routeSelect(name, current, cardKey) {
            const opts = ['<option value="">— Standard (nächste Karte / DAG) —</option>', '<option value="end"' + (current === 'end' ? ' selected' : '') + '>Workflow beenden</option>', '<option value="fail"' + (current === 'fail' ? ' selected' : '') + '>Fehlerroute (Lauf fehlgeschlagen)</option>'];
            state.cards.filter((c) => c.key !== cardKey).forEach((c) => {
                const v = 'step:' + c.key;
                opts.push('<option value="' + esc(v) + '"' + (current === v ? ' selected' : '') + '>Karte: ' + esc(c.title || c.key) + '</option>');
            });
            return '<select data-mf="' + name + '" class="luczor-input">' + opts.join('') + '</select>';
        }

        function paramField(name, spec, value) {
            const label = '<label class="text-xs text-slate-400">' + esc(name) + (spec.default !== undefined ? ' <span class="text-slate-600">(Standard: ' + esc(spec.default) + ')</span>' : '') + '</label>';
            if (name === 'workflow_definition_id') {
                const opts = DEFINITIONS.filter((d) => d.id !== SELF_ID).map((d) => '<option value="' + d.id + '"' + (Number(value) === d.id ? ' selected' : '') + '>' + esc(d.name) + (d.status !== 'active' ? ' (inaktiv)' : '') + '</option>');
                return '<div>' + label + '<select data-mf-param="' + esc(name) + '" class="luczor-input"><option value="">— Workflow wählen —</option>' + opts.join('') + '</select></div>';
            }
            if (spec.type === 'textarea') return '<div class="md:col-span-2">' + label + '<textarea data-mf-param="' + esc(name) + '" rows="3" class="luczor-input font-mono text-xs">' + esc(value ?? '') + '</textarea></div>';
            const type = spec.type === 'number' ? 'number' : 'text';
            const extra = (spec.min !== undefined ? ' min="' + spec.min + '"' : '') + (spec.max !== undefined ? ' max="' + spec.max + '"' : '');
            return '<div>' + label + '<input data-mf-param="' + esc(name) + '" type="' + type + '"' + extra + ' class="luczor-input" value="' + esc(value ?? spec.default ?? '') + '"></div>';
        }

        function openCardModal(card, listKey, position, presetType) {
            modalMode = 'card';
            editingCard = card ? card.key : null;
            const preset = presetType && CATALOG[presetType] && CATALOG[presetType].allowed_in_definition ? presetType : 'llm';
            const c = card || {key: '', type: preset, title: card ? '' : (CATALOG[preset] ? CATALOG[preset].label : ''), params: {}, routes: {}, requires_approval: false, max_attempts: 2, list: listKey, manualDeps: null, _pos: position};
            if (!card) c._new = {list: listKey, pos: position};
            const def = CATALOG[c.type] || {params: {}};
            const typeOpts = Object.values(CATALOG).map((t) =>
                '<option value="' + esc(t.key) + '"' + (t.key === c.type ? ' selected' : '') + (t.allowed_in_definition ? '' : ' disabled') + '>' + esc(t.label) + ' — ' + esc(t.key) + (t.allowed_in_definition ? '' : ' (geplant)') + '</option>').join('');
            $('[data-ed-modal-title]').textContent = card ? 'Task bearbeiten' : 'Task hinzufügen';
            $('[data-ed-modal-body]').innerHTML =
                '<div class="grid gap-3 md:grid-cols-2">' +
                '<div class="md:col-span-2"><label class="text-xs text-slate-400">Funktion (Task-Typ)</label><select data-mf="type" class="luczor-input">' + typeOpts + '</select></div>' +
                '<div><label class="text-xs text-slate-400">Kartentitel</label><input data-mf="title" class="luczor-input" value="' + esc(c.title) + '" maxlength="160" placeholder="' + esc(def.label || '') + '"></div>' +
                '<div><label class="text-xs text-slate-400">Schlüssel (eindeutig)</label><input data-mf="key" class="luczor-input font-mono text-xs" value="' + esc(c.key) + '" pattern="[A-Za-z0-9_.\\-]{1,120}" placeholder="wird aus Titel erzeugt"></div>' +
                '<div data-mf-params class="md:col-span-2 grid gap-3 md:grid-cols-2"></div>' +
                '<div><label class="text-xs text-slate-400">Timeout (Sekunden)</label><input data-mf="timeout" type="number" min="1" max="3600" class="luczor-input" value="' + esc(c.params.timeout_seconds ?? '') + '" placeholder="' + esc(def.timeout_seconds ?? 300) + '"></div>' +
                '<div><label class="text-xs text-slate-400">Versuche (max.)</label><input data-mf="attempts" type="number" min="1" max="10" class="luczor-input" value="' + esc(c.max_attempts) + '"></div>' +
                '<label class="md:col-span-2 inline-flex items-center gap-2 text-sm text-slate-300"><input data-mf="approval" type="checkbox"' + (c.requires_approval ? ' checked' : '') + '> Freigabe vor Ausführung erforderlich</label>' +
                '</div>' +
                '<div class="mt-4 border-t border-slate-800 pt-3"><b class="text-sm text-slate-200">Routen (Verzweigungen nach Ergebnis)</b><div class="mt-2 grid gap-3 md:grid-cols-2">' +
                OUTCOMES.map(([o, lbl]) => {
                    const r = c.routes[o];
                    const current = r ? (r.type === 'step' ? 'step:' + r.step_key : r.type) : '';
                    return '<div><label class="text-xs text-slate-400">' + lbl + '</label>' + routeSelect('route-' + o, current, c.key) +
                        '<input data-mf="route-' + o + '-iter" type="number" min="1" max="50" class="luczor-input mt-1' + (current.startsWith('step:') ? '' : ' hidden') + '" value="' + esc(r && r.max_iterations ? r.max_iterations : 2) + '" title="Maximale Rücksprünge (Schleifen-Limit)" placeholder="max. Rücksprünge">' + '</div>';
                }).join('') + '</div></div>' +
                '<div class="mt-4 border-t border-slate-800 pt-3"><details' + (c.manualDeps ? ' open' : '') + '><summary class="cursor-pointer text-xs font-semibold text-slate-400">Erweitert: Abhängigkeiten</summary>' +
                '<label class="mt-2 inline-flex items-center gap-2 text-xs text-slate-300"><input data-mf="autochain" type="checkbox"' + (c.manualDeps ? '' : ' checked') + '> Automatisch verketten (empfohlen)</label>' +
                '<div data-mf-deps class="mt-2 flex flex-wrap gap-2' + (c.manualDeps ? '' : ' hidden') + '">' +
                state.cards.filter((x) => x.key !== c.key).map((x) =>
                    '<label class="inline-flex items-center gap-1 rounded border border-slate-800 px-2 py-1 text-[11px] text-slate-300"><input type="checkbox" data-mf-dep="' + esc(x.key) + '"' + ((c.manualDeps || []).includes(x.key) ? ' checked' : '') + '> ' + esc(x.title || x.key) + '</label>').join('') +
                '</div></details></div>';
            renderModalParams(c.type, c.params);
            $('[data-ed-modal]').classList.remove('hidden');
            $('[data-ed-modal]').classList.add('flex');
            $('[data-ed-modal]').dataset.pending = JSON.stringify(c._new || null);
        }

        function renderModalParams(type, values) {
            const def = CATALOG[type] || {params: {}};
            const params = def.params || {};
            $('[data-mf-params]').innerHTML = Object.keys(params).length
                ? Object.entries(params).map(([name, spec]) => paramField(name, spec || {}, values ? values[name] : undefined)).join('')
                : '<p class="md:col-span-2 text-xs text-slate-600">Keine Parameter für diesen Task-Typ.</p>';
        }

        function closeModal() {
            $('[data-ed-modal]').classList.add('hidden');
            $('[data-ed-modal]').classList.remove('flex');
            modalMode = null; editingCard = null;
        }

        function saveCardModal() {
            const body = $('[data-ed-modal-body]');
            const type = $('[data-mf="type"]', body).value;
            if (!CATALOG[type] || !CATALOG[type].allowed_in_definition) return alert('Dieser Task-Typ ist noch nicht in Definitionen erlaubt.');
            const title = $('[data-mf="title"]', body).value.trim();
            let key = $('[data-mf="key"]', body).value.trim();
            const pending = JSON.parse($('[data-ed-modal]').dataset.pending || 'null');
            const existing = editingCard ? state.cards.find((c) => c.key === editingCard) : null;
            if (!key) key = existing ? existing.key : slug(title || type);
            if (!/^[A-Za-z0-9_.-]{1,120}$/.test(key)) return alert('Ungültiger Schlüssel (erlaubt: Buchstaben, Zahlen, _ . -).');
            if (state.cards.some((c) => c.key === key && c !== existing)) return alert('Schlüssel ist bereits vergeben.');
            const params = {};
            $$('[data-mf-param]', body).forEach((inp) => { if (inp.value !== '') params[inp.dataset.mfParam] = inp.type === 'number' || inp.tagName === 'SELECT' && inp.dataset.mfParam === 'workflow_definition_id' ? Number(inp.value) : inp.value; });
            const timeout = $('[data-mf="timeout"]', body).value;
            if (timeout !== '') params.timeout_seconds = Number(timeout);
            const routes = {};
            OUTCOMES.forEach(([o]) => {
                const v = $('[data-mf="route-' + o + '"]', body).value;
                if (!v) return;
                if (v === 'end' || v === 'fail') routes[o] = {type: v};
                else if (v.startsWith('step:')) routes[o] = {type: 'step', step_key: v.slice(5), max_iterations: Number($('[data-mf="route-' + o + '-iter"]', body).value) || 2};
            });
            const autochain = $('[data-mf="autochain"]', body).checked;
            const manualDeps = autochain ? null : $$('[data-mf-dep]', body).filter((x) => x.checked).map((x) => x.dataset.mfDep);
            const cardData = {key, type, title, params, routes, requires_approval: $('[data-mf="approval"]', body).checked, max_attempts: Number($('[data-mf="attempts"]', body).value) || 2, manualDeps};
            if (existing) {
                const oldKey = existing.key;
                Object.assign(existing, cardData);
                if (oldKey !== key) state.cards.forEach((c) => Object.values(c.routes || {}).forEach((r) => { if (r.step_key === oldKey) r.step_key = key; }));
            } else if (pending) {
                const card = {...cardData, list: pending.list};
                const before = listCards(pending.list)[pending.pos];
                const idx = before ? state.cards.indexOf(before) : state.cards.length;
                state.cards.splice(idx, 0, card);
            }
            markDirty(); closeModal(); render();
        }

        function openListModal(list) {
            modalMode = 'list';
            editingCard = list ? list.key : null;
            $('[data-ed-modal-title]').textContent = list ? 'Liste umbenennen' : 'Neue Liste';
            $('[data-ed-modal-body]').innerHTML = '<label class="text-xs text-slate-400">Name der Liste</label><input data-mf="list-name" class="luczor-input" value="' + esc(list ? list.name : '') + '" maxlength="120" placeholder="z. B. Vorbereitung">';
            $('[data-ed-modal]').classList.remove('hidden');
            $('[data-ed-modal]').classList.add('flex');
            setTimeout(() => $('[data-mf="list-name"]').focus(), 50);
        }

        function saveListModal() {
            const name = $('[data-mf="list-name"]').value.trim();
            if (!name) return;
            if (editingCard) {
                const list = state.lists.find((l) => l.key === editingCard);
                if (list) list.name = name;
            } else {
                let key = 'liste-' + (state.lists.length + 1), n = state.lists.length + 1;
                while (state.lists.some((l) => l.key === key)) key = 'liste-' + ++n;
                state.lists.push({key, name});
            }
            markDirty(); closeModal(); render();
        }

        // ── Interaktion (Delegation) ───────────────────────────────────────
        function markDirty() {
            dirty = true;
            $('[data-ed-dirty]').classList.remove('hidden');
            const save = $('[data-ed-save]');
            if (save) save.disabled = false;
        }
        function showError(msg) {
            const box = $('[data-ed-error]');
            box.textContent = msg;
            box.classList.remove('hidden');
            setTimeout(() => box.classList.add('hidden'), 8000);
        }

        document.addEventListener('click', (ev) => {
            const btn = ev.target.closest('[data-action]');
            if (!btn || LOCKED && btn.dataset.action !== 'edit-card') return;
            const key = btn.dataset.key;
            switch (btn.dataset.action) {
                case 'add-list': openListModal(null); break;
                case 'rename-list': openListModal(state.lists.find((l) => l.key === key)); break;
                case 'remove-list': {
                    if (listCards(key).length && !confirm('Liste samt ' + listCards(key).length + ' Task(s) entfernen?')) return;
                    state.lists = state.lists.filter((l) => l.key !== key);
                    state.cards = state.cards.filter((c) => c.list !== key);
                    if (!state.lists.length) state.lists.push({key: 'liste-1', name: 'Ablauf'});
                    markDirty(); render(); break;
                }
                case 'add-card': openCardModal(null, key, listCards(key).length); break;
                case 'edit-card': openCardModal(state.cards.find((c) => c.key === key), null, null); break;
                case 'remove-card': {
                    if (!confirm('Task „' + key + '" entfernen?')) return;
                    state.cards = state.cards.filter((c) => c.key !== key);
                    state.cards.forEach((c) => Object.entries(c.routes || {}).forEach(([o, r]) => { if (r.step_key === key) delete c.routes[o]; }));
                    markDirty(); render(); break;
                }
            }
        });

        document.addEventListener('dblclick', (ev) => {
            const card = ev.target.closest('[data-card]');
            if (card) openCardModal(state.cards.find((c) => c.key === card.dataset.card), null, null);
        });

        document.addEventListener('change', (ev) => {
            if (ev.target.matches('[data-mf="type"]')) renderModalParams(ev.target.value, {});
            if (ev.target.matches('[data-mf="autochain"]')) $('[data-mf-deps]').classList.toggle('hidden', ev.target.checked);
            if (ev.target.matches('[data-mf^="route-"]') && ev.target.tagName === 'SELECT') {
                const iter = $('[data-mf="' + ev.target.dataset.mf + '-iter"]');
                if (iter) iter.classList.toggle('hidden', !ev.target.value.startsWith('step:'));
            }
        });

        // Drag & Drop — Karten, Bibliothek und Listen (natives HTML5-DnD wie AUF)
        document.addEventListener('dragstart', (ev) => {
            if (LOCKED) return;
            const lib = ev.target.closest('[data-lib-card]');
            const card = ev.target.closest('[data-card]');
            const header = ev.target.closest('[data-list-header]');
            if (lib) {
                ev.dataTransfer.setData('application/x-workflow-task-catalog', lib.dataset.libCard);
                ev.dataTransfer.setData('text/plain', lib.dataset.libCard);
                ev.dataTransfer.effectAllowed = 'copy';
            } else if (card) {
                dragCard = card.dataset.card;
                ev.dataTransfer.setData('application/x-workflow-task-key', dragCard);
                ev.dataTransfer.setData('text/plain', dragCard);
                ev.dataTransfer.effectAllowed = 'move';
                card.style.opacity = '.4';
            } else if (header) {
                dragList = header.dataset.listHeader;
                ev.dataTransfer.setData('application/x-workflow-list-key', dragList);
                ev.dataTransfer.effectAllowed = 'move';
            }
        });
        document.addEventListener('dragend', (ev) => {
            const card = ev.target.closest && ev.target.closest('[data-card]');
            if (card) card.style.opacity = '';
            dragCard = null; dragList = null;
            $$('[data-drop-zone]').forEach((z) => z.classList.remove('h-8', 'border', 'border-dashed', 'border-cyan-400/50', 'bg-cyan-400/5'));
            $$('[data-list-body]').forEach((b) => b.classList.remove('ring-2', 'ring-inset', 'ring-cyan-400/40', 'bg-cyan-400/5'));
        });
        document.addEventListener('dragover', (ev) => {
            const zone = ev.target.closest('[data-drop-zone]');
            const body = ev.target.closest('[data-list-body]');
            const col = ev.target.closest('[data-list-col]');
            if (zone || body) {
                ev.preventDefault();
                ev.dataTransfer.dropEffect = dragCard ? 'move' : 'copy';
                if (zone) zone.classList.add('h-8', 'border', 'border-dashed', 'border-cyan-400/50', 'bg-cyan-400/5');
                if (body) body.classList.add('ring-2', 'ring-inset', 'ring-cyan-400/40');
            } else if (col && dragList) {
                ev.preventDefault();
            }
        });
        document.addEventListener('dragleave', (ev) => {
            const zone = ev.target.closest && ev.target.closest('[data-drop-zone]');
            if (zone) zone.classList.remove('h-8', 'border', 'border-dashed', 'border-cyan-400/50', 'bg-cyan-400/5');
        });
        document.addEventListener('drop', (ev) => {
            if (LOCKED) return;
            const zone = ev.target.closest('[data-drop-zone]');
            const body = ev.target.closest('[data-list-body]');
            const col = ev.target.closest('[data-list-col]');
            const catalogKey = ev.dataTransfer.getData('application/x-workflow-task-catalog');
            const taskKey = ev.dataTransfer.getData('application/x-workflow-task-key');
            const listKey = ev.dataTransfer.getData('application/x-workflow-list-key');

            if ((zone || body) && (catalogKey || taskKey)) {
                ev.preventDefault();
                const targetList = zone ? zone.dataset.list : body.dataset.listBody;
                let pos = zone ? Number(zone.dataset.pos) : (() => {
                    const items = $$('[data-card]', body);
                    let p = items.length;
                    items.forEach((item, i) => { const r = item.getBoundingClientRect(); if (ev.clientY < r.top + r.height / 2 && p === items.length) p = i; });
                    return p;
                })();
                if (catalogKey) { openCardModal(null, targetList, pos, catalogKey); return; }
                const card = state.cards.find((c) => c.key === taskKey);
                if (!card) return;
                const sameList = card.list === targetList;
                const cardsBefore = listCards(targetList);
                if (sameList && cardsBefore.indexOf(card) < pos) pos--;
                state.cards = state.cards.filter((c) => c !== card);
                card.list = targetList;
                const after = listCards(targetList)[pos];
                const idx = after ? state.cards.indexOf(after) : state.cards.length;
                state.cards.splice(idx, 0, card);
                markDirty(); render();
            } else if (col && listKey) {
                ev.preventDefault();
                const from = state.lists.findIndex((l) => l.key === listKey);
                const to = state.lists.findIndex((l) => l.key === col.dataset.listCol);
                if (from < 0 || to < 0 || from === to) return;
                const [moved] = state.lists.splice(from, 1);
                state.lists.splice(to, 0, moved);
                markDirty(); render();
            }
        });

        // Bibliothek / Toolbar
        $('[data-ed-library-toggle]').addEventListener('click', () => toggleLibrary(true));
        $('[data-ed-library-edge]').addEventListener('click', () => toggleLibrary(true));
        $('[data-ed-library-close]').addEventListener('click', () => toggleLibrary(false));
        $('[data-ed-library-tabs]').addEventListener('click', (ev) => {
            const tab = ev.target.closest('[data-lib-tab]');
            if (tab) { activeGroup = tab.dataset.libTab; renderLibrary(); }
        });
        $('[data-ed-routes-toggle]').addEventListener('click', (ev) => {
            showRoutes = !showRoutes;
            ev.target.textContent = showRoutes ? 'Routen ausblenden' : 'Routen einblenden';
            drawRoutes();
        });
        $('[data-ed-fullscreen]').addEventListener('click', () => {
            const shell = $('[data-ed-shell]');
            const active = shell.classList.toggle('wf-fullscreen');
            document.documentElement.style.overflow = active ? 'hidden' : '';
            $('[data-ed-fullscreen]').textContent = active ? 'Vollbild beenden' : 'Vollbild';
            drawRoutes();
        });
        document.addEventListener('keydown', (ev) => {
            if (ev.key === 'Escape') {
                closeModal(); toggleLibrary(false);
                const shell = $('[data-ed-shell]');
                if (shell.classList.contains('wf-fullscreen')) { shell.classList.remove('wf-fullscreen'); document.documentElement.style.overflow = ''; drawRoutes(); }
            }
        });
        $('[data-ed-surface]').addEventListener('scroll', () => requestAnimationFrame(drawRoutes));
        window.addEventListener('resize', () => requestAnimationFrame(drawRoutes));

        // Modal-Buttons
        $('[data-ed-modal-close]').addEventListener('click', closeModal);
        $('[data-ed-modal-cancel]').addEventListener('click', closeModal);
        $('[data-ed-modal-save]').addEventListener('click', () => modalMode === 'list' ? saveListModal() : saveCardModal());
        $('[data-ed-modal]').addEventListener('keydown', (ev) => { if (ev.key === 'Enter' && modalMode === 'list') { ev.preventDefault(); saveListModal(); } });

        // Name / JSON / Speichern
        $('[data-ed-name]').addEventListener('input', markDirty);
        $('[data-ed-json-apply]').addEventListener('click', () => {
            try {
                const def = JSON.parse($('[data-ed-json]').value);
                if (!def || !Array.isArray(def.steps)) throw new Error('Erwartet {"steps":[...]}');
                loadDefinition(def);
                markDirty(); render();
            } catch (e) { showError('JSON-Fehler: ' + e.message); }
        });
        const saveBtn = $('[data-ed-save]');
        if (saveBtn) saveBtn.addEventListener('click', async () => {
            saveBtn.disabled = true;
            saveBtn.textContent = 'Speichern…';
            try {
                const res = await fetch(UPDATE_URL, {
                    method: 'PUT',
                    headers: {Accept: 'application/json', 'Content-Type': 'application/json', 'X-CSRF-TOKEN': CSRF},
                    credentials: 'same-origin',
                    body: JSON.stringify({name: $('[data-ed-name]').value.trim() || INITIAL_NAME, definition_json: JSON.stringify(serialize())}),
                });
                const payload = await res.json().catch(() => ({}));
                if (!res.ok) throw new Error(payload.message || 'Speichern fehlgeschlagen (' + res.status + ').');
                dirty = false;
                $('[data-ed-dirty]').classList.add('hidden');
                window.location.reload();
            } catch (e) {
                showError(e.message);
                saveBtn.disabled = false;
            } finally {
                saveBtn.textContent = 'Speichern';
            }
        });
        window.addEventListener('beforeunload', (ev) => { if (dirty) { ev.preventDefault(); ev.returnValue = ''; } });

        // ── Init ───────────────────────────────────────────────────────────
        loadDefinition(INITIAL);
        renderLibrary();
        render();
        setTimeout(drawRoutes, 300);
    })();
    </script>
    <style>
        .wf-fullscreen { position: fixed; inset: 0; z-index: 50; margin: 0; border-radius: 0; background: #050b12; overflow: auto; }
        [data-ed-board] [data-card-menu] > summary::-webkit-details-marker { display: none; }
    </style>

