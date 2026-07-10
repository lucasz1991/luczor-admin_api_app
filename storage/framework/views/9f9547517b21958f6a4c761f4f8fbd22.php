<?php if (isset($component)) { $__componentOriginal9ac128a9029c0e4701924bd2d73d7f54 = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginal9ac128a9029c0e4701924bd2d73d7f54 = $attributes; } ?>
<?php $component = App\View\Components\AppLayout::resolve([] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? (array) $attributes->getIterator() : [])); ?>
<?php $component->withName('app-layout'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag && $constructor = (new ReflectionClass(App\View\Components\AppLayout::class))->getConstructor()): ?>
<?php $attributes = $attributes->except(collect($constructor->getParameters())->map->getName()->all()); ?>
<?php endif; ?>
<?php $component->withAttributes([]); ?>
    <div class="mb-8">
        <h1 class="text-2xl font-semibold text-white"><?php echo e($isAdmin ? 'Luczor Admin Control' : 'Mein Luczor'); ?></h1>
        <p class="mt-2 text-sm text-slate-400"><?php echo e($isAdmin ? 'Provider, Routing, Telemetrie, Policies und Systembetrieb.' : 'Geräte, Projekte, Memory und sichere Cloud-Verbindung.'); ?></p>
    </div>

    <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if(session('status')): ?>
        <div class="mb-6 rounded-md border border-emerald-400/30 bg-emerald-400/10 p-3 text-sm text-emerald-100"><?php echo e(session('status')); ?></div>
    <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>

    <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if(session('plain_api_key')): ?>
        <div class="mb-6 rounded-md border border-cyan-400/30 bg-cyan-400/10 p-4">
            <div class="text-sm font-semibold text-cyan-100">Neuer API Key, nur jetzt sichtbar:</div>
            <code class="mt-2 block break-all rounded bg-slate-950 p-3 text-sm text-cyan-200"><?php echo e(session('plain_api_key')); ?></code>
        </div>
    <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>

    <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($errors->any()): ?>
        <div class="mb-6 rounded-md border border-rose-400/30 bg-rose-400/10 p-4 text-sm text-rose-100">
            <ul class="list-disc pl-5">
                <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php $__currentLoopData = $errors->all(); $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $error): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
                    <li><?php echo e($error); ?></li>
                <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
            </ul>
        </div>
    <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>

    <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if(! $isAdmin): ?>
        <section class="grid gap-6 xl:grid-cols-[1.35fr_0.85fr]">
            <div class="luczor-card overflow-hidden border-cyan-400/30">
                <div class="border-b border-cyan-400/10 bg-cyan-400/5 px-5 py-3">
                    <div class="flex items-center justify-between gap-3">
                        <div>
                            <h1 class="font-mono text-xl font-semibold text-cyan-100">luczor terminal</h1>
                            <p class="mt-1 text-sm text-slate-400">Cloud-gesteuerter Zugriff auf deine verbundenen Geraete, Projekte und Repositories.</p>
                        </div>
                        <span class="rounded-full border border-emerald-400/30 bg-emerald-400/10 px-3 py-1 text-xs font-semibold text-emerald-200">USER MODE</span>
                    </div>
                </div>
                <div class="space-y-4 p-5 font-mono text-sm">
                    <div class="text-slate-500">$ luczor status --scope=user</div>
                    <div class="grid gap-3 md:grid-cols-4">
                        <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php $__currentLoopData = $archiveCounts; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $label => $count): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
                            <div class="rounded-md border border-cyan-400/10 bg-slate-950/70 p-3">
                                <div class="text-[10px] uppercase tracking-[0.18em] text-slate-500"><?php echo e(str_replace('_', ' ', $label)); ?></div>
                                <div class="mt-2 text-2xl font-semibold text-cyan-100"><?php echo e($count); ?></div>
                            </div>
                        <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                    </div>
                    <div class="rounded-md border border-cyan-400/10 bg-slate-950/80 p-4">
                        <div class="text-cyan-300">&gt; online clients</div>
                        <div class="mt-2 text-slate-300">
                            <?php echo e($clientIds->count() ? $clientIds->implode(', ') : 'Noch kein Device verbunden. Erzeuge unten ein Geraete-Token und verbinde die Tauri-App.'); ?>

                        </div>
                    </div>
                    <div class="grid gap-3 md:grid-cols-3">
                        <div class="rounded-md border border-cyan-400/10 bg-slate-950/70 p-3">
                            <div class="text-slate-500">control</div>
                            <div class="mt-1 text-cyan-100">cloud queue bereit</div>
                        </div>
                        <div class="rounded-md border border-cyan-400/10 bg-slate-950/70 p-3">
                            <div class="text-slate-500">mode</div>
                            <div class="mt-1 text-cyan-100">agent assisted</div>
                        </div>
                        <div class="rounded-md border border-cyan-400/10 bg-slate-950/70 p-3">
                            <div class="text-slate-500">provider</div>
                            <div class="mt-1 text-cyan-100">server routed</div>
                        </div>
                    </div>
                </div>
            </div>

            <div id="devices" class="luczor-card p-5">
                <h2 class="text-lg font-semibold text-white">Meine Geraete</h2>
                <div class="mt-4 space-y-3">
                    <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php $__empty_1 = true; $__currentLoopData = $apiKeys; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $key): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); $__empty_1 = false; ?>
                        <div class="rounded border border-slate-800 bg-slate-950/50 p-3 text-sm">
                            <div class="flex items-center justify-between gap-3">
                                <div>
                                    <div class="font-semibold text-slate-100"><?php echo e($key->device_name ?: $key->name); ?></div>
                                    <div class="text-slate-500"><?php echo e($key->device_id ?: 'keine Device ID'); ?></div>
                                </div>
                                <span class="rounded-full px-2 py-1 text-xs <?php echo e($key->active ? 'bg-emerald-400/10 text-emerald-200' : 'bg-rose-400/10 text-rose-200'); ?>"><?php echo e($key->active ? 'aktiv' : 'inaktiv'); ?></span>
                            </div>
                            <div class="mt-2 text-xs text-slate-500">Abilities: <?php echo e(implode(', ', $key->abilities ?? [])); ?></div>
                        </div>
                    <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); if ($__empty_1): ?>
                        <p class="text-sm text-slate-500">Noch keine Geraete verbunden.</p>
                    <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                </div>
            </div>
        </section>

        <section class="mt-8 grid gap-6 lg:grid-cols-2">
            <div id="projects" class="luczor-card p-5">
                <h2 class="text-lg font-semibold text-white">Meine Projekte</h2>
                <div class="mt-4 space-y-3">
                    <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php $__empty_1 = true; $__currentLoopData = $userProjects; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $project): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); $__empty_1 = false; ?>
                        <div class="rounded border border-slate-800 bg-slate-950/50 p-3 text-sm">
                            <div class="flex items-center justify-between gap-3">
                                <div>
                                    <div class="font-semibold text-cyan-100"><?php echo e($project->name); ?></div>
                                    <div class="font-mono text-xs text-slate-500"><?php echo e($project->external_id); ?></div>
                                </div>
                                <span class="text-xs text-slate-500"><?php echo e(optional($project->updated_at)->diffForHumans()); ?></span>
                            </div>
                        </div>
                    <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); if ($__empty_1): ?>
                        <p class="text-sm text-slate-500">Noch keine Projekte synchronisiert.</p>
                    <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                </div>
            </div>

            <div id="github" class="luczor-card p-5">
                <h2 class="text-lg font-semibold text-white">Cloud / GitHub</h2>
                <p class="mt-1 text-sm text-slate-400">Hier wird der Codex-aehnliche Einstieg fuer Online-Geraete und Repository-basierte Projekte gebuendelt.</p>
                <div class="mt-4 rounded-md border border-cyan-400/10 bg-slate-950/70 p-4 font-mono text-sm">
                    <div class="text-slate-500">$ luczor attach github --repo owner/repo</div>
                    <div class="mt-2 text-cyan-100">Repository-Verknuepfung ist als naechster Cloud-Workflow vorbereitet.</div>
                </div>
                <div class="mt-4">
                    <h3 class="text-sm font-semibold text-slate-200">Letzte Agent-Events</h3>
                    <div class="mt-3 space-y-2">
                        <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php $__empty_1 = true; $__currentLoopData = $userEvents; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $event): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); $__empty_1 = false; ?>
                            <div class="rounded border border-slate-800 bg-slate-950/50 px-3 py-2 text-xs">
                                <span class="font-mono text-cyan-200"><?php echo e($event->event_type); ?></span>
                                <span class="ml-2 text-slate-500"><?php echo e(optional($event->created_at)->diffForHumans()); ?></span>
                            </div>
                        <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); if ($__empty_1): ?>
                            <p class="text-sm text-slate-500">Noch keine Agent-Events.</p>
                        <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                    </div>
                </div>
            </div>
        </section>

        <section id="connect" class="mt-8 luczor-card p-5">
            <h2 class="text-lg font-semibold text-white">Geraete-Verbindung erstellen</h2>
            <p class="mt-1 text-sm text-slate-400">Dieses Token verbindet deine Tauri-App mit deinem User-Bereich. Provider-API-Keys bleiben auf dem Server.</p>
            <form class="mt-4 space-y-3" method="POST" action="<?php echo e(route('dashboard.api-keys.store')); ?>">
                <?php echo csrf_field(); ?>
                <input class="luczor-input" name="name" placeholder="Name, z.B. Mein Desktop" required>
                <div class="grid gap-3 md:grid-cols-2">
                    <input class="luczor-input" name="device_id" placeholder="Device ID optional">
                    <input class="luczor-input" name="device_name" placeholder="Device Name optional">
                </div>
                <input class="luczor-input" name="expires_at" type="datetime-local">
                <div class="rounded border border-cyan-400/10 bg-cyan-400/5 p-3 text-sm text-slate-400">Die sicheren Geräteberechtigungen werden automatisch vom Server vergeben. Provider- und Modellrechte sind ausgeschlossen.</div>
                <button class="luczor-btn" type="submit">Verbindungstoken erzeugen</button>
            </form>
        </section>
    <?php else: ?>

    <section id="overview" class="grid gap-4 md:grid-cols-3 xl:grid-cols-6">
        <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php $__currentLoopData = $operations; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $label => $count): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
            <div class="luczor-card p-4">
                <div class="text-xs uppercase tracking-wider text-slate-500"><?php echo e(str_replace('_', ' ', $label)); ?></div>
                <div class="mt-2 text-2xl font-semibold text-cyan-100"><?php echo e($count); ?></div>
            </div>
        <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
    </section>

    <section id="telemetry" class="mt-8 space-y-6">
        <div class="flex items-end justify-between gap-4">
            <div><h2 class="text-xl font-semibold text-white">Provider- und Modell-Telemetrie</h2><p class="mt-1 text-sm text-slate-400">30 Tage · Kosten, Nutzen, Geschwindigkeit, Fallbacks und Ergebnisqualität.</p></div>
            <div class="flex flex-wrap gap-2"><a class="luczor-btn-secondary" href="<?php echo e(route('dashboard.telemetry.export', ['format' => 'jsonl', 'days' => 30])); ?>">JSONL Export</a><a class="luczor-btn-secondary" href="<?php echo e(route('dashboard.telemetry.export', ['format' => 'csv', 'days' => 30])); ?>">CSV Export</a><span class="rounded-full border border-cyan-400/20 bg-cyan-400/5 px-3 py-1 text-xs text-cyan-200">Admin only</span></div>
        </div>
        <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-5">
            <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php $__currentLoopData = [
                'Runs' => number_format($telemetry['runs_30d'] ?? 0),
                'Erfolg' => number_format($telemetry['success_rate'] ?? 0, 1).' %',
                'Kosten' => '$ '.number_format($telemetry['cost_30d'] ?? 0, 6),
                'Kosten / Erfolg' => '$ '.number_format($telemetry['cost_per_success'] ?? 0, 6),
                'Fallback-Rate' => number_format($telemetry['fallback_rate'] ?? 0, 1).' %',
                'Ø Latenz' => number_format($telemetry['avg_latency_ms'] ?? 0).' ms',
                'Ø TTFT' => number_format($telemetry['avg_ttft_ms'] ?? 0).' ms',
                'Ø Tokens/s' => number_format($telemetry['avg_tokens_per_second'] ?? 0, 2),
                'Input Tokens' => number_format($telemetry['input_tokens'] ?? 0),
                'Output Tokens' => number_format($telemetry['output_tokens'] ?? 0),
            ]; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $label => $value): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
                <div class="luczor-card p-4"><div class="text-[10px] uppercase tracking-[.18em] text-slate-500"><?php echo e($label); ?></div><div class="mt-2 font-mono text-xl text-cyan-100"><?php echo e($value); ?></div></div>
            <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
        </div>
        <div class="luczor-card overflow-x-auto p-5">
            <h3 class="font-semibold text-white">Leistung je Modell und Aufgabentyp</h3>
            <table class="mt-4 min-w-full text-left text-xs">
                <thead class="border-b border-slate-800 text-slate-500"><tr><th class="py-2">Modell / Task</th><th>Runs</th><th>Erfolg</th><th>Qualität</th><th>Latenz</th><th>TTFT</th><th>Tok/s</th><th>Kosten gesamt</th><th>Ø Kosten</th></tr></thead>
                <tbody class="divide-y divide-slate-900"><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php $__empty_1 = true; $__currentLoopData = $modelTelemetry; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $row): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); $__empty_1 = false; ?><tr>
                    <td class="py-3"><div class="font-mono text-cyan-200"><?php echo e($row->model_id); ?></div><div class="text-slate-500"><?php echo e($row->provider_id); ?> · <?php echo e($row->task_type); ?></div></td>
                    <td><?php echo e($row->runs); ?></td><td><?php echo e(number_format($row->success_rate * 100, 1)); ?> %</td><td><?php echo e($row->avg_quality === null ? '—' : number_format($row->avg_quality, 3)); ?></td>
                    <td><?php echo e(number_format($row->avg_latency_ms)); ?> ms</td><td><?php echo e(number_format($row->avg_ttft_ms)); ?> ms</td><td><?php echo e(number_format($row->avg_tps, 2)); ?></td>
                    <td>$ <?php echo e(number_format($row->total_cost, 6)); ?></td><td>$ <?php echo e(number_format($row->avg_cost, 6)); ?></td>
                </tr><?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); if ($__empty_1): ?><tr><td colspan="9" class="py-6 text-center text-slate-500">Noch keine LLM-Läufe. Daten entstehen automatisch über den Provider-Proxy.</td></tr><?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?></tbody>
            </table>
        </div>
        <div class="grid gap-6 xl:grid-cols-[1fr_1.4fr]">
            <div class="luczor-card p-5"><h3 class="font-semibold text-white">Aktuelle Rankings</h3><div class="mt-4 space-y-2"><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php $__empty_1 = true; $__currentLoopData = $modelRankings; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $ranking): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); $__empty_1 = false; ?><div class="rounded border border-slate-800 bg-slate-950/60 p-3 text-xs"><div class="flex justify-between"><span class="font-mono text-cyan-200"><?php echo e($ranking->task_type); ?></span><b><?php echo e(number_format($ranking->score, 4)); ?></b></div><div class="mt-1 text-slate-400"><?php echo e($ranking->model_id); ?> · <?php echo e($ranking->sample_count); ?> Samples · $<?php echo e(number_format($ranking->avg_cost_total, 6)); ?></div></div><?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); if ($__empty_1): ?><p class="text-sm text-slate-500">Rankings werden ab fünf Messwerten je Modell aktiv.</p><?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?></div></div>
            <div class="luczor-card overflow-x-auto p-5"><h3 class="font-semibold text-white">Letzte Provider-Versuche</h3><table class="mt-4 min-w-full text-left text-xs"><thead class="text-slate-500"><tr><th>Run</th><th>Versuch</th><th>Modell</th><th>Status</th><th>TTFT / Gesamt</th><th>Tokens</th><th>Kosten</th></tr></thead><tbody class="divide-y divide-slate-900"><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php $__currentLoopData = $recentAttempts; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $attempt): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?><tr><td class="py-2 font-mono">#<?php echo e($attempt->llm_run_id); ?></td><td><?php echo e($attempt->attempt_no); ?></td><td class="max-w-52 truncate text-cyan-200"><?php echo e($attempt->model_id); ?></td><td class="<?php echo e($attempt->status === 'completed' ? 'text-emerald-300' : 'text-rose-300'); ?>"><?php echo e($attempt->status); ?></td><td><?php echo e($attempt->ttft_ms ?? '—'); ?> / <?php echo e($attempt->total_ms ?? '—'); ?> ms</td><td><?php echo e($attempt->input_tokens ?? 0); ?> → <?php echo e($attempt->output_tokens ?? 0); ?></td><td>$<?php echo e(number_format($attempt->effective_cost ?? 0, 8)); ?></td></tr><?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?></tbody></table></div>
        </div>
    </section>

    <section id="archives" class="grid gap-4 md:grid-cols-5">
        <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php $__currentLoopData = $archiveCounts; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $label => $count): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
            <div class="luczor-card p-4">
                <div class="text-xs uppercase tracking-wider text-slate-500"><?php echo e(str_replace('_', ' ', $label)); ?></div>
                <div class="mt-2 text-3xl font-semibold text-cyan-100"><?php echo e($count); ?></div>
            </div>
        <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
    </section>

    <section id="settings" class="mt-8">
        <div class="luczor-card p-5">
            <h2 class="text-lg font-semibold text-white">Client-Einstellungen (Server-Defaults)</h2>
            <p class="mt-1 text-sm text-slate-400">Werden an die Desktop-App ausgeliefert (<code class="text-cyan-200">/api/v1/runtime-settings</code>).</p>
            <form class="mt-4" method="POST" action="<?php echo e(route('dashboard.settings.store')); ?>">
                <?php echo csrf_field(); ?>
                <div class="grid gap-3 md:grid-cols-2">
                    <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php $__currentLoopData = $settings; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $setting): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
                        <div class="rounded border border-slate-800 bg-slate-950/50 px-3 py-2">
                            <div class="text-sm text-slate-200"><?php echo e($setting->label ?? $setting->key); ?> <span class="text-xs text-slate-500">(<?php echo e($setting->key); ?>)</span></div>
                            <div class="mt-2">
                                <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($setting->type === 'bool'): ?>
                                    <label class="flex items-center gap-2 text-sm text-slate-300">
                                        <input type="checkbox" class="rounded border-slate-700 bg-slate-950 text-cyan-400" name="settings[<?php echo e($setting->key); ?>]" value="1" <?php if($setting->value['v'] ?? false): echo 'checked'; endif; ?>>
                                        aktiviert
                                    </label>
                                <?php elseif($setting->type === 'number'): ?>
                                    <input type="number" step="any" class="luczor-input" name="settings[<?php echo e($setting->key); ?>]" value="<?php echo e($setting->value['v'] ?? 0); ?>">
                                <?php else: ?>
                                    <input type="text" class="luczor-input" name="settings[<?php echo e($setting->key); ?>]" value="<?php echo e($setting->value['v'] ?? ''); ?>">
                                <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                            </div>
                        </div>
                    <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                </div>
                <button class="luczor-btn mt-4" type="submit">Einstellungen speichern</button>
            </form>
        </div>
    </section>

    <section class="mt-8 grid gap-6 lg:grid-cols-2">
        <div id="api-keys" class="luczor-card p-5">
            <h2 class="text-lg font-semibold text-white">Device API Key erstellen</h2>
            <form class="mt-4 space-y-3" method="POST" action="<?php echo e(route('dashboard.api-keys.store')); ?>">
                <?php echo csrf_field(); ?>
                <input class="luczor-input" name="name" placeholder="Name, z.B. Desktop LZ" required>
                <div class="grid gap-3 md:grid-cols-2">
                    <input class="luczor-input" name="device_id" placeholder="Device ID optional">
                    <input class="luczor-input" name="device_name" placeholder="Device Name optional">
                </div>
                <input class="luczor-input" name="expires_at" type="datetime-local">
                <div class="grid gap-2 md:grid-cols-2">
                    <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php $__currentLoopData = $abilities; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $ability): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
                        <label class="flex items-center gap-2 rounded border border-slate-800 bg-slate-950/50 px-3 py-2 text-sm text-slate-200">
                            <input class="rounded border-slate-700 bg-slate-950 text-cyan-400" type="checkbox" name="abilities[]" value="<?php echo e($ability); ?>" <?php if($ability === 'all'): echo 'checked'; endif; ?>>
                            <?php echo e($ability); ?>

                        </label>
                    <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                </div>
                <button class="luczor-btn" type="submit">Key erzeugen</button>
            </form>
        </div>

        <div id="providers" class="luczor-card p-5">
            <h2 class="text-lg font-semibold text-white">Provider Credential</h2>
            <form class="mt-4 space-y-3" method="POST" action="<?php echo e(route('dashboard.provider-credentials.store')); ?>">
                <?php echo csrf_field(); ?>
                <div class="grid gap-3 md:grid-cols-2">
                    <input class="luczor-input" name="provider" placeholder="openrouter, elevenlabs, ..." required>
                    <input class="luczor-input" name="label" placeholder="Label" required>
                </div>
                <input class="luczor-input" name="api_key" placeholder="API Key wird verschluesselt gespeichert" required>
                <input class="luczor-input" name="base_url" placeholder="Base URL optional">
                <button class="luczor-btn" type="submit">Credential speichern</button>
            </form>
        </div>
    </section>

    <section class="mt-8 grid gap-6 xl:grid-cols-2">
        <div class="luczor-card p-5">
            <h2 class="text-lg font-semibold text-white">Provider-Preissnapshot</h2>
            <p class="mt-1 text-sm text-slate-400">Fallback, falls der Provider keine Kosten meldet. Historische Läufe behalten ihren Snapshot.</p>
            <form class="mt-4 grid gap-3 md:grid-cols-2" method="POST" action="<?php echo e(route('dashboard.provider-prices.store')); ?>"><?php echo csrf_field(); ?>
                <input class="luczor-input" name="provider_id" value="openrouter" required><input class="luczor-input" name="model_id" placeholder="provider/model-id" required>
                <input class="luczor-input" name="input_per_million" type="number" min="0" step="0.00000001" placeholder="Input $ / 1M" required><input class="luczor-input" name="output_per_million" type="number" min="0" step="0.00000001" placeholder="Output $ / 1M" required>
                <input class="luczor-input" name="cache_read_per_million" type="number" min="0" step="0.00000001" placeholder="Cache read $ / 1M"><input class="luczor-input" name="cache_write_per_million" type="number" min="0" step="0.00000001" placeholder="Cache write $ / 1M">
                <input type="hidden" name="currency" value="USD"><input class="luczor-input" name="valid_from" type="datetime-local" value="<?php echo e(now()->format('Y-m-d\TH:i')); ?>" required>
                <button class="luczor-btn" type="submit">Preisversion speichern</button>
            </form>
            <div class="mt-4 space-y-2"><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php $__currentLoopData = $providerPrices->take(10); $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $price): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?><div class="rounded border border-slate-800 p-2 text-xs"><span class="font-mono text-cyan-200"><?php echo e($price->model_id); ?></span><span class="ml-2 text-slate-500">in $<?php echo e($price->input_per_million); ?> · out $<?php echo e($price->output_per_million); ?> / 1M · ab <?php echo e($price->valid_from); ?></span></div><?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?></div>
        </div>
        <div class="luczor-card p-5"><h2 class="text-lg font-semibold text-white">Provider-Status</h2><div class="mt-4 space-y-3"><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php $__empty_1 = true; $__currentLoopData = $providers; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $provider): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); $__empty_1 = false; ?><div class="flex items-center justify-between rounded border border-slate-800 bg-slate-950/50 p-3 text-sm"><div><b><?php echo e($provider->label); ?></b><div class="text-slate-500"><?php echo e($provider->provider); ?> · <?php echo e($provider->maskedKey()); ?></div></div><form method="POST" action="<?php echo e(route('dashboard.provider-credentials.toggle', $provider)); ?>"><?php echo csrf_field(); ?><button class="luczor-btn-secondary"><?php echo e($provider->active ? 'Deaktivieren' : 'Aktivieren'); ?></button></form></div><?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); if ($__empty_1): ?><p class="text-slate-500">Keine Provider.</p><?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?></div></div>
    </section>

    <section id="models" class="mt-8 grid gap-6 lg:grid-cols-2">
        <div class="luczor-card p-5">
            <h2 class="text-lg font-semibold text-white">Einzelnes Modellprofil</h2>
            <p class="mt-1 text-sm text-slate-400">Diese Profile werden pro Use-Case in Fallback-Reihenfolge zusammengestellt.</p>
            <form class="mt-4 space-y-3" method="POST" action="<?php echo e(route('dashboard.model-profiles.store')); ?>">
                <?php echo csrf_field(); ?>
                <input class="luczor-input" name="name" placeholder="Name, z.B. Planner Fast" required>
                <div class="grid gap-3 md:grid-cols-2">
                    <input class="luczor-input" name="provider" placeholder="Provider" required>
                    <input class="luczor-input" name="model_id" placeholder="Model ID" required>
                </div>
                <div class="grid gap-3 md:grid-cols-3">
                    <input class="luczor-input" name="temperature" type="number" min="0" max="2" step="0.01" value="0.20" required>
                    <input class="luczor-input" name="max_tokens" type="number" min="1" value="1200" required>
                    <input class="luczor-input" name="purpose" placeholder="Zweck">
                </div>
                <button class="luczor-btn" type="submit">Modellprofil speichern</button>
            </form>
        </div>

        <div class="luczor-card p-5">
            <h2 class="text-lg font-semibold text-white">Use-Case anlegen</h2>
            <p class="mt-1 text-sm text-slate-400">Jeder Fall bekommt danach eine eigene Modellkette.</p>
            <form class="mt-4 space-y-3" method="POST" action="<?php echo e(route('dashboard.model-use-cases.store')); ?>">
                <?php echo csrf_field(); ?>
                <input class="luczor-input" name="name" placeholder="z.B. Browser Agent, Vision, TTS" required>
                <textarea class="luczor-input" name="description" rows="3" placeholder="Beschreibung optional"></textarea>
                <button class="luczor-btn" type="submit">Use-Case speichern</button>
            </form>
        </div>
    </section>

    <section class="mt-8 luczor-card p-5">
        <h2 class="text-lg font-semibold text-white">Modell-Fallbacks pro Use-Case</h2>
        <p class="mt-1 text-sm text-slate-400">Niedrige Sortierung wird zuerst genutzt. Faellt ein Modell aus, folgt das naechste aktive Profil.</p>

        <form class="mt-4 grid gap-3 md:grid-cols-4" method="POST" action="<?php echo e(route('dashboard.model-use-case-entries.store')); ?>">
            <?php echo csrf_field(); ?>
            <select class="luczor-input" name="model_use_case_id" required>
                <option value="">Use-Case</option>
                <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php $__currentLoopData = $modelUseCases; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $useCase): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
                    <option value="<?php echo e($useCase->id); ?>"><?php echo e($useCase->slug); ?></option>
                <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
            </select>
            <select class="luczor-input" name="model_profile_id" required>
                <option value="">Modellprofil</option>
                <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php $__currentLoopData = $modelProfiles; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $profile): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
                    <option value="<?php echo e($profile->id); ?>"><?php echo e($profile->name); ?> / <?php echo e($profile->model_id); ?></option>
                <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
            </select>
            <input class="luczor-input" name="sort_order" type="number" min="1" value="1" required>
            <button class="luczor-btn mt-1" type="submit">Fallback setzen</button>
        </form>

        <div class="mt-6 grid gap-4 lg:grid-cols-2">
            <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php $__currentLoopData = $modelUseCases; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $useCase): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
                <div class="rounded-lg border border-slate-800 bg-slate-950/50 p-4">
                    <div class="flex items-center justify-between gap-3">
                        <div>
                            <h3 class="font-semibold text-cyan-100"><?php echo e($useCase->name); ?></h3>
                            <p class="text-xs text-slate-500"><?php echo e($useCase->slug); ?></p>
                        </div>
                        <span class="rounded-full bg-cyan-400/10 px-2 py-1 text-xs text-cyan-200"><?php echo e($useCase->entries->count()); ?> Modelle</span>
                    </div>
                    <ol class="mt-4 space-y-2">
                        <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php $__empty_1 = true; $__currentLoopData = $useCase->entries->sortBy('sort_order'); $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $entry): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); $__empty_1 = false; ?>
                            <li class="flex items-center justify-between rounded border border-slate-800 px-3 py-2 text-sm">
                                <span>
                                    <span class="font-mono text-cyan-200">#<?php echo e($entry->sort_order); ?></span>
                                    <?php echo e($entry->modelProfile?->name); ?>

                                    <span class="text-slate-500">(<?php echo e($entry->modelProfile?->model_id); ?>)</span>
                                </span>
                                <span class="text-xs <?php echo e($entry->active ? 'text-emerald-300' : 'text-slate-500'); ?>"><?php echo e($entry->active ? 'aktiv' : 'aus'); ?></span>
                            </li>
                        <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); if ($__empty_1): ?>
                            <li class="text-sm text-slate-500">Noch keine Fallbacks gesetzt.</li>
                        <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                    </ol>
                </div>
            <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
        </div>
    </section>

    <section id="optimizer" class="mt-8 grid gap-6 xl:grid-cols-3">
        <div class="luczor-card p-5"><h2 class="font-semibold text-white">Prompt-Version</h2><form class="mt-4 space-y-3" method="POST" action="<?php echo e(route('dashboard.prompt-templates.store')); ?>"><?php echo csrf_field(); ?><input class="luczor-input" name="key" placeholder="luczor.coding" required><input class="luczor-input" name="task_type" placeholder="coding.fix_bug"><textarea class="luczor-input" name="body" rows="6" placeholder="System-/Prompt-Template" required></textarea><button class="luczor-btn">Neue Version</button></form><div class="mt-4 text-xs text-slate-500"><?php echo e($promptTemplates->count()); ?> Versionen gespeichert</div></div>
        <div class="luczor-card p-5"><h2 class="font-semibold text-white">Kontextstrategie</h2><form class="mt-4 space-y-3" method="POST" action="<?php echo e(route('dashboard.context-strategies.store')); ?>"><?php echo csrf_field(); ?><input class="luczor-input" name="key" value="context.memory_code_budgeted" required><input class="luczor-input" name="name" value="Memory + Code budgetiert" required><textarea class="luczor-input font-mono text-xs" name="config" rows="6" required>{"git_tokens":250,"graph_tokens":1000,"memory_tokens":600,"raw_file_tokens":3500,"deduplicate":true}</textarea><button class="luczor-btn">Strategie speichern</button></form></div>
        <div class="luczor-card p-5"><h2 class="font-semibold text-white">Netzwerk-/Kostenpolicy</h2><form class="mt-4 grid gap-3 md:grid-cols-2" method="POST" action="<?php echo e(route('dashboard.network-policies.store')); ?>"><?php echo csrf_field(); ?><input class="luczor-input md:col-span-2" name="key" value="proxy.openrouter.default" required><input class="luczor-input md:col-span-2" name="name" value="OpenRouter Default" required><input class="luczor-input" name="connect_timeout_ms" type="number" value="10000" required><input class="luczor-input" name="request_timeout_ms" type="number" value="90000" required><input class="luczor-input" name="max_attempts" type="number" value="3" required><input class="luczor-input" name="backoff_ms" type="number" value="250" required><input class="luczor-input" name="max_cost_usd" type="number" step="0.000001" placeholder="Max $ / Run"><input class="luczor-input" name="max_input_tokens" type="number" value="24000" placeholder="Max Input"><input class="luczor-input" name="max_output_tokens" type="number" value="8192"><button class="luczor-btn">Policy speichern</button></form></div>
    </section>

    <section id="experiments" class="mt-8 grid gap-6 xl:grid-cols-[1.1fr_0.9fr]">
        <div class="luczor-card p-5">
            <h2 class="font-semibold text-white">A/B-Modellversuch</h2>
            <p class="mt-1 text-xs text-slate-500">Varianten dürfen ausschließlich bereits administrierte Modellprofile referenzieren. Das Routing bleibt serverseitig.</p>
            <form class="mt-4 grid gap-3 md:grid-cols-2" method="POST" action="<?php echo e(route('dashboard.llm-experiments.store')); ?>"><?php echo csrf_field(); ?>
                <input class="luczor-input" name="key" placeholder="coding-fast-v1" required>
                <input class="luczor-input" name="name" placeholder="Coding: Qualität gegen Kosten" required>
                <input class="luczor-input" name="task_type" value="coding" required>
                <select class="luczor-input" name="status"><option value="draft">Entwurf</option><option value="active">Aktiv</option><option value="paused">Pausiert</option><option value="completed">Beendet</option></select>
                <input class="luczor-input" name="traffic_percent" type="number" min="0" max="100" value="10" required>
                <textarea class="luczor-input font-mono text-xs md:col-span-2" name="variants" rows="4" required>[{"model_profile_slug":"luczor-default","weight":100}]</textarea>
                <textarea class="luczor-input font-mono text-xs md:col-span-2" name="success_criteria" rows="3">{"quality_min":0.8,"cost_max_usd":0.05,"latency_max_ms":15000}</textarea>
                <button class="luczor-btn">Experiment speichern</button>
            </form>
        </div>
        <div class="luczor-card p-5">
            <h2 class="font-semibold text-white">Experimente</h2>
            <div class="mt-4 space-y-3">
                <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php $__empty_1 = true; $__currentLoopData = $llmExperiments; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $experiment): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); $__empty_1 = false; ?>
                    <div class="rounded border border-slate-800 bg-slate-950/50 p-3 text-sm">
                        <div class="flex justify-between"><b class="text-cyan-100"><?php echo e($experiment->name); ?></b><span class="text-xs text-slate-400"><?php echo e($experiment->status); ?></span></div>
                        <div class="mt-1 font-mono text-xs text-slate-500"><?php echo e($experiment->task_type); ?> · <?php echo e($experiment->traffic_percent); ?>% Traffic</div>
                    </div>
                <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); if ($__empty_1): ?>
                    <p class="text-sm text-slate-500">Noch keine Experimente angelegt.</p>
                <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
            </div>
        </div>
    </section>

    <section class="mt-8 luczor-card p-5"><h2 class="font-semibold text-white">Aktive Modellprofile (nur Admin)</h2><div class="mt-4 grid gap-3 lg:grid-cols-2"><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php $__currentLoopData = $modelProfiles; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $profile): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?><div class="flex items-center justify-between rounded border border-slate-800 bg-slate-950/50 p-3 text-sm"><div><b class="text-cyan-100"><?php echo e($profile->name); ?></b><div class="font-mono text-xs text-slate-500"><?php echo e($profile->model_id); ?> · <?php echo e($profile->purpose); ?></div></div><form method="POST" action="<?php echo e(route('dashboard.model-profiles.toggle', $profile)); ?>"><?php echo csrf_field(); ?><button class="luczor-btn-secondary"><?php echo e($profile->active ? 'Deaktivieren' : 'Aktivieren'); ?></button></form></div><?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?></div></section>

    <section class="mt-8 grid gap-6 xl:grid-cols-[1fr_1.2fr]">
        <div class="luczor-card p-5">
            <h2 class="font-semibold text-white">Agent-Profil</h2>
            <form class="mt-4 grid gap-3 md:grid-cols-2" method="POST" action="<?php echo e(route('dashboard.agent-profiles.store')); ?>"><?php echo csrf_field(); ?>
                <input class="luczor-input" name="key" placeholder="backend" required><input class="luczor-input" name="name" placeholder="Backend Agent" required>
                <input class="luczor-input" name="type" placeholder="backend" required><select class="luczor-input" name="status"><option value="active">Aktiv</option><option value="draft">Entwurf</option><option value="disabled">Deaktiviert</option></select>
                <input class="luczor-input md:col-span-2" name="prompt_template_key" value="luczor.system">
                <textarea class="luczor-input font-mono text-xs md:col-span-2" name="required_sources" rows="2">["graphify","github","cognee"]</textarea>
                <textarea class="luczor-input font-mono text-xs md:col-span-2" name="capabilities" rows="2">[]</textarea>
                <textarea class="luczor-input font-mono text-xs md:col-span-2" name="config" rows="2">{"parallel_safe":false}</textarea>
                <button class="luczor-btn">Agent speichern</button>
            </form>
        </div>
        <div class="luczor-card p-5"><h2 class="font-semibold text-white">Orchestrator-Agenten</h2><div class="mt-4 grid gap-3 md:grid-cols-2"><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php $__currentLoopData = $agentProfiles; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $agent): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?><div class="rounded border border-slate-800 bg-slate-950/50 p-3 text-sm"><div class="flex justify-between"><b class="text-cyan-100"><?php echo e($agent->name); ?></b><span class="text-xs text-slate-500"><?php echo e($agent->status); ?></span></div><div class="mt-1 font-mono text-xs text-slate-500"><?php echo e($agent->type); ?> · <?php echo e(implode(', ', $agent->required_sources ?? [])); ?></div></div><?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?></div></div>
    </section>

    <section class="mt-8 grid gap-6 lg:grid-cols-2">
        <div class="luczor-card p-5">
            <h2 class="text-lg font-semibold text-white">Aktive API Keys</h2>
            <div class="mt-4 space-y-3">
                <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php $__empty_1 = true; $__currentLoopData = $apiKeys; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $key): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); $__empty_1 = false; ?>
                    <div class="rounded border border-slate-800 bg-slate-950/50 p-3 text-sm">
                        <div class="flex items-center justify-between">
                            <div>
                                <div class="font-semibold text-slate-100"><?php echo e($key->name); ?></div>
                                <div class="text-slate-500"><?php echo e($key->device_name ?: 'kein Device Name'); ?> / <?php echo e($key->device_id ?: 'keine Device ID'); ?></div>
                            </div>
                            <form method="POST" action="<?php echo e(route('dashboard.api-keys.toggle', $key)); ?>">
                                <?php echo csrf_field(); ?>
                                <button class="luczor-btn-secondary" type="submit"><?php echo e($key->active ? 'Deaktivieren' : 'Aktivieren'); ?></button>
                            </form>
                        </div>
                        <div class="mt-2 text-xs text-slate-500">Abilities: <?php echo e(implode(', ', $key->abilities ?? [])); ?></div>
                    </div>
                <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); if ($__empty_1): ?>
                    <p class="text-sm text-slate-500">Noch keine API Keys.</p>
                <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
            </div>
        </div>

        <div class="luczor-card p-5">
            <h2 class="text-lg font-semibold text-white">Provider Credentials</h2>
            <div class="mt-4 space-y-3">
                <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php $__empty_1 = true; $__currentLoopData = $providers; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $provider): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); $__empty_1 = false; ?>
                    <div class="rounded border border-slate-800 bg-slate-950/50 p-3 text-sm">
                        <div class="font-semibold text-slate-100"><?php echo e($provider->label); ?></div>
                        <div class="text-slate-500"><?php echo e($provider->provider); ?> / <?php echo e($provider->base_url ?: 'default endpoint'); ?></div>
                        <div class="mt-1 font-mono text-xs text-cyan-200"><?php echo e($provider->maskedKey()); ?></div>
                    </div>
                <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); if ($__empty_1): ?>
                    <p class="text-sm text-slate-500">Noch keine Provider Credentials.</p>
                <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
            </div>
        </div>
    </section>
    <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
 <?php echo $__env->renderComponent(); ?>
<?php endif; ?>
<?php if (isset($__attributesOriginal9ac128a9029c0e4701924bd2d73d7f54)): ?>
<?php $attributes = $__attributesOriginal9ac128a9029c0e4701924bd2d73d7f54; ?>
<?php unset($__attributesOriginal9ac128a9029c0e4701924bd2d73d7f54); ?>
<?php endif; ?>
<?php if (isset($__componentOriginal9ac128a9029c0e4701924bd2d73d7f54)): ?>
<?php $component = $__componentOriginal9ac128a9029c0e4701924bd2d73d7f54; ?>
<?php unset($__componentOriginal9ac128a9029c0e4701924bd2d73d7f54); ?>
<?php endif; ?>
<?php /**PATH E:\projekte\luczor\admin_api_app\resources\views/dashboard/index.blade.php ENDPATH**/ ?>