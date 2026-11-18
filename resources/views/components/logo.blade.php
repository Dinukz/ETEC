@props(['class' => 'h-11'])

<a href="{{ url('/') }}" {{ $attributes->merge(['class' => 'inline-flex items-center gap-2.5 font-extrabold tracking-tight']) }}>
    <span class="grid place-items-center rounded-xl bg-white p-1 shadow-md">
        <img src="{{ asset('images/etec-logo.jpeg') }}" alt="ETEC" class="{{ $class }} w-auto rounded-lg">
    </span>
</a>
