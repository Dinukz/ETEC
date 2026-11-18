<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="csrf-token" content="{{ csrf_token() }}">
{{-- ⚠️ THE SITE ICON (2026-10-02). Google showed a grey globe beside
     etecbusiness.lk because this pointed at the 512×512 logo JPEG — Google only
     uses an icon whose size is a multiple of 48 px — and its fallback,
     /favicon.ico, was an empty 0-byte file. These are made from the shield
     alone (no lettering, which is unreadable at 16 px): a real .ico holding
     16/32/48, PNGs at 48/96/192, and the iPhone home-screen icon. --}}
<link rel="icon" href="{{ asset('favicon.ico') }}" sizes="48x48">
<link rel="icon" href="{{ asset('favicon-192x192.png') }}" type="image/png" sizes="192x192">
<link rel="icon" href="{{ asset('favicon-96x96.png') }}" type="image/png" sizes="96x96">
<link rel="apple-touch-icon" href="{{ asset('apple-touch-icon.png') }}">

<title>{{ ($title ?? false) ? $title . ' — ' . config('app.name') : config('app.name') . ' — Learn. Earn. Grow.' }}</title>
<meta name="description" content="{{ $metaDescription ?? 'ETEC is a modern learning platform offering professional courses, certifications, and a rewarding referral program.' }}">

{{-- ⚠️ SEARCH ENGINES (2026-09-30). The site lived at /public/ and the bare
     domain answered 403, so Google had no homepage to index — searching
     "etecbusiness" found nothing. The root .htaccess moves everything to the
     clean address; these tell Google which address is THE page (canonical),
     what to show when a link is shared, and — on the homepage — that this site
     is ETEC Business College, which is what a search for the brand is matched
     against. --}}
<link rel="canonical" href="{{ url()->current() }}">
<meta property="og:site_name" content="ETEC Business College">
<meta property="og:type" content="website">
<meta property="og:url" content="{{ url()->current() }}">
<meta property="og:title" content="{{ ($title ?? false) ? $title . ' — ETEC Business College' : 'ETEC Business College — Learn. Earn. Grow.' }}">
<meta property="og:description" content="{{ $metaDescription ?? 'ETEC is a modern learning platform offering professional courses, certifications, and a rewarding referral program.' }}">
<meta property="og:image" content="{{ asset('images/etec-logo.jpeg') }}">

@if (request()->routeIs('home'))
    @php
        $orgAddress = \App\Models\Setting::get('contact_address');
        $orgPhone = \App\Models\Setting::get('contact_phone');
        $orgEmail = \App\Models\Setting::get('contact_email');

        // WebSite gives Google the name to show for the site in results;
        // EducationalOrganization ties the brand, the logo and the contact
        // details to it. The names are the ones on the logo and the domain.
        $structured = [
            [
                '@context' => 'https://schema.org',
                '@type' => 'WebSite',
                'name' => 'ETEC Business College',
                'alternateName' => ['E-TEC Business College', 'ETEC', 'etecbusiness'],
                'url' => route('home'),
            ],
            array_filter([
                '@context' => 'https://schema.org',
                '@type' => 'EducationalOrganization',
                'name' => 'ETEC Business College',
                'alternateName' => ['E-TEC Business College', 'ETEC', 'etecbusiness'],
                'url' => route('home'),
                'logo' => asset('images/etec-logo.jpeg'),
                'email' => $orgEmail ?: null,
                'telephone' => $orgPhone ?: null,
                'address' => $orgAddress ? [
                    '@type' => 'PostalAddress',
                    'streetAddress' => $orgAddress,
                    'addressCountry' => 'LK',
                ] : null,
            ]),
        ];
    @endphp
    {{-- JSON_HEX_TAG kept: passing flags drops @json's default escaping, and the
         address is admin-typed text that must never be able to close this tag. --}}
    <script type="application/ld+json">@json($structured, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG)</script>
@endif

<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">

{{-- Prevent dark-mode flash: apply the stored theme before first paint --}}
<script>
    (function () {
        try {
            var t = localStorage.getItem('theme');
            var systemDark = window.matchMedia('(prefers-color-scheme: dark)').matches;
            if (t === 'dark' || (!t && systemDark)) {
                document.documentElement.classList.add('dark');
            }
        } catch (e) {}
    })();
</script>

@vite(['resources/css/app.css', 'resources/js/app.js'])
