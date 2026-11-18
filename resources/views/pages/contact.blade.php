<x-layouts.public title="Contact Us">
    <x-page-header
        title="Get in touch"
        subtitle="Have a question? We'd love to hear from you. Send us a message and we'll respond as soon as we can."
        :breadcrumbs="['Contact' => null]"
    />

    <section class="container-app py-12">
        <div class="grid gap-10 lg:grid-cols-3">
            {{-- Contact info --}}
            <div class="space-y-4 lg:col-span-1">
                @php
                    // ⚠️ READ FROM SETTINGS, NOT HARDCODED (2026-09-26).
                    //
                    // The owner changed the address under Settings → General and this
                    // page went on showing "Colombo, Sri Lanka" — it had never read the
                    // setting at all, so there was nothing to go wrong: the three values
                    // were literals in the array below. Same for the email and the phone,
                    // which are equally editable and were equally ignored.
                    //
                    // The old literals stay as FALLBACKS so a blank setting shows
                    // something sensible rather than an empty row.
                    $contactEmail = \App\Models\Setting::get('contact_email') ?: 'hello@etec.lk';
                    $contactPhone = \App\Models\Setting::get('contact_phone') ?: '+94 11 234 5678';
                    $contactAddress = \App\Models\Setting::get('contact_address') ?: 'Colombo, Sri Lanka';
                    // A tel: link may only carry digits and a leading +.
                    $phoneHref = 'tel:'.preg_replace('/[^0-9+]/', '', $contactPhone);

                    // ⚠️ THE ADDRESS OPENS A MAP (2026-09-27). Its link was '#',
                    // so tapping the card just put a bare fragment in the address
                    // bar and sat there — "meka click krhama location eka map eken
                    // open widyt". The other two cards already dial and compose.
                    //
                    // A SEARCH url, not a pinned coordinate: the address is typed
                    // by hand in Settings and can be edited at any time, so there
                    // is no lat/long to trust. Maps resolves the text the same way
                    // a person typing it would, and this is the documented
                    // cross-platform form — it opens the app on a phone and the
                    // site on a desktop, on both Android and iOS.
                    $mapHref = 'https://www.google.com/maps/search/?api=1&query='.urlencode($contactAddress);
                @endphp

                @foreach ([
                    ['t' => 'Email us', 'v' => $contactEmail, 'i' => 'M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z', 'href' => 'mailto:'.$contactEmail],
                    ['t' => 'Call us', 'v' => $contactPhone, 'i' => 'M3 5a2 2 0 012-2h3.28a1 1 0 01.948.684l1.498 4.493a1 1 0 01-.502 1.21l-2.257 1.13a11.042 11.042 0 005.516 5.516l1.13-2.257a1 1 0 011.21-.502l4.493 1.498a1 1 0 01.684.949V19a2 2 0 01-2 2h-1C9.716 21 3 14.284 3 6V5z', 'href' => $phoneHref],
                    ['t' => 'Visit us', 'v' => $contactAddress, 'i' => 'M17.657 16.657L13.414 20.9a2 2 0 01-2.827 0l-4.244-4.243a8 8 0 1111.314 0zM15 11a3 3 0 11-6 0 3 3 0 016 0z', 'href' => $mapHref, 'away' => true],
                ] as $c)
                    {{-- Only the map leaves the site, so only it opens in a new tab.
                         mailto: and tel: hand off to another app and must NOT — a
                         blank tab would be left behind on the desktop. --}}
                    <a href="{{ $c['href'] }}" class="card flex items-center gap-4 p-5 transition hover:shadow-md"
                        @if ($c['away'] ?? false) target="_blank" rel="noopener noreferrer" @endif>
                        <span class="grid h-12 w-12 shrink-0 place-items-center rounded-xl bg-brand-50 text-brand-600 dark:bg-brand-500/10 dark:text-brand-400"><svg class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.7"><path stroke-linecap="round" stroke-linejoin="round" d="{{ $c['i'] }}"/></svg></span>
                        <div><div class="text-sm text-slate-500 dark:text-slate-400">{{ $c['t'] }}</div><div class="font-semibold text-slate-900 dark:text-white">{{ $c['v'] }}</div></div>
                    </a>
                @endforeach

                <div class="card p-5">
                    <h4 class="font-semibold text-slate-900 dark:text-white">Office hours</h4>
                    <dl class="mt-3 space-y-2 text-sm text-slate-500 dark:text-slate-400">
                        <div class="flex justify-between"><dt>Mon – Fri</dt><dd class="font-medium text-slate-700 dark:text-slate-300">9:00 – 18:00</dd></div>
                        <div class="flex justify-between"><dt>Saturday</dt><dd class="font-medium text-slate-700 dark:text-slate-300">9:00 – 13:00</dd></div>
                        <div class="flex justify-between"><dt>Sunday</dt><dd class="font-medium text-slate-700 dark:text-slate-300">Closed</dd></div>
                    </dl>
                </div>
            </div>

            {{-- Form --}}
            <div class="lg:col-span-2">
                <div class="card p-7 lg:p-8">
                    <h2 class="text-xl font-bold text-slate-900 dark:text-white">Send us a message</h2>
                    <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">Fill in the form below and our team will get back to you.</p>

                    <x-flash />

                    <form method="POST" action="{{ route('contact.store') }}" class="mt-6 grid gap-5 sm:grid-cols-2">
                        @csrf
                        <div>
                            <label for="name" class="label">Full name <span class="text-rose-500">*</span></label>
                            <input id="name" name="name" type="text" value="{{ old('name') }}" required class="input @error('name') border-rose-400 @enderror" placeholder="John Doe">
                            @error('name')<p class="mt-1 text-xs text-rose-500">{{ $message }}</p>@enderror
                        </div>
                        <div>
                            <label for="email" class="label">Email <span class="text-rose-500">*</span></label>
                            <input id="email" name="email" type="email" value="{{ old('email', request('email')) }}" required class="input @error('email') border-rose-400 @enderror" placeholder="you@email.com">
                            @error('email')<p class="mt-1 text-xs text-rose-500">{{ $message }}</p>@enderror
                        </div>
                        <div>
                            <label for="phone" class="label">Phone</label>
                            <input id="phone" name="phone" type="text" value="{{ old('phone') }}" class="input" placeholder="+94 ...">
                        </div>
                        <div>
                            <label for="subject" class="label">Subject</label>
                            <input id="subject" name="subject" type="text" value="{{ old('subject') }}" class="input" placeholder="How can we help?">
                        </div>
                        <div class="sm:col-span-2">
                            <label for="message" class="label">Message <span class="text-rose-500">*</span></label>
                            <textarea id="message" name="message" rows="5" required class="input @error('message') border-rose-400 @enderror" placeholder="Write your message...">{{ old('message') }}</textarea>
                            @error('message')<p class="mt-1 text-xs text-rose-500">{{ $message }}</p>@enderror
                        </div>
                        <div class="sm:col-span-2">
                            <button type="submit" class="btn-primary w-full py-3 sm:w-auto sm:px-10">
                                Send message
                                <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 19l9 2-9-18-9 18 9-2zm0 0v-8"/></svg>
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </section>

    {{-- The campus on a map (2026-11-02) — zoom in and out, or open it in Google Maps. --}}
    <section class="container-app pb-16">
        <h2 class="text-xl font-extrabold text-slate-900 dark:text-white">Find us</h2>
        <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">{{ $contactAddress }}</p>
        <div class="mt-4">
            @include('partials.location-map', ['height' => 420])
        </div>
    </section>
</x-layouts.public>
