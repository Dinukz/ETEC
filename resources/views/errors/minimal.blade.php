<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>@yield('code') — {{ config('app.name', 'ETEC') }}</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;600;700;800&display=swap" rel="stylesheet">
    @vite(['resources/css/app.css'])
    <script>(function(){try{var t=localStorage.getItem('theme');if(t==='dark'||(!t&&window.matchMedia('(prefers-color-scheme: dark)').matches))document.documentElement.classList.add('dark');}catch(e){}})();</script>
</head>
<body class="grid min-h-screen place-items-center bg-slate-50 px-4 dark:bg-slate-950">
    <div class="w-full max-w-md text-center">
        <div class="relative mx-auto mb-8 w-fit">
            <span class="bg-gradient-to-br from-brand-500 to-brand-700 bg-clip-text text-[7rem] font-extrabold leading-none text-transparent">@yield('code')</span>
        </div>
        <h1 class="text-2xl font-extrabold text-slate-900 dark:text-white">@yield('title')</h1>
        <p class="mt-3 text-slate-500 dark:text-slate-400">@yield('message')</p>
        <div class="mt-8 flex flex-col justify-center gap-3 sm:flex-row">
            <a href="{{ url('/') }}" class="btn-primary px-6 py-3">Back to home</a>
            <a href="{{ url()->previous() }}" class="btn-secondary px-6 py-3">Go back</a>
        </div>
    </div>
</body>
</html>
