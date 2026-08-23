    <script>
    (function () {
        'use strict';
        const CSRF = @js(csrf_token());
        const STATUS_URL = @js(route('dashboard.workflow-runs.status', $workflowPreviewRun));
        const CANCEL_URL = @js(route('dashboard.workflow-runs.cancel', $workflowPreviewRun));
        const APPROVE_BASE = @js(url('/dashboard/workflow-steps'));
        const TERMINAL = ['completed', 'failed', 'cancelled'];
        const BADGE = {
            queued: ['Wartet', 'bg-slate-400/10 text-slate-300 ring-slate-400/30'],
            ready: ['Bereit', 'bg-cyan-400/10 text-cyan-200 ring-cyan-400/30'],
            running: ['Läuft', 'bg-blue-400/10 text-blue-200 ring-blue-400/30'],
            awaiting_approval: ['Freigabe nötig', 'bg-amber-400/10 text-amber-200 ring-amber-400/30'],
            completed: ['Fertig', 'bg-emerald-400/10 text-emerald-200 ring-emerald-400/30'],
            failed: ['Fehler', 'bg-rose-400/10 text-rose-200 ring-rose-400/30'],
            cancelled: ['Abgebrochen', 'bg-slate-500/10 text-slate-400 ring-slate-500/30'],
            skipped: ['Übersprungen', 'bg-slate-500/10 text-slate-400 ring-slate-500/30'],
        };
        const CHIP = {
            running: 'border-amber-400/60 bg-amber-400/10 ring-2 ring-amber-400/40',
            ready: 'border-amber-400/40 bg-amber-400/5',
            awaiting_approval: 'border-amber-400/60 bg-amber-400/10 ring-2 ring-amber-400/40',
            completed: 'border-emerald-400/40 bg-emerald-400/10',
            failed: 'border-rose-400/50 bg-rose-400/10',
            skipped: 'border-slate-600 bg-slate-800/60 opacity-70',
            cancelled: 'border-slate-600 bg-slate-800/60 opacity-70',
            queued: 'border-slate-700 bg-slate-900/40',
        };
        const esc = (v) => String(v ?? '').replace(/[&<>"']/g, (c) => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
        const dur = (ms) => {
            if (ms === null || ms === undefined) return '—';
            if (ms < 1000) return '< 1s';
            const s = Math.round(ms / 1000);
            return s < 60 ? s + 's' : Math.floor(s / 60) + 'm ' + (s % 60) + 's';
        };
        const el = (sel) => document.querySelector(sel);
        let timer = null;

        function badge(status) {
            const [label, cls] = BADGE[status] || [status, BADGE.queued[1]];
            return '<span class="inline-flex items-center rounded-full px-2 py-0.5 text-[10px] font-semibold ring-1 ' + cls + '">' + esc(label) + '</span>';
        }

        function render(data) {
            const run = data.run, steps = data.steps || [], artifacts = data.artifacts || [];
            const [label, cls] = BADGE[run.status] || [run.status, BADGE.queued[1]];
            const b = el('[data-rp-badge]');
            b.textContent = label;
            b.className = 'inline-flex items-center rounded-full px-2.5 py-1 text-xs font-semibold ring-1 ' + cls;
            el('[data-rp-duration]').textContent = dur(run.duration_ms ?? (run.started_at && !run.finished_at ? Date.now() - new Date(run.started_at).getTime() : null));
            el('[data-rp-cancel]').classList.toggle('hidden', TERMINAL.includes(run.status));
            el('[data-rp-json]').href = 'data:application/json;base64,' + btoa(unescape(encodeURIComponent(JSON.stringify(data, null, 2))));

            // Minimap-Streifen
            el('[data-rp-strip]').innerHTML = steps.map((s, i) => {
                const active = s.id === run.current_workflow_step_id || s.status === 'running';
                const chip = CHIP[active ? 'running' : s.status] || CHIP.queued;
                return (i ? '<div class="flex items-center px-0.5 text-slate-600">→</div>' : '') +
                    '<div class="flex h-14 min-w-[5.75rem] flex-1 flex-col justify-between rounded-md border px-1.5 py-1 ' + chip + '" title="' + esc(s.key) + ' · ' + esc(s.status) + '">' +
                    '<div class="truncate text-[9px] font-semibold text-slate-200">' + esc(s.title || s.key) + '</div>' +
                    '<div class="truncate font-mono text-[8px] text-slate-500">' + esc(s.type) + '</div>' +
                    '<div class="text-[8px] text-slate-400">' + esc(s.attempts) + '/' + esc(s.max_attempts) + ' · ' + dur(s.duration_ms) + '</div></div>';
            }).join('');

            // Schritt-Detailliste
            el('[data-rp-steps]').innerHTML = steps.map((s) => {
                const approve = s.status === 'awaiting_approval'
                    ? '<button data-approve="' + s.id + '" class="mt-2 inline-flex items-center rounded-md border border-amber-400/40 bg-amber-400/10 px-3 py-1.5 text-xs font-semibold text-amber-200 hover:bg-amber-400/20">Schritt freigeben</button>'
                    : '';
                const err = s.error ? '<div class="mt-2 rounded border border-rose-400/30 bg-rose-400/10 p-2 font-mono text-[11px] text-rose-200">' + esc(s.error) + '</div>' : '';
                const out = s.output ? '<pre class="mt-2 max-h-40 overflow-auto rounded border border-slate-800 bg-slate-950/70 p-2 font-mono text-[10px] text-slate-400">' + esc(JSON.stringify(s.output, null, 2)) + '</pre>' : '';
                const deps = (s.depends_on || []).map((d) => '<span class="rounded border border-slate-700 px-1 font-mono text-[9px] text-slate-500">' + esc(d) + '</span>').join(' ');
                return '<details class="rounded border border-slate-800 bg-slate-900/30 p-3"' + (s.status === 'running' || s.status === 'awaiting_approval' || s.status === 'failed' ? ' open' : '') + '>' +
                    '<summary class="flex cursor-pointer flex-wrap items-center gap-2 text-sm"><b class="text-cyan-100">' + esc(s.title || s.key) + '</b>' +
                    '<span class="font-mono text-[10px] text-slate-500">' + esc(s.type) + '</span>' + badge(s.status) +
                    '<span class="ml-auto text-[10px] text-slate-500">' + dur(s.duration_ms) + ' · Versuch ' + esc(s.attempts) + '/' + esc(s.max_attempts) + '</span></summary>' +
                    (deps ? '<div class="mt-2 flex flex-wrap gap-1 text-[10px] text-slate-500">Abhängig von: ' + deps + '</div>' : '') +
                    err + out + approve + '</details>';
            }).join('') || '<p class="text-xs text-slate-500">Keine Schritte.</p>';

            // Timeline (Ereignisse aus Zeitstempeln, neueste zuerst)
            const events = [];
            steps.forEach((s) => {
                if (s.started_at) events.push({at: s.started_at, text: (s.title || s.key) + ' gestartet'});
                if (s.finished_at) events.push({at: s.finished_at, text: (s.title || s.key) + ' → ' + (BADGE[s.status] ? BADGE[s.status][0] : s.status)});
            });
            if (run.started_at) events.push({at: run.started_at, text: 'Lauf gestartet'});
            if (run.finished_at) events.push({at: run.finished_at, text: 'Lauf beendet → ' + label});
            events.sort((a, b) => new Date(b.at) - new Date(a.at));
            el('[data-rp-timeline]').innerHTML = events.map((e) =>
                '<div class="rounded border border-slate-800 bg-slate-900/30 px-2 py-1.5"><span class="text-slate-500">' + esc(new Date(e.at).toLocaleTimeString('de-DE')) + '</span> <span class="text-slate-300">' + esc(e.text) + '</span></div>'
            ).join('') || '<p class="text-slate-500">Noch keine Ereignisse.</p>';

            // Artefakte
            el('[data-rp-artifacts-card]').hidden = artifacts.length === 0;
            el('[data-rp-artifacts]').innerHTML = artifacts.map((a) =>
                '<div class="rounded border ' + (a.status === 'success' ? 'border-slate-800 bg-slate-900/30' : 'border-amber-400/30 bg-amber-400/5') + ' p-2 text-[11px]">' +
                '<div class="flex items-center justify-between gap-2"><span class="font-semibold text-slate-200">' + esc(a.phase) + ' · ' + esc(a.artifact_type) + '</span>' + badge(a.status === 'success' ? 'completed' : 'failed') + '</div>' +
                '<div class="mt-1 font-mono text-[10px] text-slate-500">' + esc(a.step_key) + (a.label ? ' · ' + esc(a.label) : '') + '</div>' +
                (a.error_message ? '<div class="mt-1 text-rose-300">' + esc(a.error_message) + '</div>' : '') +
                (a.metadata ? '<pre class="mt-1 max-h-24 overflow-auto font-mono text-[9px] text-slate-500">' + esc(JSON.stringify(a.metadata, null, 1)) + '</pre>' : '') +
                '</div>').join('');

            // Lauf-Ausgabe
            const hasOutput = run.output && Object.keys(run.output).length;
            el('[data-rp-output-card]').hidden = !hasOutput;
            if (hasOutput) el('[data-rp-output]').textContent = JSON.stringify(run.output, null, 2);

            if (TERMINAL.includes(run.status) && timer) { clearInterval(timer); timer = null; }
        }

        async function poll() {
            try {
                const res = await fetch(STATUS_URL, {headers: {Accept: 'application/json'}, credentials: 'same-origin'});
                if (res.ok) render(await res.json());
            } catch (e) { /* nächster Poll versucht es erneut */ }
        }

        document.addEventListener('click', async (ev) => {
            const approve = ev.target.closest('[data-approve]');
            if (approve) {
                approve.disabled = true;
                await fetch(APPROVE_BASE + '/' + approve.dataset.approve + '/approve', {method: 'POST', headers: {Accept: 'application/json', 'X-CSRF-TOKEN': CSRF}, credentials: 'same-origin'});
                poll();
            }
        });
        el('[data-rp-cancel]').addEventListener('click', async () => {
            if (!confirm('Diesen Lauf wirklich abbrechen?')) return;
            await fetch(CANCEL_URL, {method: 'POST', headers: {Accept: 'application/json', 'X-CSRF-TOKEN': CSRF}, credentials: 'same-origin'});
            poll();
        });

        poll();
        timer = setInterval(poll, 3000);
    })();
    </script>

