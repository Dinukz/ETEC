@props([
    'name' => 'password',
    'id' => null,
    'label' => 'Password',
    'placeholder' => null,
    'required' => false,
    'disabled' => false,
])

@php $inputId = $id ?? $name; @endphp

{{-- A password field with a show/hide toggle. Alpine swaps the input's `type`
     rather than rendering two fields, so the value, name and validation state
     survive toggling and the form posts exactly one value. --}}
<div x-data="{ show: false }">
    <label for="{{ $inputId }}" class="label">{{ $label }}</label>

    <div class="relative">
        <input
            :type="show ? 'text' : 'password'"
            type="password"
            id="{{ $inputId }}"
            name="{{ $name }}"
            @if ($placeholder) placeholder="{{ $placeholder }}" @endif
            @required($required)
            @disabled($disabled)
            {{ $attributes->merge(['class' => 'input pr-11']) }}
        >

        {{-- tabindex -1 so tabbing runs field → field, not field → eye → field. --}}
        <button
            type="button"
            x-on:click="show = !show"
            tabindex="-1"
            @disabled($disabled)
            class="absolute inset-y-0 right-0 flex items-center px-3 text-slate-400 transition hover:text-slate-600 disabled:cursor-not-allowed disabled:opacity-50 dark:hover:text-slate-200"
            x-bind:aria-label="show ? 'Hide password' : 'Show password'"
            aria-label="Show password"
        >
            {{-- Open eye — shown while the password is hidden. --}}
            <svg x-show="! show" class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8">
                <path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/>
                <path stroke-linecap="round" stroke-linejoin="round" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"/>
            </svg>

            {{-- Struck-through eye — shown while the password is visible. --}}
            <svg x-show="show" x-cloak class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8">
                <path stroke-linecap="round" stroke-linejoin="round" d="M13.875 18.825A10.05 10.05 0 0112 19c-4.478 0-8.268-2.943-9.543-7a9.97 9.97 0 011.563-3.029m5.858.908a3 3 0 114.243 4.243M9.878 9.878l4.242 4.242M9.88 9.88l-3.29-3.29m7.532 7.532l3.29 3.29M3 3l3.59 3.59m0 0A9.953 9.953 0 0112 5c4.51 0 8.268 2.943 9.543 7a10.025 10.025 0 01-4.132 5.411m0 0L21 21"/>
            </svg>
        </button>
    </div>
</div>
