import { Livewire, Alpine } from '../../vendor/livewire/livewire/dist/livewire.esm';
import focus from '@alpinejs/focus';
import { registerLuczorShell } from './app-shell';

window.Alpine = Alpine;
Alpine.plugin(focus);
registerLuczorShell(Alpine);
Livewire.start();
