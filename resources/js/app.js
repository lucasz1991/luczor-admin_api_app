import { Livewire, Alpine } from '../../vendor/livewire/livewire/dist/livewire.esm';
import focus from '@alpinejs/focus';
import { registerLuczorShell } from './app-shell';
import { registerMemoryNetworks } from './memory-network-3d';

window.Alpine = Alpine;
Alpine.plugin(focus);
registerLuczorShell(Alpine);
registerMemoryNetworks();
Livewire.start();
