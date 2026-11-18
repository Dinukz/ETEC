{{--
    A photo on the public site, with a graceful fallback when the file is not
    there yet.

    ⚠️ WHY THE FALLBACK EXISTS. The owner supplied the two site photos as chat
    attachments, which cannot be written to disk from here — so the markup ships
    referencing a path, and the files are dropped in afterwards. Without this a
    missing file renders as a broken-image icon across the homepage, which looks
    far worse than no photo at all.

    So: if the file is present it is shown; if not, a themed gradient stands in
    at the same size and the layout does not move. Drop the file in and it
    appears — no code change.

    Paths expected (both under public/images/):
      hero-students.jpg   the celebrating students — page header
      about-students.jpg  the students at laptops  — the section below it

    `file_exists` is a filesystem stat on a local path, not a network call, and
    only runs on public marketing pages.

    ⚠️ ANY PHOTO ENDING (2026-11-02). The owner uploaded the About photos as
    .jpeg while the page asked for .jpg, and they never showed. So the same
    name with .jpg / .jpeg / .png / .webp (any case) is accepted — only the
    FOLDER must be right: public/images/..., beside etec-logo.jpeg.
--}}
@props([
    'path',
    'alt' => '',
    'class' => '',
    'eager' => false,
])

@php
    $present = false;
    if (is_string($path) && $path !== '') {
        $stem = preg_replace('/\.[A-Za-z0-9]+$/', '', $path);
        foreach (array_unique([$path, $stem.'.jpg', $stem.'.jpeg', $stem.'.png', $stem.'.webp', $stem.'.JPG', $stem.'.JPEG', $stem.'.PNG']) as $candidate) {
            if (@file_exists(public_path($candidate))) {
                $path = $candidate;
                $present = true;
                break;
            }
        }
    }
@endphp

@if ($present)
    <img
        src="{{ asset($path) }}"
        alt="{{ $alt }}"
        @if ($eager) fetchpriority="high" @else loading="lazy" @endif
        class="{{ $class }} h-full w-full object-cover"
    >
@else
    {{-- Same box, no broken icon. Deliberately not a "missing image" message:
         a visitor should never be shown the site's own build state. --}}
    <div
        role="img"
        aria-label="{{ $alt }}"
        class="{{ $class }} h-full w-full bg-gradient-to-br from-brand-500 via-brand-600 to-slate-900 dark:from-brand-600 dark:via-slate-900 dark:to-black"
    ></div>
@endif
