<x-layouts.public title="FAQ">
    <x-page-header
        title="Frequently Asked Questions"
        subtitle="Find answers to common questions about courses, payments, and our referral program."
        :breadcrumbs="['FAQ' => null]"
    />

    <section class="container-app py-12">
        <div class="mx-auto max-w-3xl">
            @forelse ($faqs as $category => $items)
                <div class="mb-10">
                    @if ($category)
                        <h2 class="mb-4 text-lg font-bold text-slate-900 dark:text-white">{{ $category }}</h2>
                    @endif
                    <div class="space-y-3" x-data="{ open: null }">
                        @foreach ($items as $faq)
                            <div class="card overflow-hidden">
                                <button @click="open = open === {{ $faq->id }} ? null : {{ $faq->id }}" class="flex w-full items-center justify-between gap-4 p-5 text-left">
                                    <span class="font-semibold text-slate-900 dark:text-white">{{ $faq->question }}</span>
                                    <svg class="h-5 w-5 shrink-0 text-slate-400 transition-transform" :class="open === {{ $faq->id }} && 'rotate-180'" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M19 9l-7 7-7-7"/></svg>
                                </button>
                                <div x-show="open === {{ $faq->id }}" x-collapse><p class="px-5 pb-5 text-sm leading-relaxed text-slate-500 dark:text-slate-400">{{ $faq->answer }}</p></div>
                            </div>
                        @endforeach
                    </div>
                </div>
            @empty
                <div class="card p-16 text-center">
                    <h3 class="text-lg font-bold text-slate-900 dark:text-white">No FAQs yet</h3>
                    <p class="mt-2 text-sm text-slate-500 dark:text-slate-400">Check back soon or contact us directly.</p>
                </div>
            @endforelse

            {{-- Still have questions --}}
            <div class="card mt-8 flex flex-col items-center gap-4 bg-gradient-to-br from-brand-50 to-white p-8 text-center dark:from-brand-500/10 dark:to-slate-900 sm:flex-row sm:text-left">
                <span class="grid h-14 w-14 shrink-0 place-items-center rounded-2xl bg-brand-600 text-white"><svg class="h-7 w-7" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.7"><path stroke-linecap="round" stroke-linejoin="round" d="M8.228 9c.549-1.165 2.03-2 3.772-2 2.21 0 4 1.343 4 3 0 1.4-1.278 2.575-3.006 2.907-.542.104-.994.54-.994 1.093m0 3h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg></span>
                <div class="flex-1">
                    <h3 class="text-lg font-bold text-slate-900 dark:text-white">Still have questions?</h3>
                    <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">Our team is here to help you with anything you need.</p>
                </div>
                <a href="{{ route('contact') }}" class="btn-primary">Contact us</a>
            </div>
        </div>
    </section>
</x-layouts.public>
