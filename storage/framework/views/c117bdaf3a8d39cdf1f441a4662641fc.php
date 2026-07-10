<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="<?php echo e(csrf_token()); ?>"><title>Luczor Cloud</title>
    <?php echo app('Illuminate\Foundation\Vite')(['resources/css/app.css', 'resources/js/app.js']); ?> <?php echo \Livewire\Mechanisms\FrontendAssets\FrontendAssets::styles(); ?>

</head>
<body class="min-h-screen bg-[#050b12] text-slate-100 antialiased">
<div class="min-h-screen lg:pl-64">
    <aside class="fixed inset-y-0 left-0 z-30 hidden w-64 flex-col border-r border-cyan-400/10 bg-slate-950/95 lg:flex">
        <a href="<?php echo e(route('dashboard')); ?>" class="flex h-[72px] items-center gap-3 border-b border-cyan-400/10 px-5"><span class="flex h-9 w-9 items-center justify-center rounded-full border border-cyan-300/30 bg-cyan-300/10 font-mono text-cyan-100">LZ</span><span><b class="block tracking-[.2em]">LUCZOR</b><small class="text-slate-500">Cloud Terminal</small></span></a>
        <nav class="flex-1 space-y-1 px-4 py-6 text-sm">
            <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php $__currentLoopData = ['Terminal'=>'','Meine Geräte'=>'#devices','Meine Projekte'=>'#projects','GitHub / Cloud'=>'#github','Verbindung erstellen'=>'#connect']; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $label=>$hash): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?><a href="<?php echo e(route('dashboard').$hash); ?>" class="block rounded-md px-3 py-2.5 text-slate-300 hover:bg-cyan-400/10 hover:text-cyan-100"><?php echo e($label); ?></a><?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
        </nav>
        <div class="border-t border-cyan-400/10 p-4 text-xs text-slate-500">Modelle und Provider werden sicher vom Luczor-Server verwaltet.</div>
    </aside>
    <header class="sticky top-0 z-20 flex min-h-[72px] items-center justify-between border-b border-cyan-400/10 bg-slate-950/85 px-5 backdrop-blur lg:px-8">
        <div><div class="text-xs uppercase tracking-[.24em] text-cyan-300/70">Mein Luczor</div><div class="text-sm text-slate-500">Geräte · Projekte · Memory · Sync</div></div>
        <div class="flex items-center gap-3 text-sm"><a href="<?php echo e(route('profile.show')); ?>" class="text-slate-300"><?php echo e(auth()->user()->name); ?></a><form method="POST" action="<?php echo e(route('logout')); ?>"><?php echo csrf_field(); ?><button class="rounded border border-slate-700 px-3 py-2">Logout</button></form></div>
    </header>
    <main class="px-4 py-7 sm:px-6 lg:px-8"><?php echo e($slot ?? ''); ?> <?php echo $__env->yieldContent('content'); ?></main>
</div><?php echo \Livewire\Mechanisms\FrontendAssets\FrontendAssets::scripts(); ?>

</body></html>
<?php /**PATH E:\projekte\luczor\admin_api_app\resources\views/layouts/customer.blade.php ENDPATH**/ ?>