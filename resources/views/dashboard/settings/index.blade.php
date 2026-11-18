<x-layouts.app title="Settings" header="System Settings">
    <div x-data="{ tab: 'commission' }" class="space-y-6">
        <div class="mb-2">
            <h2 class="text-2xl font-extrabold text-slate-900 dark:text-white">Settings</h2>
            <p class="mt-1 text-slate-500 dark:text-slate-400">Configure service income rules and system-wide options. All values are database-driven — no code changes required.</p>
        </div>

        {{-- Tabs --}}
        <div class="flex flex-wrap gap-2 border-b border-slate-200 dark:border-slate-800">
            <button @click="tab = 'commission'" :class="tab === 'commission' ? 'border-brand-600 text-brand-600 dark:text-brand-400' : 'border-transparent text-slate-500 hover:text-slate-700 dark:hover:text-slate-300'" class="border-b-2 px-4 py-2.5 text-sm font-semibold transition">Service Groups & Income</button>
            <button @click="tab = 'general'" :class="tab === 'general' ? 'border-brand-600 text-brand-600 dark:text-brand-400' : 'border-transparent text-slate-500 hover:text-slate-700 dark:hover:text-slate-300'" class="border-b-2 px-4 py-2.5 text-sm font-semibold transition">General & Payment</button>
            <button @click="tab = 'mail'" :class="tab === 'mail' ? 'border-brand-600 text-brand-600 dark:text-brand-400' : 'border-transparent text-slate-500 hover:text-slate-700 dark:hover:text-slate-300'" class="border-b-2 px-4 py-2.5 text-sm font-semibold transition">Email</button>
        </div>

        {{-- COMMISSION TAB --}}
        <div x-show="tab === 'commission'" x-cloak class="space-y-6">
            <div class="card overflow-hidden">
                <div class="border-b border-slate-100 bg-gradient-to-r from-brand-50 to-transparent p-5 dark:border-slate-800 dark:from-brand-500/10">
                    <h3 class="text-lg font-bold text-slate-900 dark:text-white">Service Income configuration</h3>
                    <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">These values drive the service income engine. Updating them creates a new active version; existing service income records are unchanged.</p>
                </div>
                <form method="POST" action="{{ route('settings.commission') }}" class="p-6">
                    @csrf @method('PATCH')
                    <div class="grid gap-5 sm:grid-cols-2 lg:grid-cols-3">
                        <div>
                            <label for="course_price" class="label">Course Price (LKR)</label>
                            <input id="course_price" name="course_price" type="number" step="0.01" value="{{ old('course_price', $commission->course_price) }}" required class="input">
                        </div>
                        <div>
                            <label for="half_payment_amount" class="label">Half Payment — First Instalment (LKR)</label>
                            <input id="half_payment_amount" name="half_payment_amount" type="number" step="0.01" value="{{ old('half_payment_amount', \App\Models\Setting::get('half_payment_amount', 18000)) }}" required class="input">
                            <p class="mt-1 text-xs text-slate-400">What a "half payment" student pays up front; the rest stays as their balance. The standard — a course can set its own under Manage → Courses.</p>
                        </div>
                        <div>
                            <label for="commission_per_student" class="label">Service Group Slot (LKR)</label>
                            <input id="commission_per_student" name="commission_per_student" type="number" step="0.01" value="{{ old('commission_per_student', $commission->commission_per_student) }}" required class="input">
                            <p class="mt-1 text-xs text-slate-400">Earned per student slot — by the consultant and by every upline.</p>
                        </div>
                        <div>
                            <label for="direct_commission" class="label">Direct Add Bonus (LKR)</label>
                            <input id="direct_commission" name="direct_commission" type="number" step="0.01" value="{{ old('direct_commission', $commission->direct_commission) }}" required class="input">
                            @if ((float) ($commission->direct_commission ?? 0) <= 0)
                                <p class="mt-1 text-xs font-semibold text-rose-500">Currently 0 — no direct bonus is being paid. Enter an amount and save.</p>
                            @else
                                <p class="mt-1 text-xs text-slate-400">Paid as soon as the payment is verified, only to the consultant who personally registered the student. The standard — a course can set its own under Manage → Courses.</p>
                            @endif
                        </div>
                        <div>
                            <label for="max_commission" class="label">Maximum Commission (LKR)</label>
                            <input id="max_commission" name="max_commission" type="number" step="0.01" value="{{ old('max_commission', $commission->max_commission) }}" required class="input">
                        </div>
                        <div>
                            <label for="group_size" class="label">Group Size</label>
                            <input id="group_size" name="group_size" type="number" value="{{ old('group_size', $commission->group_size) }}" required class="input">
                        </div>
                    </div>

                    <div class="mt-6 rounded-xl border border-slate-200 p-5 dark:border-slate-800">
                        <h4 class="text-sm font-bold text-slate-900 dark:text-white">Tier structure</h4>
                        <p class="mt-1 text-xs text-slate-500 dark:text-slate-400">Split the group into two commission tiers (e.g. 3 + 3 students).</p>
                        <div class="mt-4 grid gap-5 sm:grid-cols-2 lg:grid-cols-4">
                            <div>
                                <label for="first_tier_students" class="label">Tier 1 Students</label>
                                <input id="first_tier_students" name="first_tier_students" type="number" value="{{ old('first_tier_students', $commission->first_tier_students) }}" required class="input">
                            </div>
                            <div>
                                <label for="first_tier_amount" class="label">Tier 1 Amount (LKR)</label>
                                <input id="first_tier_amount" name="first_tier_amount" type="number" step="0.01" value="{{ old('first_tier_amount', $commission->first_tier_amount) }}" required class="input">
                            </div>
                            <div>
                                <label for="second_tier_students" class="label">Tier 2 Students</label>
                                <input id="second_tier_students" name="second_tier_students" type="number" value="{{ old('second_tier_students', $commission->second_tier_students) }}" required class="input">
                            </div>
                            <div>
                                <label for="second_tier_amount" class="label">Tier 2 Amount (LKR)</label>
                                <input id="second_tier_amount" name="second_tier_amount" type="number" step="0.01" value="{{ old('second_tier_amount', $commission->second_tier_amount) }}" required class="input">
                            </div>
                        </div>
                    </div>

                    {{-- Weekly student quota. A consultant who misses it is
                         restricted (income held), and stays restricted for the
                         grace window before being suspended. --}}
                    <div class="mt-6 rounded-xl border border-slate-200 p-5 dark:border-slate-800">
                        <h4 class="text-sm font-bold text-slate-900 dark:text-white">Consultant weekly requirement</h4>
                        <p class="mt-1 text-xs text-slate-500 dark:text-slate-400">
                            Each finance week a consultant must register this many students. Miss it and the account is
                            restricted — they keep earning and can still see every rupee, but their income cannot be
                            approved or paid out until they catch up. Miss it for the whole grace window and the account
                            is suspended until an admin reactivates it.
                        </p>
                        <div class="mt-4 grid gap-5 sm:grid-cols-2 lg:grid-cols-4">
                            <div>
                                <label for="finance_quota_enabled" class="label">Weekly requirement</label>
                                <select id="finance_quota_enabled" name="finance_quota_enabled" required class="input">
                                    <option value="1" @selected((int) old('finance_quota_enabled', $financeQuotaEnabled) === 1)>On</option>
                                    <option value="0" @selected((int) old('finance_quota_enabled', $financeQuotaEnabled) === 0)>Off — nobody is restricted</option>
                                </select>
                                <p class="mt-1 text-xs text-slate-400">
                                    Off parks the whole thing: nobody is restricted or suspended, and anyone already
                                    restricted can be paid again. Turning it back On puts everyone back exactly as they were.
                                </p>
                            </div>
                            <div>
                                <label for="finance_week_start_day" class="label">Finance week starts</label>
                                <select id="finance_week_start_day" name="finance_week_start_day" required class="input">
                                    @foreach ([1 => 'Monday', 2 => 'Tuesday', 3 => 'Wednesday', 4 => 'Thursday', 5 => 'Friday', 6 => 'Saturday', 7 => 'Sunday'] as $iso => $dayName)
                                        <option value="{{ $iso }}" @selected((int) old('finance_week_start_day', $financeWeekStartDay) === $iso)>{{ $dayName }}</option>
                                    @endforeach
                                </select>
                                <p class="mt-1 text-xs text-slate-400">Runs from 00:00 that day to 23:59 six days later, Sri Lanka time.</p>
                            </div>
                            <div>
                                <label for="finance_week_quota" class="label">Students required per week</label>
                                <input id="finance_week_quota" name="finance_week_quota" type="number" min="0" max="100"
                                    value="{{ old('finance_week_quota', $financeWeekQuota) }}" required class="input">
                                <p class="mt-1 text-xs text-slate-400">Set to 0 to switch the requirement off entirely.</p>
                            </div>
                            <div>
                                <label for="finance_grace_weeks" class="label">Grace weeks before suspension</label>
                                <input id="finance_grace_weeks" name="finance_grace_weeks" type="number" min="0" max="52"
                                    value="{{ old('finance_grace_weeks', $financeGraceWeeks) }}" required class="input">
                                <p class="mt-1 text-xs text-slate-400">Weeks a restricted consultant has to catch up.</p>
                            </div>
                        </div>
                    </div>

                    {{-- Staged service-group income. Slot income is released
                         only when a whole stage of pairs balances. --}}
                    <div class="mt-6 rounded-xl border border-slate-200 p-5 dark:border-slate-800">
                        <h4 class="text-sm font-bold text-slate-900 dark:text-white">Service group release stages</h4>
                        <p class="mt-1 text-xs text-slate-500 dark:text-slate-400">
                            Service group income is released only when both legs balance. The first two stages happen
                            <strong>once in a consultant's lifetime</strong>; the third repeats forever. Each pair pays
                            2 &times; the per-student rate above.
                        </p>
                        <div class="mt-4 grid gap-5 sm:grid-cols-2 lg:grid-cols-4">
                            <div>
                                <label for="stage_first_pairs" class="label">Stage 1 — pairs</label>
                                <input id="stage_first_pairs" name="stage_first_pairs" type="number" min="1" max="50"
                                    value="{{ old('stage_first_pairs', $stageFirstPairs) }}" required class="input">
                                <p class="mt-1 text-xs text-slate-400">One time only. Default 1 (&ldquo;1-to-1&rdquo;).</p>
                            </div>
                            <div>
                                <label for="stage_second_pairs" class="label">Stage 2 — pairs</label>
                                <input id="stage_second_pairs" name="stage_second_pairs" type="number" min="1" max="50"
                                    value="{{ old('stage_second_pairs', $stageSecondPairs) }}" required class="input">
                                <p class="mt-1 text-xs text-slate-400">One time only. Default 2 (&ldquo;2-to-2&rdquo;).</p>
                            </div>
                            <div>
                                <label for="stage_repeat_pairs" class="label">Repeating stage — pairs</label>
                                <input id="stage_repeat_pairs" name="stage_repeat_pairs" type="number" min="1" max="50"
                                    value="{{ old('stage_repeat_pairs', $stageRepeatPairs) }}" required class="input">
                                <p class="mt-1 text-xs text-slate-400">Repeats forever. Default 3 (&ldquo;3-to-3&rdquo;).</p>
                            </div>
                            <div>
                                <label for="account_unlock_students" class="label">Students to unlock next account</label>
                                <input id="account_unlock_students" name="account_unlock_students" type="number" min="1" max="500"
                                    value="{{ old('account_unlock_students', $accountUnlockStudents) }}" required class="input">
                                <p class="mt-1 text-xs text-slate-400">Default 12. The next account earns nothing until then.</p>
                            </div>
                        </div>
                    </div>

                    <div class="mt-6 flex justify-end">
                        <button type="submit" class="btn-primary">Save commission settings</button>
                    </div>
                </form>
            </div>

            {{-- Two switches (2026-10-01). Not part of the versioned commission
                 row above — neither is a money rate. --}}
            <div class="card overflow-hidden">
                <div class="border-b border-slate-100 p-5 dark:border-slate-800">
                    <h3 class="text-lg font-bold text-slate-900 dark:text-white">Student line, course videos &amp; hand-entry alerts</h3>
                </div>
                <form method="POST" action="{{ route('settings.switches') }}" class="grid gap-5 p-6 sm:grid-cols-2">
                    @csrf
                    @method('PATCH')
                    <div>
                        <label for="student_line_attach" class="label">Student line</label>
                        <select id="student_line_attach" name="student_line_attach" class="input">
                            <option value="1" @selected((int) old('student_line_attach', $studentLineAttach) === 1)>On</option>
                            <option value="0" @selected((int) old('student_line_attach', $studentLineAttach) === 0)>Off</option>
                        </select>
                        <p class="mt-1 text-xs text-slate-400">
                            On: a student who becomes a consultant goes under the nearest consultant above them on their line,
                            so their students pay that person and then everyone above as before. Affects promotions from now on only.
                        </p>
                    </div>
                    <div>
                        <label for="video_chunk_mb" class="label">Video piece size (MB)</label>
                        <input id="video_chunk_mb" name="video_chunk_mb" type="number" min="0" max="64" step="1"
                            value="{{ old('video_chunk_mb', $videoChunkMb) }}" class="input">
                        <p class="mt-1 text-xs text-slate-400">
                            Course videos are sent in pieces of this size so one viewer never holds the server for a whole lesson.
                            Default 4. 0 sends the whole file at once (the old way).
                        </p>
                    </div>
                    {{-- 2026-10-29: who is emailed when a commission is added by hand
                         (Service Groups page). See App\Services\ManualCommissionAlert. --}}
                    <div class="sm:col-span-2">
                        <label for="manual_commission_alert_emails" class="label">Hand-entry alert emails</label>
                        <input id="manual_commission_alert_emails" name="manual_commission_alert_emails" type="text"
                            value="{{ old('manual_commission_alert_emails', $manualCommissionAlertEmails) }}" class="input"
                            placeholder="name@example.com, other@example.com">
                        @error('manual_commission_alert_emails')
                            <p class="mt-1 text-xs text-rose-500">{{ $message }}</p>
                        @enderror
                        <p class="mt-1 text-xs text-slate-400">
                            Every commission added by hand on the Service Groups page is emailed to these addresses — name, SCN,
                            Service Group, amount, course, date, who receives it and when it was added. Separate with commas. Leave empty to send none.
                        </p>
                    </div>
                    <div class="sm:col-span-2 flex justify-end">
                        <button type="submit" class="btn-primary">Save</button>
                    </div>
                </form>
            </div>

            {{-- Live preview --}}
            <div class="card bg-gradient-to-br from-brand-700 to-brand-950 p-6 text-white">
                <h4 class="text-sm font-semibold text-white/70">Current active configuration</h4>
                <div class="mt-4 grid grid-cols-2 gap-4 sm:grid-cols-4">
                    <div><div class="text-2xl font-extrabold">LKR {{ number_format($commission->course_price) }}</div><div class="text-xs text-white/60">Course price</div></div>
                    <div><div class="text-2xl font-extrabold text-accent-400">LKR {{ number_format($commission->max_commission) }}</div><div class="text-xs text-white/60">Max / group</div></div>
                    <div><div class="text-2xl font-extrabold">{{ $commission->group_size }}</div><div class="text-xs text-white/60">Group size</div></div>
                    <div><div class="text-2xl font-extrabold">LKR {{ number_format($commission->commission_per_student) }}</div><div class="text-xs text-white/60">Per student</div></div>
                </div>
            </div>
        </div>

        {{-- GENERAL TAB --}}
        <div x-show="tab === 'general'" x-cloak>
            <div class="card overflow-hidden">
                <div class="border-b border-slate-100 p-5 dark:border-slate-800">
                    <h3 class="text-lg font-bold text-slate-900 dark:text-white">General & payment settings</h3>
                </div>
                <form method="POST" action="{{ route('settings.general') }}" class="p-6">
                    @csrf @method('PATCH')
                    <div class="grid gap-5 sm:grid-cols-2">
                        <div>
                            <label for="site_name" class="label">Site name</label>
                            <input id="site_name" name="site_name" type="text" value="{{ old('site_name', $general['site_name']->value ?? 'ETEC') }}" required class="input">
                        </div>
                        <div>
                            <label for="site_tagline" class="label">Tagline</label>
                            <input id="site_tagline" name="site_tagline" type="text" value="{{ old('site_tagline', $general['site_tagline']->value ?? '') }}" class="input">
                        </div>
                        <div>
                            <label for="contact_email" class="label">Contact email</label>
                            <input id="contact_email" name="contact_email" type="email" value="{{ old('contact_email', $general['contact_email']->value ?? '') }}" required class="input">
                        </div>
                        <div>
                            <label for="contact_phone" class="label">Contact phone</label>
                            <input id="contact_phone" name="contact_phone" type="text" value="{{ old('contact_phone', $general['contact_phone']->value ?? '') }}" class="input">
                        </div>
                        <div class="sm:col-span-2">
                            <label for="contact_address" class="label">Address</label>
                            <input id="contact_address" name="contact_address" type="text" value="{{ old('contact_address', $general['contact_address']->value ?? '') }}" class="input">
                        </div>
                        <div>
                            <label for="default_currency" class="label">Currency</label>
                            <input id="default_currency" name="default_currency" type="text" value="{{ old('default_currency', $general['default_currency']->value ?? 'LKR') }}" required class="input">
                        </div>
                    </div>

                    <div class="mt-6 rounded-xl border border-slate-200 p-5 dark:border-slate-800">
                        <h4 class="text-sm font-bold text-slate-900 dark:text-white">Bank details (for manual payments)</h4>
                        <div class="mt-4 grid gap-5 sm:grid-cols-3">
                            <div>
                                <label for="bank_name" class="label">Bank name</label>
                                <input id="bank_name" name="bank_name" type="text" value="{{ old('bank_name', $general['bank_name']->value ?? '') }}" class="input">
                            </div>
                            <div>
                                <label for="bank_account_name" class="label">Account name</label>
                                <input id="bank_account_name" name="bank_account_name" type="text" value="{{ old('bank_account_name', $general['bank_account_name']->value ?? '') }}" class="input">
                            </div>
                            <div>
                                <label for="bank_account_number" class="label">Account number</label>
                                <input id="bank_account_number" name="bank_account_number" type="text" value="{{ old('bank_account_number', $general['bank_account_number']->value ?? '') }}" class="input">
                            </div>
                        </div>
                    </div>

                    <div class="mt-6 flex justify-end">
                        <button type="submit" class="btn-primary">Save general settings</button>
                    </div>
                </form>
            </div>
        </div>

        {{-- EMAIL TAB --}}
        <div x-show="tab === 'mail'" x-cloak class="space-y-6">
            <div class="card overflow-hidden">
                <div class="border-b border-slate-100 p-5 dark:border-slate-800">
                    <h3 class="text-lg font-bold text-slate-900 dark:text-white">Outgoing email (SMTP)</h3>
                    <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">Controls welcome emails, payment notifications, etc. Update this whenever your email provider changes — no developer needed.</p>
                </div>
                <form method="POST" action="{{ route('settings.mail') }}" class="p-6" x-data="{ mailer: '{{ old('mail_mailer', $mail['mail_mailer']->value ?? 'log') }}' }">
                    @csrf @method('PATCH')
                    <div class="grid gap-5 sm:grid-cols-2">
                        <div>
                            <label for="mail_mailer" class="label">Sending method</label>
                            <select id="mail_mailer" name="mail_mailer" x-model="mailer" required class="input">
                                <option value="log">Log only (no real emails sent — testing)</option>
                                <option value="smtp">SMTP (send real emails)</option>
                            </select>
                        </div>
                        <div>
                            <label for="mail_from_address" class="label">From address</label>
                            <input id="mail_from_address" name="mail_from_address" type="email" value="{{ old('mail_from_address', $mail['mail_from_address']->value ?? '') }}" required class="input" placeholder="no-reply@etec.lk">
                        </div>
                        <div>
                            <label for="mail_from_name" class="label">From name</label>
                            <input id="mail_from_name" name="mail_from_name" type="text" value="{{ old('mail_from_name', $mail['mail_from_name']->value ?? 'ETEC College') }}" required class="input">
                        </div>
                    </div>

                    <div x-show="mailer === 'smtp'" x-cloak class="mt-6 rounded-xl border border-slate-200 p-5 dark:border-slate-800">
                        <h4 class="text-sm font-bold text-slate-900 dark:text-white">SMTP server details</h4>
                        <div class="mt-4 grid gap-5 sm:grid-cols-2">
                            <div>
                                <label for="mail_host" class="label">SMTP host</label>
                                <input id="mail_host" name="mail_host" type="text" value="{{ old('mail_host', $mail['mail_host']->value ?? '') }}" class="input" placeholder="smtp.gmail.com">
                            </div>
                            <div>
                                <label for="mail_port" class="label">SMTP port</label>
                                <input id="mail_port" name="mail_port" type="number" value="{{ old('mail_port', $mail['mail_port']->value ?? '587') }}" class="input" placeholder="587">
                            </div>
                            <div>
                                <label for="mail_encryption" class="label">Encryption</label>
                                <select id="mail_encryption" name="mail_encryption" class="input">
                                    <option value="tls" @selected(old('mail_encryption', $mail['mail_encryption']->value ?? 'tls') === 'tls')>TLS</option>
                                    <option value="ssl" @selected(old('mail_encryption', $mail['mail_encryption']->value ?? '') === 'ssl')>SSL</option>
                                </select>
                            </div>
                            <div>
                                <label for="mail_username" class="label">Username</label>
                                <input id="mail_username" name="mail_username" type="text" value="{{ old('mail_username', $mail['mail_username']->value ?? '') }}" class="input" placeholder="you@gmail.com">
                            </div>
                            <div class="sm:col-span-2">
                                <label for="mail_password" class="label">Password <span class="font-normal text-slate-400">({{ filled($mail['mail_password']->value ?? null) ? 'a password is already saved — leave blank to keep it' : 'not set yet' }})</span></label>
                                <input id="mail_password" name="mail_password" type="password" class="input" placeholder="Leave blank to keep current password" autocomplete="new-password">
                            </div>
                        </div>
                    </div>

                    <div class="mt-6 flex justify-end">
                        <button type="submit" class="btn-primary">Save email settings</button>
                    </div>
                </form>
            </div>

            <div class="card p-6">
                <h4 class="text-sm font-bold text-slate-900 dark:text-white">Send a test email</h4>
                <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">Save your settings above first, then send a test to confirm it actually works.</p>
                <form method="POST" action="{{ route('settings.mail.test') }}" class="mt-4 flex flex-col gap-3 sm:flex-row">
                    @csrf
                    <input type="email" name="test_email" required class="input flex-1" placeholder="your@email.com" value="{{ auth()->user()->email }}">
                    <button type="submit" class="btn-secondary">Send test email</button>
                </form>
            </div>
        </div>
    </div>
</x-layouts.app>
