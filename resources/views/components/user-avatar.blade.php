{{--
    A user's profile photo, falling back to the initial of their name.

    ⚠️ ONE COMPONENT, BECAUSE THERE WERE NINE COPIES (2026-09-02).

    Every screen drew its own initial circle inline, so a photo uploaded on the
    profile page appeared precisely nowhere else — an admin looking at User
    Management still saw "M". Reported as: "meke profile photo eka anith account
    walatath dakinn puluwn wenn one, example gaththoth adminta penwann one."

    Fixing that in nine places would have meant nine chances to drift. Anywhere a
    person is shown, use this.

    ⚠️ SIZES ARE PASSED AS WHOLE TAILWIND CLASSES, never interpolated fragments.
    Tailwind scans source text for complete class names, so `h-{{ $n }}` compiles
    to nothing and the avatar silently renders at zero height.

    `avatar` is a plain column on `users`, so this triggers no lazy load — but
    the USER itself must already be loaded by the caller (preventLazyLoading is
    on outside production).

    Photos are served by MediaController, not the public/storage symlink, which
    is missing on this host. That route is deliberately unauthenticated for the
    `avatars/` prefix — an avatar is shown to anyone who can see the person.
--}}
@props([
    'user' => null,
    'size' => 'h-10 w-10',
    'text' => 'text-sm',
    // Only the fallback initial is tinted, and only one screen differs (recent
    // enrolments use sky). Kept as a prop rather than flattened to brand so
    // adopting this component changes no page's colours.
    'gradient' => 'from-brand-400 to-brand-600',
])

@php
    $displayName = $user?->name ?: '?';
    $photo = $user?->avatar ? route('media.show', $user->avatar) : null;
@endphp

@if ($photo)
    <img src="{{ $photo }}" alt="{{ $displayName }}" loading="lazy"
        class="{{ $size }} shrink-0 rounded-full bg-slate-100 object-cover dark:bg-slate-800">
@else
    <span class="{{ $size }} {{ $text }} {{ $gradient }} grid shrink-0 place-items-center rounded-full bg-gradient-to-br font-bold text-white">{{ strtoupper(substr($displayName, 0, 1)) }}</span>
@endif
