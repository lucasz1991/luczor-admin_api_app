<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="<?php echo e(csrf_token()); ?>"><title>Luczor Admin Control</title>
    <?php echo app('Illuminate\Foundation\Vite')(['resources/css/app.css', 'resources/js/app.js']); ?> <?php echo \Livewire\Mechanisms\FrontendAssets\FrontendAssets::styles(); ?>

</head>
<body class="min-h-screen bg-[#03070d] text-slate-100 antialiased">
<div class="min-h-screen lg:pl-72">
    <aside class="fixed inset-y-0 left-0 z-30 hidden w-72 flex-col border-r border-cyan-400/10 bg-slate-950/95 lg:flex">
        <a href="<?php echo e(route('dashboard')); ?>" class="flex h-[72px] items-center gap-3 border-b border-cyan-400/10 px-6">
            <span class="flex h-10 w-10 items-center justify-center rounded-lg border border-cyan-400/30 bg-cyan-400/10 font-mono font-bold text-cyan-200">LZ</span>
            <span><b class="block uppercase tracking-[.25em]">Luczor</b><small class="text-cyan-300/60">Admin Control Plane</small></span>
        </a>
        <nav class="flex-1 space-y-6 overflow-y-auto px-4 py-6 text-sm">
            <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php $__currentLoopData = ['System'=>['Übersicht'=>'#overview','Provider & Preise'=>'#providers','Modelle & Routing'=>'#models'],'Optimierung'=>['Telemetry & Kosten'=>'#telemetry','Prompt & Kontext'=>'#optimizer','Experimente'=>'#experiments'],'Operations'=>['Geräte & Keys'=>'#api-keys','Archive & Audit'=>'#archives','Server Settings'=>'#settings']]; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $group=>$items): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
                <div><div class="px-3 text-[10px] font-semibold uppercase tracking-[.22em] text-slate-600"><?php echo e($group); ?></div>
                    <div class="mt-2 space-y-1"><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php $__currentLoopData = $items; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $label=>$hash): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?><a href="<?php echo e(route('dashboard').$hash); ?>" class="block rounded-md px-3 py-2 text-slate-300 hover:bg-cyan-400/10 hover:text-cyan-100"><?php echo e($label); ?></a><?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?></div>
                </div>
            <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
        </nav>
        <div class="border-t border-cyan-400/10 p-4 text-xs text-slate-500">Administrator · Modellwahl serverseitig</div>
    </aside>
    <header class="sticky top-0 z-20 flex min-h-[72px] items-center justify-between border-b border-cyan-400/10 bg-slate-950/85 px-5 backdrop-blur lg:px-8">
        <div><div class="text-xs uppercase tracking-[.24em] text-cyan-300/70">Systemsteuerung</div><div class="text-sm text-slate-500">Provider · Routing · Kosten · Qualität</div></div>
        <div class="flex items-center gap-3 text-sm"><a href="<?php echo e(route('profile.show')); ?>" class="text-slate-300"><?php echo e(auth()->user()->name); ?></a><form method="POST" action="<?php echo e(route('logout')); ?>"><?php echo csrf_field(); ?><button class="rounded border border-rose-400/20 px-3 py-2 text-rose-200">Logout</button></form></div>
    </header>
    <main class="px-4 py-7 sm:px-6 lg:px-8"><?php echo e($slot ?? ''); ?> <?php echo $__env->yieldContent('content'); ?></main>
</div><?php echo \Livewire\Mechanisms\FrontendAssets\FrontendAssets::scripts(); ?>

</body></html>
<?php /**PATH E:\projekte\luczor\admin_api_app\resources\views/layouts/admin.blade.php ENDPATH**/ ?>