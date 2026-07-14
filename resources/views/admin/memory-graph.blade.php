{{-- SOLL §15 P20 — interaktiver Beziehungsgraph: Projekte ⟷ Erinnerungen ⟷ Typen
     als Inline-SVG (keine CDN); Klick auf Knoten hebt Kanten hervor + Detail-Panel. --}}
<section class="mt-6 luczor-card p-5">
    <div class="flex flex-wrap items-baseline justify-between gap-2">
        <h2 class="font-semibold">Beziehungsgraph <span class="text-xs text-slate-500">(Projekte · Erinnerungen · Typen)</span></h2>
        <div class="flex flex-wrap gap-3 text-[10px] text-slate-500">
            <span><span class="inline-block h-2 w-2 rounded-full" style="background:#22d3ee"></span> project</span>
            <span><span class="inline-block h-2 w-2 rounded-full" style="background:#a78bfa"></span> private</span>
            <span><span class="inline-block h-2 w-2 rounded-full" style="background:#fbbf24"></span> skill</span>
            <span><span class="inline-block h-2 w-2 rounded-full" style="background:#38bdf8"></span> agent</span>
            <span><span class="inline-block h-2 w-2 rounded-full" style="background:#34d399"></span> global</span>
        </div>
    </div>
    @if(empty($memoryGraph['memories']))
        <p class="mt-3 text-xs text-slate-500">Noch keine Erinnerungen — der Graph erscheint, sobald memory_links gefüllt ist.</p>
    @else
        <div class="mt-4 grid gap-4 xl:grid-cols-[1.6fr_1fr]">
            <div class="overflow-x-auto rounded border border-slate-800 bg-slate-950/40">
                <svg data-mg-svg class="w-full" style="min-width:640px"></svg>
            </div>
            <div data-mg-panel class="rounded border border-slate-800 bg-slate-900/30 p-4 text-sm">
                <p class="text-xs text-slate-500">Erinnerung, Projekt oder Typ anklicken, um Verbindungen hervorzuheben.
                    Angezeigt: {{ count($memoryGraph['memories']) }} von {{ $memoryGraph['total'] }} Erinnerungen (nach Wichtigkeit).</p>
            </div>
        </div>
        <script>
        (function () {
            'use strict';
            const DATA = @js($memoryGraph['memories']);
            const SCOPE_COLOR = {project: '#22d3ee', private: '#a78bfa', skill: '#fbbf24', agent: '#38bdf8', global: '#34d399'};
            const esc = (v) => String(v ?? '').replace(/[&<>"']/g, (c) => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
            const svg = document.querySelector('[data-mg-svg]');
            const panel = document.querySelector('[data-mg-panel]');

            const projects = [...new Set(DATA.map((m) => m.project))];
            const types = [...new Set(DATA.map((m) => m.type))];
            const H = Math.max(260, Math.max(DATA.length * 16, projects.length * 34, types.length * 34) + 40);
            const W = 900, PX = 170, MX = W / 2, TX = W - 170;
            svg.setAttribute('viewBox', '0 0 ' + W + ' ' + H);

            const y = (index, total) => 30 + (H - 60) * (total <= 1 ? 0.5 : index / (total - 1));
            const pPos = Object.fromEntries(projects.map((p, i) => [p, y(i, projects.length)]));
            const tPos = Object.fromEntries(types.map((t, i) => [t, y(i, types.length)]));
            const mPos = DATA.map((m, i) => y(i, DATA.length));

            let edges = '', dots = '', hubs = '';
            DATA.forEach((m, i) => {
                const my = mPos[i];
                edges += '<line data-mg-edge data-mem="' + m.id + '" data-hub="p:' + esc(m.project) + '" x1="' + (PX + 8) + '" y1="' + pPos[m.project] + '" x2="' + (MX - 8) + '" y2="' + my + '" stroke="#22d3ee" stroke-opacity="0.14"/>';
                edges += '<line data-mg-edge data-mem="' + m.id + '" data-hub="t:' + esc(m.type) + '" x1="' + (MX + 8) + '" y1="' + my + '" x2="' + (TX - 8) + '" y2="' + tPos[m.type] + '" stroke="#34d399" stroke-opacity="0.14"/>';
                const r = 3 + m.importance * 5;
                dots += '<circle data-mg-mem="' + m.id + '" cx="' + MX + '" cy="' + my + '" r="' + r + '" fill="' + (SCOPE_COLOR[m.scope] || '#94a3b8') + '" fill-opacity="0.85" stroke="#020617" stroke-width="1" style="cursor:pointer"><title>' + esc(m.summary) + '</title></circle>';
            });
            projects.forEach((p) => {
                hubs += '<g data-mg-hub="p:' + esc(p) + '" style="cursor:pointer"><circle cx="' + PX + '" cy="' + pPos[p] + '" r="6" fill="#22d3ee" fill-opacity="0.9"/>' +
                    '<text x="' + (PX - 12) + '" y="' + (pPos[p] + 3) + '" text-anchor="end" font-size="10" fill="#94c5ec">' + esc(p.length > 22 ? p.slice(0, 21) + '…' : p) + '</text></g>';
            });
            types.forEach((t) => {
                hubs += '<g data-mg-hub="t:' + esc(t) + '" style="cursor:pointer"><circle cx="' + TX + '" cy="' + tPos[t] + '" r="6" fill="#34d399" fill-opacity="0.9"/>' +
                    '<text x="' + (TX + 12) + '" y="' + (tPos[t] + 3) + '" font-size="10" fill="#86efac">' + esc(t) + '</text></g>';
            });
            svg.innerHTML = edges + dots + hubs +
                '<text x="' + PX + '" y="14" text-anchor="middle" font-size="9" fill="#64748b">PROJEKTE</text>' +
                '<text x="' + MX + '" y="14" text-anchor="middle" font-size="9" fill="#64748b">ERINNERUNGEN</text>' +
                '<text x="' + TX + '" y="14" text-anchor="middle" font-size="9" fill="#64748b">TYPEN</text>';

            function highlight(filter) {
                svg.querySelectorAll('[data-mg-edge]').forEach((edge) => {
                    const hit = filter && (filter.mem ? edge.dataset.mem === String(filter.mem) : edge.dataset.hub === filter.hub);
                    edge.setAttribute('stroke-opacity', filter ? (hit ? '0.85' : '0.05') : '0.14');
                    edge.setAttribute('stroke-width', hit ? '1.6' : '1');
                });
            }

            svg.addEventListener('click', (ev) => {
                const mem = ev.target.closest('[data-mg-mem]');
                const hub = ev.target.closest('[data-mg-hub]');
                if (mem) {
                    const m = DATA.find((x) => String(x.id) === mem.dataset.mgMem);
                    highlight({mem: m.id});
                    panel.innerHTML = '<div class="flex items-center gap-2"><span class="h-2.5 w-2.5 rounded-full" style="background:' + (SCOPE_COLOR[m.scope] || '#94a3b8') + '"></span><b class="text-cyan-100">Erinnerung #' + m.id + '</b>' +
                        '<span class="rounded-full bg-slate-800 px-2 py-0.5 text-[10px] text-slate-400">' + esc(m.scope) + ' · ' + esc(m.type) + '</span></div>' +
                        '<p class="mt-2 text-slate-300">' + esc(m.summary) + '</p>' +
                        '<dl class="mt-3 grid grid-cols-[90px_1fr] gap-y-1 text-xs text-slate-400">' +
                        '<dt class="text-slate-600">Projekt</dt><dd>' + esc(m.project) + '</dd>' +
                        (m.feature_key ? '<dt class="text-slate-600">Feature</dt><dd class="font-mono">' + esc(m.feature_key) + '</dd>' : '') +
                        '<dt class="text-slate-600">Wichtigkeit</dt><dd>' + m.importance.toFixed(2) + '</dd>' +
                        '<dt class="text-slate-600">Dataset</dt><dd class="break-all font-mono text-[10px]">' + esc(m.dataset) + '</dd>' +
                        (m.updated_at ? '<dt class="text-slate-600">Aktualisiert</dt><dd>' + esc(m.updated_at) + '</dd>' : '') + '</dl>';
                } else if (hub) {
                    const key = hub.dataset.mgHub;
                    highlight({hub: key});
                    const [kind, label] = [key.slice(0, 1), key.slice(2)];
                    const related = DATA.filter((m) => (kind === 'p' ? m.project : m.type) === label);
                    panel.innerHTML = '<b class="text-cyan-100">' + (kind === 'p' ? 'Projekt' : 'Typ') + ': ' + esc(label) + '</b>' +
                        '<p class="mt-1 text-xs text-slate-500">' + related.length + ' verknüpfte Erinnerung(en)</p>' +
                        '<ul class="mt-2 max-h-64 space-y-1 overflow-y-auto text-xs">' +
                        related.map((m) => '<li class="rounded border border-slate-800 px-2 py-1.5 text-slate-300">' + esc(m.summary) + '</li>').join('') + '</ul>';
                } else {
                    highlight(null);
                }
            });
        })();
        </script>
    @endif
</section>
