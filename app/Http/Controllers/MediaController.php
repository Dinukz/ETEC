<?php

namespace App\Http\Controllers;

use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Serves specific public-disk files straight from storage/app/public via PHP
 * instead of the public/storage symlink — that symlink is missing/broken on
 * production (shared hosting, no CLI to recreate it reliably), which was
 * surfacing as avatars/receipts silently failing to display.
 */
class MediaController extends Controller
{
    /**
     * Only these prefixes are streamable. Deliberately an allowlist, not a
     * blocklist: `path` is a `.*` route param, so anything reachable here is
     * reachable by any visitor. Receipts and payment slips are NOT listed —
     * those stay behind the authenticated `payments.receipt` route.
     *
     * `courses/` and `blog/` added 2026-08-19: course thumbnails and blog
     * images were rendered with asset('storage/...'), which depends on the
     * public/storage symlink this class exists precisely because production
     * lacks. They are public marketing images on already-public pages, so
     * serving them unauthenticated matches where they are shown.
     */
    private const ALLOWED_PREFIXES = ['avatars/', 'consultants/account-proof/', 'courses/', 'blog/'];

    public function show(string $path): StreamedResponse
    {
        abort_unless(collect(self::ALLOWED_PREFIXES)->contains(fn ($prefix) => str_starts_with($path, $prefix)), 404);
        abort_unless(Storage::disk('public')->exists($path), 404);

        return Storage::disk('public')->response($path);
    }
}
