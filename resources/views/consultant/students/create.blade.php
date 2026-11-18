<x-layouts.app title="Student Registration" header="Student Registration">
    @if (session('student_created'))
        @include('consultant.students._created-modal', ['created' => session('student_created')])
    @endif

    <div class="mb-6">
        <a href="{{ route('consultant.students.index') }}" class="inline-flex items-center gap-1.5 text-sm font-medium text-slate-500 hover:text-brand-600 dark:text-slate-400 dark:hover:text-brand-400">
            <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M15 19l-7-7 7-7"/></svg>
            Back to direct registrations
        </a>
    </div>

    <div class="mx-auto max-w-4xl">
        <div class="card p-6 lg:p-8" x-data="{
            nicOwnedByStudent: true,
            selectedCourse: '{{ old('course_id', '') }}',
            language: '{{ old('language', '') }}',
            classType: '{{ old('class_type', '') }}',
            courseType: '{{ old('course_type', '') }}',
            courseDefaults: @js($courseDefaults),
            placementTier: '{{ old('placement_tier', '1') }}',
            requestedBy: {{ (int) old('requested_by_consultant_id', $consultant->id) }},
            partner: {{ old('partner_consultant_id') ? (int) old('partner_consultant_id') : 'null' }},
            requestedBySearch: '',
            partnerSearch: '',
            requestedByOptions: @js($requestedByConsultants->map(fn ($c) => [
                'id' => $c->id,
                'label' => ($c->scn_code ?? $c->consultant_code).' — '.$c->user->name.($c->id === $consultant->id ? ' (You)' : ''),
            ])->values()),
            partnerOptions: @js($partnerOptions),
            partnerChains: @js((object) $partnerChains),
            groupPreviews: @js($groupPreviews),
            get filteredRequestedByOptions() {
                return this.requestedByOptions.filter(o => o.label.toLowerCase().includes(this.requestedBySearch.toLowerCase()));
            },
            get filteredPartnerOptions() {
                // Only accounts in the chosen Requested By consultant's own group:
                // their own service-group accounts, and every account of anyone
                // below them, main accounts included. Their own main is None.
                // Picking one NEVER changes Requested By (2026-11-03): whoever is
                // named there pays, and the account picked earns.
                const term = this.partnerSearch.toLowerCase();
                const rb = Number(this.requestedBy);

                return this.partnerOptions.filter(o => Number(o.id) !== rb
                    && (this.partnerChains[o.owner_id] || []).includes(rb)
                    && o.label.toLowerCase().includes(term));
            },
            get partnerHolderId() {
                const p = this.partnerOptions.find(o => Number(o.id) === Number(this.partner));

                return p ? Number(p.owner_id) : null;
            },
            get paidByRequester() {
                // Somebody else's account: Requested By pays, the account earns.
                return this.partnerHolderId !== null && this.partnerHolderId !== Number(this.requestedBy);
            },
            get partnerCode() {
                return this.partnerSelectedLabel.split(' — ')[0];
            },
            get partnerOwnerLabel() {
                const owner = this.requestedByOptions.find(o => Number(o.id) === Number(this.requestedBy));

                return owner ? owner.label.split(' — ')[0] : 'the selected consultant';
            },
            get requestedBySelectedLabel() {
                return this.requestedByOptions.find(o => o.id === this.requestedBy)?.label || '';
            },
            get partnerSelectedLabel() {
                return this.partnerOptions.find(o => o.id === this.partner)?.label || '';
            },
            get activeAccountId() {
                return this.partner !== null && this.partner !== '' ? this.partner : this.requestedBy;
            },
            get activeAccountLabel() {
                return this.partner !== null && this.partner !== ''
                    ? this.partnerSelectedLabel
                    : this.requestedBySelectedLabel;
            },
            get activePreview() {
                return this.groupPreviews[String(this.activeAccountId)] ?? null;
            },
            get sg1Label() {
                const p = this.activePreview;
                return 'Service Group 1' + (p ? ' (' + p.tier1_filled + ' / ' + p.tier1_capacity + ' filled)' : '');
            },
            get sg2Label() {
                const p = this.activePreview;
                return 'Service Group 2' + (p ? ' (' + p.tier2_filled + ' / ' + p.tier2_capacity + ' filled)' : '');
            },
            get selectedCourseDefaults() {
                return this.courseDefaults[this.selectedCourse] ?? null;
            },
            /**
             * Picking a course (force=true) always overwrites Language/Class Type/
             * Course Type with that course's saved defaults. On page load
             * (force=false) — e.g. a course pre-selected via old() after a
             * validation error, or a link that pre-picks a course — only the
             * still-blank fields are filled in, so an earlier explicit choice
             * made by the consultant is never silently overwritten.
             */
            applyCourseDefaults(force = false) {
                const d = this.selectedCourseDefaults;
                if (! d) return;
                if (d.language && (force || !this.language)) this.language = d.language;
                if (d.class_type && (force || !this.classType)) this.classType = d.class_type;
                if (d.course_type && (force || !this.courseType)) this.courseType = d.course_type;
            },
        }" x-init="applyCourseDefaults()">
            <h2 class="text-xl font-bold text-slate-900 dark:text-white">Student Registration</h2>
            <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">Create the student's account and enroll them in a course. No payment is collected here — confirm their payment plan from Pending Students within 14 days, or the registration is removed automatically.</p>

            {{-- ⚠️ ALREADY REGISTERED — A POPUP, NOT A LINE IN A LIST.

                 Asked for 2026-09-07: "eka kalin add krla thiyeno nm 'already
                 have' kiyla pop up ekak penwann". A duplicate is the one error
                 on this form that means "stop and go look at something else"
                 rather than "correct this field", and buried in the error list
                 above it was being scrolled past — which is how the same person
                 was registered twice in the first place.

                 The rest of the errors keep the inline list; only this one
                 interrupts. --}}
            @error('duplicate')
                <div x-data="{ open: true }" x-show="open" x-cloak
                    class="fixed inset-0 z-50 flex items-center justify-center bg-slate-900/50 p-4"
                    @keydown.escape.window="open = false">
                    <div class="w-full max-w-md rounded-2xl bg-white p-6 shadow-xl dark:bg-slate-900" @click.outside="open = false">
                        <div class="flex items-start gap-3">
                            <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full bg-amber-100 text-amber-600 dark:bg-amber-500/20 dark:text-amber-400">
                                <svg class="h-5 w-5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v4m0 4h.01M10.29 3.86 1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z" />
                                </svg>
                            </span>
                            <div class="min-w-0">
                                <h3 class="text-base font-bold text-slate-900 dark:text-white">This student already has an account</h3>
                                <p class="mt-2 text-sm text-slate-600 dark:text-slate-300">{{ $message }}</p>
                            </div>
                        </div>
                        <div class="mt-5 flex justify-end gap-2">
                            <a href="{{ route('consultant.students.index') }}" class="btn-secondary text-sm">See all students</a>
                            <button type="button" class="btn-primary text-sm" @click="open = false">Change the details</button>
                        </div>
                    </div>
                </div>
            @enderror

            @if ($errors->any())
                <div class="mt-6 rounded-xl bg-rose-50 px-4 py-3 text-sm text-rose-700 ring-1 ring-rose-200 dark:bg-rose-500/10 dark:text-rose-300 dark:ring-rose-500/30">
                    <ul class="list-inside list-disc space-y-1">@foreach ($errors->all() as $e)<li>{{ $e }}</li>@endforeach</ul>
                </div>
            @endif

            <form method="POST" action="{{ route('consultant.students.store') }}" enctype="multipart/form-data" class="mt-6 space-y-8">
                @csrf

                {{-- Personal Information --}}
                <div>
                    <h3 class="text-sm font-bold uppercase tracking-wide text-slate-400">Personal Information</h3>
                    <div class="mt-4 grid gap-5 sm:grid-cols-2">
                        <div>
                            <label for="name" class="label">Full Name</label>
                            <input id="name" name="name" type="text" value="{{ old('name') }}" required maxlength="120" class="input" placeholder="Full name">
                        </div>
                        <div>
                            <label for="email" class="label">Email</label>
                            <input id="email" name="email" type="email" value="{{ old('email') }}" required class="input" placeholder="student@example.com">
                        </div>
                        <div>
                            <label for="date_of_birth" class="label">Date of Birth</label>
                            <input id="date_of_birth" name="date_of_birth" type="date" value="{{ old('date_of_birth') }}" max="{{ today()->toDateString() }}" class="input">
                        </div>
                        <div>
                            <label for="gender" class="label">Gender</label>
                            <select id="gender" name="gender" class="input">
                                <option value="">Select gender</option>
                                <option value="male" @selected(old('gender') === 'male')>Male</option>
                                <option value="female" @selected(old('gender') === 'female')>Female</option>
                                <option value="other" @selected(old('gender') === 'other')>Other</option>
                            </select>
                        </div>
                        <div>
                            <label for="phone" class="label">Phone Number</label>
                            <input id="phone" name="phone" type="text" value="{{ old('phone') }}" class="input" placeholder="+94 ...">
                        </div>
                        <div>
                            <label for="nic" class="label">NIC / Passport</label>
                            <input id="nic" name="nic" type="text" value="{{ old('nic') }}" class="input" placeholder="XXXXXXXXXX">
                        </div>
                        <div>
                            <label for="city" class="label">City <span class="font-normal text-slate-400">(optional)</span></label>
                            <input id="city" name="city" type="text" value="{{ old('city') }}" class="input">
                        </div>
                        <div>
                            <label for="district" class="label">District <span class="font-normal text-slate-400">(optional)</span></label>
                            <input id="district" name="district" type="text" value="{{ old('district') }}" class="input">
                        </div>
                        <div class="sm:col-span-2">
                            <label for="address" class="label">Address</label>
                            <textarea id="address" name="address" rows="2" maxlength="300" class="input">{{ old('address') }}</textarea>
                        </div>
                    </div>
                </div>

                {{-- NIC Owner Details --}}
                <div class="border-t border-slate-100 pt-6 dark:border-slate-800">
                    <h3 class="text-sm font-bold uppercase tracking-wide text-slate-400">NIC Owner Details</h3>
                    <label class="mt-4 flex items-center gap-3">
                        <input type="hidden" name="nic_owned_by_student" value="0">
                        <input type="checkbox" name="nic_owned_by_student" value="1" x-model="nicOwnedByStudent" class="h-5 w-5 rounded border-slate-300 text-brand-600">
                        <span class="text-sm font-medium text-slate-700 dark:text-slate-200">NIC / Passport Owned By Student</span>
                    </label>

                    <div x-show="!nicOwnedByStudent" x-cloak class="mt-4 grid gap-5 sm:grid-cols-2">
                        <div>
                            <label for="nic_owner_name" class="label">NIC Owner Name</label>
                            <input id="nic_owner_name" name="nic_owner_name" type="text" value="{{ old('nic_owner_name') }}" maxlength="120" class="input" placeholder="Enter NIC owner's full name" :required="!nicOwnedByStudent">
                        </div>
                        <div>
                            <label for="nic_owner_relationship" class="label">NIC Owner Relationship</label>
                            <input id="nic_owner_relationship" name="nic_owner_relationship" type="text" value="{{ old('nic_owner_relationship') }}" maxlength="80" class="input" placeholder="e.g. Father, Mother, Guardian" :required="!nicOwnedByStudent">
                        </div>
                        <div class="sm:col-span-2">
                            <label for="no_objection_letter" class="label">No Objection Letter (Parent/Guardian) <span class="font-normal text-slate-400">(optional)</span></label>
                            <input id="no_objection_letter" name="no_objection_letter" type="file" accept=".pdf,.png,.jpg,.jpeg" class="input">
                        </div>
                    </div>
                </div>

                {{-- Course and Placement Information --}}
                <div class="border-t border-slate-100 pt-6 dark:border-slate-800">
                    <h3 class="text-sm font-bold uppercase tracking-wide text-slate-400">Course and Placement Information</h3>
                    <div class="mt-4 grid gap-5 sm:grid-cols-2">
                        <div>
                            <label for="course_id" class="label">Course</label>
                            <select id="course_id" name="course_id" x-model="selectedCourse" @change="applyCourseDefaults(true)" required class="input">
                                <option value="">Select course</option>
                                @foreach ($courses as $course)
                                    <option value="{{ $course->id }}" @selected(old('course_id') == $course->id)>{{ $course->title }} — LKR {{ number_format($course->discount_price ?? $course->price) }}</option>
                                @endforeach
                            </select>
                            <template x-if="selectedCourse && selectedCourseDefaults">
                                <p class="mt-1 text-xs text-slate-400">Language, Class Type and Course Type were filled in for this course — change any of them if this student needs something different.</p>
                            </template>
                        </div>
                        <div>
                            <label for="language" class="label">Language</label>
                            <select id="language" name="language" x-model="language" class="input">
                                <option value="">Select language</option>
                                @foreach (['Sinhala', 'English', 'Tamil'] as $lang)
                                    <option value="{{ $lang }}">{{ $lang }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div>
                            <label for="class_type" class="label">Class Type</label>
                            <select id="class_type" name="class_type" x-model="classType" class="input">
                                <option value="">Select class type</option>
                                @foreach (['Physical', 'Online', 'Hybrid'] as $type)
                                    <option value="{{ $type }}">{{ $type }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div>
                            <label for="course_type" class="label">Course Type</label>
                            <select id="course_type" name="course_type" x-model="courseType" class="input">
                                <option value="">Select course type</option>
                                @foreach (['Individual', 'Group'] as $type)
                                    <option value="{{ $type }}">{{ $type }}</option>
                                @endforeach
                            </select>
                        </div>
                    </div>
                </div>

                {{-- Consultant Information --}}
                <div class="border-t border-slate-100 pt-6 dark:border-slate-800">
                    <h3 class="text-sm font-bold uppercase tracking-wide text-slate-400">Consultant Information</h3>
                    <div class="mt-4 grid gap-5 sm:grid-cols-2">
                        <div class="relative" x-data="{ open: false }">
                            <label class="label">Consultant (Requested By)</label>
                            <p class="mb-1 text-xs text-slate-400">
                                Who registers this student and pays for the registration. Picking a partner account below never changes it.
                            </p>
                            <input type="text" readonly :value="requestedBySelectedLabel" class="input cursor-pointer" @click="open = true; $nextTick(() => $refs.requestedBySearch.focus())">
                            <div x-show="open" x-cloak @click.outside="open = false" class="absolute z-10 mt-1 w-full overflow-hidden rounded-xl border border-slate-200 bg-white shadow-lg dark:border-slate-700 dark:bg-slate-800">
                                <input x-ref="requestedBySearch" type="text" x-model="requestedBySearch" placeholder="Search consultants..." class="input rounded-none border-0 border-b border-slate-200 dark:border-slate-700" @click.stop>
                                <div class="max-h-56 overflow-y-auto">
                                    <template x-for="opt in filteredRequestedByOptions" :key="opt.id">
                                        <button type="button" class="block w-full px-4 py-2 text-left text-sm hover:bg-brand-50 dark:hover:bg-slate-700"
                                            @click="requestedBy = opt.id; partner = null; open = false; requestedBySearch = ''"
                                            x-text="opt.label"></button>
                                    </template>
                                    <div x-show="filteredRequestedByOptions.length === 0" class="px-4 py-2 text-sm text-slate-400">No matches</div>
                                </div>
                            </div>
                            <input type="hidden" name="requested_by_consultant_id" :value="requestedBy">
                        </div>
                        <div class="relative" x-data="{ open: false }">
                            <label class="label">Partner Consultant <span class="font-normal text-slate-400">(optional)</span></label>
                            {{-- The list follows the Requested By picker: that consultant's
                                 own accounts and every account below them (2026-11-03). --}}
                            <p class="mb-1 text-xs text-slate-400">
                                The account the student is placed on: one of the Requested By consultant's own accounts, or any account of someone below them. The service income (2,500) climbs up from that account. On someone else's account, the direct bonus goes to Requested By.
                            </p>
                            <input type="text" readonly :value="partner ? partnerSelectedLabel : 'None (use the main account)'" class="input cursor-pointer" @click="open = true; $nextTick(() => $refs.partnerSearch.focus())">
                            <div x-show="open" x-cloak @click.outside="open = false" class="absolute z-10 mt-1 w-full overflow-hidden rounded-xl border border-slate-200 bg-white shadow-lg dark:border-slate-700 dark:bg-slate-800">
                                <input x-ref="partnerSearch" type="text" x-model="partnerSearch" placeholder="Search service-group accounts..." class="input rounded-none border-0 border-b border-slate-200 dark:border-slate-700" @click.stop>
                                <div class="max-h-56 overflow-y-auto">
                                    <button type="button" class="block w-full px-4 py-2 text-left text-sm hover:bg-brand-50 dark:hover:bg-slate-700" @click="partner = null; open = false; partnerSearch = ''">None (use the main account)</button>
                                    <template x-for="opt in filteredPartnerOptions" :key="opt.id">
                                        <button type="button" class="block w-full px-4 py-2 text-left text-sm hover:bg-brand-50 dark:hover:bg-slate-700"
                                            @click="partner = opt.id; open = false; partnerSearch = ''"
                                            x-text="opt.label"></button>
                                    </template>
                                    <div x-show="filteredPartnerOptions.length === 0" class="px-4 py-2 text-sm text-slate-400"
                                        x-text="partnerSearch ? 'No account matches that search.' : partnerOwnerLabel + ' has no accounts to place on yet.'"></div>
                                </div>
                            </div>
                            <input type="hidden" name="partner_consultant_id" :value="partner">
                        </div>
                    </div>

                    {{-- Somebody else's account: say who pays and who earns what before Add
                         (2026-11-03; the direct bonus moved to Requested By 2026-11-16). --}}
                    <div x-show="paidByRequester" x-cloak class="mt-4 rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-xs text-amber-800 dark:border-amber-500/30 dark:bg-amber-500/10 dark:text-amber-300">
                        <p><span class="font-semibold" x-text="partnerOwnerLabel"></span> pays for this registration: the payment plan, the slip and any balance are done from their Pending Students.</p>
                        <p class="mt-1"><span class="font-semibold" x-text="partnerOwnerLabel"></span> also gets the direct bonus.</p>
                        <p class="mt-1">The student is placed on <span class="font-semibold" x-text="partnerCode"></span>, and the service income (2,500) climbs up from that account to everyone above it. Its holder sees the student as view only, as a pending payment.</p>
                    </div>

                    <div class="mt-4">
                        <label for="placement_tier" class="label">Placement Group <span class="text-rose-500">*</span></label>
                        <select id="placement_tier" name="placement_tier" x-model="placementTier" required class="input max-w-sm">
                            <option value="1" x-text="sg1Label">Service Group 1</option>
                            <option value="2" x-text="sg2Label">Service Group 2</option>
                        </select>
                        <p class="mt-1 text-xs text-slate-400">The student is placed straight into this Service Group of the selected account once their payment is verified.</p>
                    </div>

                    <div class="mt-4 rounded-xl border border-slate-200 bg-slate-50 p-4 dark:border-slate-700 dark:bg-slate-800/50">
                        <div class="flex items-center justify-between">
                            <p class="text-xs font-semibold uppercase tracking-wide text-slate-400">Current service group status</p>
                            <p class="text-xs font-semibold text-brand-600 dark:text-brand-400" x-text="activeAccountLabel"></p>
                        </div>
                        <div class="mt-2 grid grid-cols-2 gap-3 text-sm text-slate-700 dark:text-slate-200">
                            <div>Service Group 1: <span class="font-semibold" x-text="(activePreview ? activePreview.tier1_filled : 0) + ' / ' + (activePreview ? activePreview.tier1_capacity : 3)"></span></div>
                            <div>Service Group 2: <span class="font-semibold" x-text="(activePreview ? activePreview.tier2_filled : 0) + ' / ' + (activePreview ? activePreview.tier2_capacity : 3)"></span></div>
                        </div>
                        <p class="mt-2 text-xs text-slate-400">For information only — placement into a group happens automatically once the payment is verified.</p>
                    </div>
                </div>

                {{-- Account credentials --}}
                <div class="border-t border-slate-100 pt-6 dark:border-slate-800" x-data="{ setPassword: {{ old('password') ? 'true' : 'false' }} }">
                    <!-- <label class="flex items-center gap-3">
                        <input type="checkbox" x-model="setPassword" class="h-5 w-5 rounded border-slate-300 text-brand-600">
                        <span class="text-sm font-bold uppercase tracking-wide text-slate-400">Set a custom password</span>
                    </label> -->

                    <div x-show="setPassword" x-cloak class="mt-4 grid gap-5 sm:grid-cols-2">
                        <div>
                            <label for="password" class="label">Password</label>
                            <input id="password" name="password" type="text" class="input" placeholder="Leave blank to auto-generate" :required="setPassword">
                        </div>
                        <div>
                            <label for="password_confirmation" class="label">Confirm password</label>
                            <input id="password_confirmation" name="password_confirmation" type="text" class="input" :required="setPassword">
                        </div>
                    </div>
                </div>

                <div class="flex justify-end gap-3 border-t border-slate-100 pt-5 dark:border-slate-800">
                    <a href="{{ route('consultant.students.index') }}" class="btn-secondary">Cancel</a>
                    <button type="submit" class="btn-primary">
                        <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4v16m8-8H4"/></svg>
                        Add
                    </button>
                </div>
            </form>
        </div>
    </div>
</x-layouts.app>
