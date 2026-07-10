<!DOCTYPE html>
<html lang="<?php echo e(str_replace('_', '-', app()->getLocale())); ?>">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="<?php echo e(csrf_token()); ?>">
    <title><?php echo e(config('app.name')); ?></title>
    <?php echo app('Illuminate\Foundation\Vite')(['resources/css/app.css', 'resources/js/app.js']); ?>
    <?php echo \Livewire\Mechanisms\FrontendAssets\FrontendAssets::styles(); ?>

</head>
<?php
    $layoutUser = auth()->user();
    $layoutIsAdmin = $layoutUser?->isAdmin() ?? false;
    $navGroups = $layoutIsAdmin
        ? [
            'System' => [
                ['label' => 'Uebersicht', 'href' => route('dashboard'), 'target' => ''],
                ['label' => 'Server Settings', 'href' => route('dashboard').'#settings', 'target' => '#settings'],
                ['label' => 'API & Devices', 'href' => route('dashboard').'#api-keys', 'target' => '#api-keys'],
                ['label' => 'Modelle', 'href' => route('dashboard').'#models', 'target' => '#models'],
            ],
            'Operations' => [
                ['label' => 'Provider', 'href' => route('dashboard').'#providers', 'target' => '#providers'],
                ['label' => 'Archive', 'href' => route('dashboard').'#archives', 'target' => '#archives'],
            ],
        ]
        : [
            'Luczor Terminal' => [
                ['label' => 'Terminal', 'href' => route('dashboard'), 'target' => ''],
                ['label' => 'Meine Geraete', 'href' => route('dashboard').'#devices', 'target' => '#devices'],
                ['label' => 'Projekte', 'href' => route('dashboard').'#projects', 'target' => '#projects'],
                ['label' => 'GitHub / Cloud', 'href' => route('dashboard').'#github', 'target' => '#github'],
            ],
        ];
?>
<body class="min-h-screen bg-[#050b12] text-slate-100 antialiased">
    <div class="min-h-screen lg:pl-72">
        <aside class="fixed inset-y-0 left-0 z-30 hidden w-72 flex-col border-r border-cyan-400/10 bg-slate-950/95 shadow-2xl shadow-black/40 lg:flex">
            <div class="flex h-[70px] items-center gap-3 border-b border-cyan-400/10 px-6">
                <a class="flex items-center gap-3" href="<?php echo e(route('dashboard')); ?>">
                    <span class="flex h-9 w-9 items-center justify-center rounded-md border border-cyan-400/30 bg-cyan-400/10 font-mono text-sm font-bold text-cyan-200">LZ</span>
                    <span class="min-w-0">
                        <span class="block text-sm font-semibold uppercase tracking-[0.28em] text-white">Luczor</span>
                        <span class="block text-xs text-cyan-200/70"><?php echo e($layoutIsAdmin ? 'Admin Control' : 'Cloud Terminal'); ?></span>
                    </span>
                </a>
            </div>

            <nav class="flex-1 overflow-y-auto px-4 py-5">
                <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php $__currentLoopData = $navGroups; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $group => $items): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
                    <div class="mb-6">
                        <div class="mb-2 px-2 text-[11px] font-semibold uppercase tracking-[0.22em] text-slate-500"><?php echo e($group); ?></div>
                        <div class="space-y-1">
                            <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php $__currentLoopData = $items; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $item): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
                                <a href="<?php echo e($item['href']); ?>" class="flex items-center justify-between rounded-md border border-transparent px-3 py-2 text-sm text-slate-300 transition hover:border-cyan-400/20 hover:bg-cyan-400/10 hover:text-cyan-100">
                                    <span><?php echo e($item['label']); ?></span>
                                    <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($item['target']): ?>
                                        <span class="font-mono text-xs text-cyan-500">/</span>
                                    <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                                </a>
                            <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                        </div>
                    </div>
                <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
            </nav>

            <div class="border-t border-cyan-400/10 p-4">
                <div class="rounded-md border border-cyan-400/15 bg-cyan-400/5 p-3">
                    <div class="text-xs uppercase tracking-[0.18em] text-slate-500">Rolle</div>
                    <div class="mt-1 text-sm font-semibold text-cyan-100"><?php echo e($layoutIsAdmin ? 'Administrator' : 'User'); ?></div>
                </div>
            </div>
        </aside>

        <header class="sticky top-0 z-20 border-b border-cyan-400/10 bg-slate-950/85 backdrop-blur">
            <div class="flex min-h-[70px] items-center justify-between gap-4 px-4 sm:px-6 lg:px-8">
                <div>
                    <div class="text-xs uppercase tracking-[0.24em] text-cyan-300/70"><?php echo e($layoutIsAdmin ? 'Systemuebersicht' : 'Terminalzugriff'); ?></div>
                    <div class="mt-1 text-sm text-slate-400"><?php echo e(now()->format('d.m.Y H:i')); ?> · Europe/Berlin</div>
                </div>
                <nav class="flex items-center gap-3 text-sm text-slate-300">
                    <a class="hidden rounded-md border border-cyan-400/15 px-3 py-2 hover:bg-cyan-400/10 hover:text-cyan-100 sm:inline-flex" href="<?php echo e(route('profile.show')); ?>">
                        <?php echo e($layoutUser?->name ?? 'Profil'); ?>

                    </a>
                    <form method="POST" action="<?php echo e(route('logout')); ?>">
                        <?php echo csrf_field(); ?>
                        <button class="rounded-md border border-rose-400/20 px-3 py-2 text-rose-100 hover:bg-rose-400/10" type="submit">Logout</button>
                    </form>
                </nav>
            </div>
        </header>

        <main class="px-4 py-6 sm:px-6 lg:px-8">
            <?php echo e($slot ?? ''); ?>

            <?php echo $__env->yieldContent('content'); ?>
        </main>
    </div>
    <?php echo \Livewire\Mechanisms\FrontendAssets\FrontendAssets::scripts(); ?>

</body>
</html>
<?php /**PATH E:\projekte\luczor\admin_api_app\resources\views/layouts/app.blade.php ENDPATH**/ ?>