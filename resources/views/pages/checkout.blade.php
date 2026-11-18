<x-layouts.public title="Enroll — {{ $course->title }}">
    <section class="bg-slate-50 py-16 dark:bg-slate-950 lg:py-20">
        <div class="mx-auto max-w-4xl px-4 sm:px-6">
            <h1 class="text-3xl font-extrabold text-slate-900 dark:text-white">Complete your enrollment</h1>
            <p class="mt-2 text-slate-500 dark:text-slate-400">Submit your payment details — our accounts team verifies every payment manually, then your course unlocks.</p>

            <div class="mt-8 grid gap-6 lg:grid-cols-5">
                <div class="lg:col-span-3">
                    <div class="card p-6 lg:p-8">
                        @if ($errors->any())
                            <div class="mb-6 rounded-xl bg-rose-50 px-4 py-3 text-sm text-rose-700 ring-1 ring-rose-200 dark:bg-rose-500/10 dark:text-rose-300 dark:ring-rose-500/30">
                                <ul class="list-inside list-disc space-y-1">@foreach ($errors->all() as $e)<li>{{ $e }}</li>@endforeach</ul>
                            </div>
                        @endif

                        @if ($existing && $existing->status === 'pending')
                            <div class="mb-6 rounded-xl bg-amber-50 px-4 py-3 text-sm text-amber-800 ring-1 ring-amber-200 dark:bg-amber-500/10 dark:text-amber-200 dark:ring-amber-500/30">
                                You already have a pending enrollment for this course. Submitting again updates your payment details.
                            </div>
                        @endif

                        <form method="POST" action="{{ route('checkout.store', $course) }}" enctype="multipart/form-data" class="space-y-5"
                            {{-- The plan lives on the FORM, not on the plan picker, because
                                 the reference and receipt fields below have to react to it and
                                 they sit outside that picker. Defaults to full for buyers who
                                 never see the picker at all. --}}
                            x-data="{ plan: '{{ old('payment_type', 'full') }}' }">
                            @csrf

                            {{-- Consultant placement (2026-08-19).
                                 The buyer chooses which existing SCN account and which of its
                                 two Service Groups they sit under. Optional, so an ordinary
                                 ?ref= purchase and every existing link keep working unchanged.
                                 Filtering is client-side over the SAME list the server
                                 authorised and re-checks on submit, so it can never reach a
                                 suspended account or the buyer's own. --}}
                            @if ($placementAccounts->isNotEmpty())
                                <div x-data="{
                                        open: false,
                                        search: '',
                                        chosen: @js(old('placement_consultant_id')),
                                        chosenLabel: @js(collect($placementAccounts)->firstWhere('id', (int) old('placement_consultant_id'))['label'] ?? ''),
                                        options: @js($placementAccounts),
                                        get filtered() {
                                            if (! this.search.trim()) return this.options;
                                            const q = this.search.trim().toLowerCase();
                                            return this.options.filter(o => o.label.toLowerCase().includes(q));
                                        },
                                        pick(option) {
                                            this.chosen = option.id;
                                            this.chosenLabel = option.label;
                                            this.open = false;
                                        },
                                        clear() { this.chosen = null; this.chosenLabel = ''; this.search = ''; },
                                    }" class="rounded-2xl border border-slate-200 p-4 dark:border-slate-700">
                                    {{-- ⚠️ REQUIRED WHENEVER THIS BLOCK RENDERS (2026-08-27).
                                         It renders only when the buyer HAS accounts to place
                                         under, and for them the purchase opens a new set of
                                         three — so this field decides where those accounts
                                         live, and with them where every future student's
                                         2,500 calculates. Left blank it used to fall back to
                                         the buyer's main, which belongs to neither Service
                                         Group; the set was then stranded and the account they
                                         thought they bought under earned nothing. --}}
                                    <label for="placement-search" class="label">
                                        Consultant placement <span class="font-normal text-rose-500">(required)</span>
                                    </label>
                                    <p class="mb-2 text-xs text-slate-500 dark:text-slate-400">
                                        Choose which of your SCN accounts this course is bought under. The new accounts are opened there, so this is what decides where the income calculates.
                                    </p>

                                    <input type="hidden" name="placement_consultant_id" :value="chosen">

                                    <div class="relative">
                                        <input id="placement-search" type="text" autocomplete="off"
                                            x-model="search"
                                            @focus="open = true" @click="open = true" @keydown.escape="open = false"
                                            :placeholder="chosenLabel || 'Search an SCN number or name…'"
                                            class="input">

                                        <div x-show="open" x-cloak @click.outside="open = false"
                                            class="absolute left-0 right-0 z-30 mt-1 max-h-60 overflow-y-auto rounded-xl border border-slate-200 bg-white shadow-lg dark:border-slate-700 dark:bg-slate-900">
                                            <template x-for="option in filtered" :key="option.id">
                                                <button type="button" @click="pick(option)"
                                                    class="block w-full border-b border-slate-100 px-4 py-2.5 text-left text-sm text-slate-700 last:border-b-0 hover:bg-brand-50 dark:border-slate-800 dark:text-slate-200 dark:hover:bg-brand-500/10"
                                                    x-text="option.label"></button>
                                            </template>
                                            <p x-show="filtered.length === 0" class="px-4 py-3 text-sm text-slate-400">Nothing matches that search.</p>
                                        </div>
                                    </div>

                                    <div x-show="chosen" x-cloak class="mt-3">
                                        <p class="text-xs text-slate-500 dark:text-slate-400">
                                            Placing under <span class="font-semibold text-slate-700 dark:text-slate-200" x-text="chosenLabel"></span>
                                            <button type="button" @click="clear()" class="ml-1 underline">change</button>
                                        </p>

                                        {{-- Required once an account is picked: without it the leg
                                             falls back to fill order, and whatever lands on the
                                             unmatched side is forfeited to the company at a flush. --}}
                                        <label for="placement_tier" class="label mt-3">Service Group</label>
                                        <select id="placement_tier" name="placement_tier" class="input">
                                            <option value="">Select a Service Group</option>
                                            <option value="1" @selected(old('placement_tier') === '1')>Service Group 1</option>
                                            <option value="2" @selected(old('placement_tier') === '2')>Service Group 2</option>
                                        </select>
                                    </div>

                                    @error('placement_consultant_id')
                                        <p class="mt-2 text-xs font-medium text-rose-500">{{ $message }}</p>
                                    @enderror
                                    @error('placement_tier')
                                        <p class="mt-2 text-xs font-medium text-rose-500">{{ $message }}</p>
                                    @enderror
                                </div>
                            @endif

                            {{-- ⚠️ FREE CARD — CONSULTANTS ONLY (2026-09-07).

                                 Hidden entirely for anyone else, and refused
                                 again server-side in CheckoutController::store()
                                 — a form that merely omits an option is not a
                                 rule. Asked for by the owner: "course buy kran
                                 thanat free card option ekak dala denn".

                                 It is a payment PLAN, not a payment METHOD: it
                                 sets payment_type = 'free_card', which
                                 EnrollmentService::amountForType() prices at 0,
                                 and an amount of 0 is what stops the 2,500 ever
                                 reaching the upline. `method` is left alone
                                 because payments.method is a raw MySQL ENUM. --}}
                            @if ($canIssueFreeCard ?? false)
                                <div>
                                    <label for="payment_type" class="label">Payment plan</label>
                                    <select id="payment_type" name="payment_type" x-model="plan" class="input">
                                        <option value="full">Full payment</option>
                                        <option value="free_card">Free card (no payment)</option>
                                    </select>
                                    @error('payment_type')
                                        <p class="mt-2 text-xs font-medium text-rose-500">{{ $message }}</p>
                                    @enderror
                                    <template x-if="plan === 'free_card'">
                                        <div class="mt-3 rounded-xl border border-amber-200 bg-amber-50 p-3 text-xs text-amber-800 dark:border-amber-500/30 dark:bg-amber-500/10 dark:text-amber-200">
                                            <p class="font-semibold">Nothing is collected for this enrolment.</p>
                                            <p class="mt-1">It still goes to the office for verification before it counts.</p>
                                            {{-- Existing, deliberate behaviour — PaymentService::openAccountSetForBuyer()
                                                 bails on a zero amount ("a free card must not mint three SCN accounts for
                                                 nothing"). Said out loud here, or the missing accounts read as a bug. --}}
                                            <p class="mt-1">A free card does not open a new set of SCN accounts.</p>
                                        </div>
                                    </template>
                                </div>
                            @endif

                            <div>
                                <label for="method" class="label">Payment method</label>
                                <select id="method" name="method" required class="input">
                                    <option value="bank_transfer" @selected(old('method') === 'bank_transfer')>Bank transfer</option>
                                    <option value="online" @selected(old('method') === 'online')>Online payment</option>
                                    <option value="cash" @selected(old('method') === 'cash')>Cash (paid at office)</option>
                                </select>
                            </div>
                            <div>
                                <label for="reference_number" class="label">
                                    Payment reference number
                                    <span class="font-normal text-slate-400" x-show="plan !== 'free_card'">(from your slip/receipt)</span>
                                    <span class="font-normal text-slate-400" x-show="plan === 'free_card'" x-cloak>(not needed for a free card)</span>
                                </label>
                                <input id="reference_number" name="reference_number" type="text" value="{{ old('reference_number') }}" class="input"
                                    :required="plan !== 'free_card'" placeholder="e.g. TXN-2026-000123">
                                @error('reference_number')
                                    <p class="mt-2 text-xs font-medium text-rose-500">{{ $message }}</p>
                                @enderror
                            </div>
                            <div>
                                <label for="receipt" class="label">
                                    Upload receipt
                                    <span class="font-normal text-slate-400" x-show="plan !== 'free_card'">(PDF or photo, max 10MB)</span>
                                    <span class="font-normal text-slate-400" x-show="plan === 'free_card'" x-cloak>(not needed for a free card)</span>
                                </label>
                                <input id="receipt" name="receipt" type="file" accept=".pdf,.png,.jpg,.jpeg" class="input"
                                    :required="plan !== 'free_card'">
                                @error('receipt')
                                    <p class="mt-2 text-xs font-medium text-rose-500">{{ $message }}</p>
                                @enderror
                            </div>
                            <div>
                                <label for="notes" class="label">Notes <span class="font-normal text-slate-400">(optional)</span></label>
                                <textarea id="notes" name="notes" rows="3" class="input">{{ old('notes') }}</textarea>
                            </div>
                            <button type="submit" class="btn-primary w-full py-3">Submit for Verification</button>
                        </form>
                    </div>
                </div>

                <div class="lg:col-span-2">
                    <div class="card p-6">
                        <h3 class="font-bold text-slate-900 dark:text-white">Order summary</h3>
                        <div class="mt-4 flex items-start justify-between gap-3 border-b border-slate-100 pb-4 dark:border-slate-800">
                            <span class="text-sm text-slate-600 dark:text-slate-300">{{ $course->title }}</span>
                            <span class="shrink-0 font-bold text-slate-900 dark:text-white">LKR {{ number_format($course->effective_price) }}</span>
                        </div>
                        <div class="mt-4 flex justify-between text-sm">
                            <span class="text-slate-400">Total due</span>
                            <span class="text-lg font-extrabold text-brand-600 dark:text-brand-400">LKR {{ number_format($course->effective_price) }}</span>
                        </div>
                        @if ($referralConsultant)
                            <div class="mt-4 rounded-xl bg-brand-50 p-3 text-xs text-brand-800 dark:bg-brand-500/10 dark:text-brand-200">
                                Referred by consultant <strong>{{ $referralConsultant->user->name ?? $referralConsultant->consultant_code }}</strong>
                            </div>
                        @endif
                        @php $bank = \App\Models\Setting::whereIn('key', ['bank_name', 'bank_account_name', 'bank_account_number', 'bank_branch'])->pluck('value', 'key'); @endphp
                        @if ($bank->isNotEmpty())
                            <div class="mt-4 rounded-xl border border-slate-200 p-4 text-sm dark:border-slate-800">
                                <h4 class="font-bold text-slate-900 dark:text-white">Bank details</h4>
                                <dl class="mt-2 space-y-1 text-slate-600 dark:text-slate-300">
                                    @if ($bank->get('bank_name'))<div class="flex justify-between"><dt class="text-slate-400">Bank</dt><dd>{{ $bank['bank_name'] }}</dd></div>@endif
                                    @if ($bank->get('bank_account_name'))<div class="flex justify-between"><dt class="text-slate-400">Name</dt><dd>{{ $bank['bank_account_name'] }}</dd></div>@endif
                                    @if ($bank->get('bank_account_number'))<div class="flex justify-between"><dt class="text-slate-400">Account</dt><dd class="font-mono">{{ $bank['bank_account_number'] }}</dd></div>@endif
                                    @if ($bank->get('bank_branch'))<div class="flex justify-between"><dt class="text-slate-400">Branch</dt><dd>{{ $bank['bank_branch'] }}</dd></div>@endif
                                </dl>
                            </div>
                        @endif
                    </div>
                </div>
            </div>
        </div>
    </section>
</x-layouts.public>
