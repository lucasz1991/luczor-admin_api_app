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
        <h1 class="text-2xl font-semibold text-white">Luczor Admin API</h1>
        <p class="mt-2 text-sm text-slate-400">Auth, Device API Keys, Modell-Fallbacks und Brain-Sync Archiv.</p>
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

    <section class="grid gap-4 md:grid-cols-5">
        <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php $__currentLoopData = $archiveCounts; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $label => $count): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
            <div class="luczor-card p-4">
                <div class="text-xs uppercase tracking-wider text-slate-500"><?php echo e(str_replace('_', ' ', $label)); ?></div>
                <div class="mt-2 text-3xl font-semibold text-cyan-100"><?php echo e($count); ?></div>
            </div>
        <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
    </section>

    <section class="mt-8 grid gap-6 lg:grid-cols-2">
        <div class="luczor-card p-5">
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

        <div class="luczor-card p-5">
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

    <section class="mt-8 grid gap-6 lg:grid-cols-2">
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