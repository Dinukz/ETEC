import './bootstrap';

// ⚠️ ALPINE COMES FROM LIVEWIRE — DO NOT ALSO IMPORT alpinejs HERE (2026-08-23).
//
// This file used to `import Alpine from 'alpinejs'` and call `Alpine.start()`.
// Livewire ships its own copy of Alpine inside its script, so once Livewire was
// added the page had two instances: Alpine warns that it has already been
// initialised, and every x-data component is processed twice — one click
// toggles a dropdown open and immediately shut again. Most of this dashboard is
// Alpine, so that breaks far more than the one page Livewire was added for.
//
// Importing Livewire's ESM build instead gives us a single Alpine that both
// systems share. Our plugins are registered on it below, before start().
//
// This pairs with `inject_assets => false` in config/livewire.php and the
// @livewireScriptConfig directive in every layout — bundling it here means
// Livewire no longer injects its own <script>, so without that directive it
// would not know its update endpoint and every wire:click would silently fail.
import { Livewire, Alpine } from '../../vendor/livewire/livewire/dist/livewire.esm';
import collapse from '@alpinejs/collapse';
import focus from '@alpinejs/focus';

Alpine.plugin(collapse);
Alpine.plugin(focus);

// Kept for any inline code that still reaches for the global.
window.Alpine = Alpine;

// Starts Alpine too — never call Alpine.start() as well, that is the double
// initialisation described above.
Livewire.start();
