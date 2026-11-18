<x-layouts.app title="Edit User" header="Edit User">
    <div class="mb-6">
        <a href="{{ route('users.index') }}" class="inline-flex items-center gap-1.5 text-sm font-medium text-slate-500 hover:text-brand-600 dark:text-slate-400 dark:hover:text-brand-400">
            <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M15 19l-7-7 7-7"/></svg>
            Back to users
        </a>
    </div>

    {{-- Two balanced columns rather than one narrow ribbon of tiles down
         the middle: "user edit screen eka piliwelak na, eka piliwelakata
         hadal denn, tile list ekak widiyt nathuw depaththa balance krla"
         (2026-09-10). Service Group accounts stays full width at the
         bottom — it is a two-column grid of its own. --}}
    <div class="space-y-6">
        {{-- Identity header --}}
        <div class="card flex flex-wrap items-start justify-between gap-4 p-6">
            <div class="flex items-center gap-4">
                <x-user-avatar :user="$user" size="h-14 w-14" text="text-xl" />
                <div>
                    <h2 class="text-lg font-bold text-slate-900 dark:text-white">{{ $user->name }}</h2>
                    <p class="text-sm text-slate-500 dark:text-slate-400">{{ $user->email }}</p>
                    <span class="badge mt-1.5 bg-brand-50 text-brand-700 dark:bg-brand-500/10 dark:text-brand-400">{{ \Illuminate\Support\Str::headline($user->roles->first()?->name ?? 'No role') }}</span>

                    {{-- ⚠️ THE SCN NUMBERS, WHERE SOMEBODY LOOKING FOR THEM WOULD LOOK
                         (2026-09-10). They were on this page already — but only as the
                         heading of each income-control tile in the Service Group accounts
                         panel, which is the last thing on the screen and renders only for
                         a consultant. Main account first; see scnCodesFor(). --}}
                    @if ($scnCodes !== [])
                        <p class="mt-2 text-xs text-slate-500 dark:text-slate-400">
                            <span class="font-semibold uppercase tracking-wide text-slate-400">SCN numbers</span><br>
                            @foreach ($scnCodes as $i => $code)
                                <span class="font-mono">{{ $code }}</span>@if ($i === 0)<span class="text-slate-400"> (main)</span>@endif{{ $loop->last ? '' : ' · ' }}
                            @endforeach
                        </p>
                    @endif
                </div>
            </div>

            {{-- ⚠️ RE-SEND THE REGISTRATION EMAIL. Mirrors the consultant-side
                 action, including its rule: a fresh temporary password is issued
                 ONLY while the student has never logged in — otherwise a
                 well-meant re-send would lock them out of the account they are
                 already using. Disabled with the reason when there is no unpaid
                 registration to chase, rather than failing when pressed. --}}
            <div class="shrink-0">
                @if ($resendPayment)
                    <form method="POST" action="{{ route('users.resend-registration', $user) }}"
                        onsubmit="return confirm('Re-send the registration email to {{ $user->email }}?{{ $user->password_must_change ? ' A new temporary password will be issued and any earlier one stops working.' : '' }}');">
                        @csrf
                        <button type="submit" class="btn-secondary text-sm">Re-send registration email</button>
                    </form>
                    <p class="mt-1 max-w-xs text-right text-xs text-slate-400">
                        For {{ $resendPayment->payment_code }}
                        @if (! $user->password_must_change)
                            · keeps their current password
                        @endif
                    </p>
                @else
                    {{-- Nothing unpaid (2026-09-22): still clickable — it sends their
                         login details instead. See resendLoginDetails(). --}}
                    <form method="POST" action="{{ route('users.resend-registration', $user) }}"
                        onsubmit="return confirm('Send login details to {{ $user->email }}?{{ $user->password_must_change ? ' A new temporary password will be issued and any earlier one stops working.' : '' }}');">
                        @csrf
                        <button type="submit" class="btn-secondary text-sm">Re-send login details</button>
                    </form>
                    <p class="mt-1 max-w-xs text-right text-xs text-slate-400">
                        @if ($user->password_must_change)
                            Sends their login details with a new temporary password
                        @else
                            Sends a password reset link · keeps their current password
                        @endif
                    </p>
                @endif

                {{-- ⚠️ SUPER ADMIN ONLY: log in as this user to see a problem the way they
                     see it (2026-09-14). The route and ImpersonationController both check
                     the role again, and it is never offered for another super admin. --}}
                @role('super_admin')
                    @if ($user->id !== auth()->id() && ! $user->isSuperAdmin())
                        <form method="POST" action="{{ route('users.login-as', $user) }}" class="mt-3 text-right"
                            onsubmit="return confirm('Log in as ' + @js($user->name) + '? You will act as this user until you press Return to my account.');">
                            @csrf
                            <button type="submit" class="btn-primary text-sm">Log in as this user</button>
                        </form>
                    @endif
                @endrole
            </div>
        </div>

        @if ($errors->any())
            <div class="rounded-xl bg-rose-50 px-4 py-3 text-sm text-rose-700 ring-1 ring-rose-200 dark:bg-rose-500/10 dark:text-rose-300 dark:ring-rose-500/30">
                <ul class="list-inside list-disc space-y-1">@foreach ($errors->all() as $e)<li>{{ $e }}</li>@endforeach</ul>
            </div>
        @endif

        <div class="grid gap-6 lg:grid-cols-2">
            <div class="space-y-6">
        {{-- Details form --}}
        <div class="card p-6 lg:p-8">
            <h3 class="text-lg font-bold text-slate-900 dark:text-white">Account details</h3>
            <form method="POST" action="{{ route('users.update', $user) }}" class="mt-5 space-y-5">
                @csrf @method('PATCH')
                <div class="grid gap-5 sm:grid-cols-2">
                    <div>
                        <label for="name" class="label">Full name</label>
                        <input id="name" name="name" type="text" value="{{ old('name', $user->name) }}" required class="input">
                    </div>
                    <div>
                        <label for="email" class="label">Email</label>
                        <input id="email" name="email" type="email" value="{{ old('email', $user->email) }}" required class="input">
                        <div class="mt-1">@include('dashboard.users.partials.mistyped-email', ['email' => $user->email])</div>
                    </div>
                    <div>
                        <label for="phone" class="label">Phone</label>
                        <input id="phone" name="phone" type="text" value="{{ old('phone', $user->phone) }}" class="input">
                    </div>
                    <div>
                        <label for="status" class="label">Status</label>
                        <select id="status" name="status" required class="input">
                            @foreach (['active', 'inactive', 'suspended'] as $s)
                                <option value="{{ $s }}" @selected(old('status', $user->status) === $s)>{{ ucfirst($s) }}</option>
                            @endforeach
                        </select>
                    </div>
                </div>
                <div class="flex justify-end">
                    <button type="submit" class="btn-primary">Save changes</button>
                </div>
            </form>
        </div>

        {{-- Password form --}}
        <div class="card p-6 lg:p-8">
            <h3 class="text-lg font-bold text-slate-900 dark:text-white">Change password</h3>
            <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">Set a new password for this user. They won't need their old password.</p>
            <form method="POST" action="{{ route('users.password', $user) }}" class="mt-5 space-y-5">
                @csrf @method('PATCH')
                <div class="grid gap-5 sm:grid-cols-2">
                    @php $lockedForThisAdmin = $user->hasRole('super_admin') && ! auth()->user()->isSuperAdmin(); @endphp
                    <x-password-input name="password" label="New password" :required="true" :disabled="$lockedForThisAdmin" />
                    <x-password-input name="password_confirmation" label="Confirm password" :required="true" :disabled="$lockedForThisAdmin" />
                </div>
                <div class="flex justify-end">
                    <button type="submit" class="btn-primary" @disabled($user->hasRole('super_admin') && ! auth()->user()->isSuperAdmin())>Update password</button>
                </div>
            </form>
        </div>

        {{-- Profile lock --}}
        @if ($user->profile_locked_at)
            <div class="card p-6 lg:p-8">
                <h3 class="text-lg font-bold text-slate-900 dark:text-white">Profile lock</h3>
                <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">
                    This user's self-service profile locked on {{ $user->profile_locked_at->format('M d, Y') }} after their first save (bank/beneficiary/personal details can no longer be self-edited). Unlock it if a genuine correction is needed.
                </p>
                <form method="POST" action="{{ route('users.unlock-profile', $user) }}" class="mt-4">
                    @csrf @method('PATCH')
                    <button type="submit" class="btn-secondary">Unlock profile</button>
                </form>
            </div>
        @endif

            </div>

            <div class="space-y-6">
        {{-- Role form --}}
        <div class="card p-6 lg:p-8">
            <h3 class="text-lg font-bold text-slate-900 dark:text-white">Role & permissions</h3>
            <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">Changing the role updates what this user can access. Promoting to Consultant or Student creates the matching profile automatically.</p>
            <form method="POST" action="{{ route('users.role', $user) }}" class="mt-5 flex flex-col gap-3 sm:flex-row sm:items-end">
                @csrf @method('PATCH')
                <div class="flex-1">
                    <label for="role" class="label">Assigned role</label>
                    <select id="role" name="role" class="input" @disabled($user->hasRole('super_admin') && ! auth()->user()->isSuperAdmin())>
                        @php $currentRole = $user->roles->first()?->name; @endphp
                        @foreach ($assignableRoles as $role)
                            <option value="{{ $role }}" @selected($currentRole === $role)>{{ \Illuminate\Support\Str::headline($role) }}</option>
                        @endforeach
                        @if ($currentRole && ! in_array($currentRole, $assignableRoles))
                            <option value="{{ $currentRole }}" selected>{{ \Illuminate\Support\Str::headline($currentRole) }}</option>
                        @endif
                    </select>
                </div>
                <button type="submit" class="btn-primary" @disabled($user->hasRole('super_admin') && ! auth()->user()->isSuperAdmin())>Update role</button>
            </form>

            {{-- ⚠️ PAGE ACCESS (2026-09-22) — replaces the single "Downline Admission
                 Report" switch that sat here. Owner: "Admission report eka, payment
                 summary report, My bookings, All bookings ewa access eken dena widiyt
                 dala denn one buy one access denn saha okkom access denn". OFF for
                 every consultant until ticked here. The Admission Report grant now
                 includes their whole downline (the old switch's job), and anyone
                 who already had that switch on keeps the Admission Report.
                 Plain forms, no JavaScript: "Give all" / "Remove all" are forms of
                 their own. edit() runs FinanceSchemaRepair::ensure(), so the column
                 exists by the time this renders. --}}
            @if ($user->consultant)
                @php
                    $granted = $user->consultant->grantedPages();
                    $legacyAdmissions = $user->consultant->canViewDownlineReport() && ! in_array('admissions', $granted, true);
                @endphp

                <div class="mt-6 border-t border-slate-100 pt-6 dark:border-slate-800">
                    <div class="flex flex-wrap items-center gap-3">
                        <h4 class="font-bold text-slate-900 dark:text-white">Page access</h4>
                        <span class="badge {{ $granted ? 'bg-emerald-50 text-emerald-600 dark:bg-emerald-500/10 dark:text-emerald-400' : 'bg-slate-100 text-slate-600 dark:bg-slate-800 dark:text-slate-300' }}">
                            {{ count($granted) }} of {{ count(\App\Models\Consultant::PAGE_ACCESS) }} on
                        </span>
                    </div>

                    <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">
                        Off for every consultant until ticked here. The Admission Report shows their whole team —
                        everyone below them, with who each person is under — and never anyone outside it.
                    </p>

                    <form method="POST" action="{{ route('users.page-access', $user) }}" class="mt-4 space-y-2">
                        @csrf @method('PATCH')
                        @foreach (\App\Models\Consultant::PAGE_ACCESS as $key => $label)
                            <label class="flex items-center gap-3 text-sm text-slate-700 dark:text-slate-300">
                                <input type="checkbox" name="pages[]" value="{{ $key }}" class="h-4 w-4 rounded border-slate-300"
                                    @checked(in_array($key, $granted, true) || ($key === 'admissions' && $legacyAdmissions))>
                                {{ $label }}
                                @if ($key === 'admissions' && $legacyAdmissions)
                                    <span class="text-xs text-slate-400">(on from the old downline switch)</span>
                                @endif
                            </label>
                        @endforeach
                        <button type="submit" class="btn-primary mt-2">Save page access</button>
                    </form>

                    <div class="mt-3 flex flex-wrap gap-2">
                        <form method="POST" action="{{ route('users.page-access', $user) }}">
                            @csrf @method('PATCH')
                            @foreach (array_keys(\App\Models\Consultant::PAGE_ACCESS) as $key)
                                <input type="hidden" name="pages[]" value="{{ $key }}">
                            @endforeach
                            <button type="submit" class="btn-secondary text-sm">Give all</button>
                        </form>
                        <form method="POST" action="{{ route('users.page-access', $user) }}">
                            @csrf @method('PATCH')
                            <button type="submit" class="btn-secondary text-sm">Remove all</button>
                        </form>
                    </div>
                </div>
            @endif
        </div>

        {{-- ⚠️ THE COURSES A LECTURER TEACHES (2026-10-25). On these the lecturer
             sees and runs every exam and assignment, whoever wrote it. The whole
             list is replaced on save. A course whose own lecturer this is shows
             ticked and fixed — it is theirs whatever is ticked here.
             See App\Services\LecturerCourses. --}}
        @if ($user->hasRole('lecturer'))
            <div class="card p-6 lg:p-8">
                <h3 class="text-lg font-bold text-slate-900 dark:text-white">Courses this lecturer teaches</h3>
                <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">
                    They see the exams and assignments of these courses — added by an admin or by any lecturer — with the marks.
                </p>
                <form method="POST" action="{{ route('users.lecturer-courses', $user) }}" class="mt-4 space-y-2">
                    @csrf @method('PATCH')
                    <div class="max-h-72 space-y-2 overflow-y-auto">
                        @forelse ($courses as $course)
                            @php $own = (int) $course->lecturer_id === (int) $user->id; @endphp
                            <label class="flex items-center gap-3 text-sm text-slate-700 dark:text-slate-300">
                                <input type="checkbox" name="course_ids[]" value="{{ $course->id }}" class="h-4 w-4 rounded border-slate-300"
                                    @checked($own || in_array((int) $course->id, $assignedCourseIds, true)) @disabled($own)>
                                {{ $course->title }}
                                @if ($own)
                                    <span class="text-xs text-slate-400">(their own course)</span>
                                @endif
                            </label>
                        @empty
                            <p class="text-sm text-slate-400">No courses yet.</p>
                        @endforelse
                    </div>
                    <button type="submit" class="btn-primary mt-2">Save courses</button>
                </form>
            </div>
        @endif
        {{-- ⚠️ PERSONAL / BANK / BENEFICIARY — SUPER ADMIN ONLY (2026-09-10).

             Asked for: "eyage profile eke thiyen okkom details methant load wenn
             one saha ewa super adminta edit krnn puluwn wenn one."

             These are the details money is paid into, so the form is not
             rendered at all for an ordinary admin and the action refuses them
             too (UserManagementController::updateProfileDetails()) — a hidden
             form is not a rule.

             ⚠️ It writes straight past the profile lock, on purpose. Until now
             an admin could only CLEAR the lock and ask the consultant to
             re-save. The lock is neither set nor cleared here: clearing it would
             quietly re-open the bank details to the consultant, and setting it
             would lock out someone still filling their profile in. --}}
        @if ($user->consultant && auth()->user()?->isSuperAdmin())
            <div class="card p-6 lg:p-8">
                <h3 class="text-lg font-bold text-slate-900 dark:text-white">Profile details</h3>
                <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">
                    Correct this consultant's own details. Every field is optional here — fill in only what needs fixing.
                    @if ($user->profile_locked_at)
                        Their own profile page is locked, and saving here does not unlock it.
                    @endif
                </p>

                {{-- POST + _method, not PATCH: PHP parses a multipart body only
                     on a real POST, so a PATCH would arrive with no file and no
                     fields at all. Same trap documented on the profile page. --}}
                <form method="POST" action="{{ route('users.profile-details', $user) }}" enctype="multipart/form-data" class="mt-6 space-y-8">
                    @csrf

                    @php $c = $user->consultant; @endphp

                    <div>
                        <h4 class="text-sm font-bold uppercase tracking-wide text-slate-400">Personal information</h4>
                        <div class="mt-4 grid gap-5 sm:grid-cols-2">
                            <div>
                                <label for="nic" class="label">NIC / Passport</label>
                                <input id="nic" name="nic" type="text" value="{{ old('nic', $c->nic) }}" class="input">
                                @if ($c->nic_verified)
                                    <p class="mt-1 text-xs font-medium text-emerald-600 dark:text-emerald-400">Verified</p>
                                @endif
                            </div>
                            <div>
                                <label for="date_of_birth" class="label">Date of birth</label>
                                <input id="date_of_birth" name="date_of_birth" type="date" max="{{ now()->toDateString() }}"
                                    value="{{ old('date_of_birth', optional($c->date_of_birth)->toDateString()) }}" class="input">
                            </div>
                            <div>
                                <label for="gender" class="label">Gender</label>
                                <select id="gender" name="gender" class="input">
                                    <option value="">—</option>
                                    @foreach (['male' => 'Male', 'female' => 'Female', 'other' => 'Other'] as $value => $label)
                                        <option value="{{ $value }}" @selected(old('gender', $c->gender) === $value)>{{ $label }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <div class="sm:col-span-2">
                                <label for="address" class="label">Address</label>
                                <textarea id="address" name="address" rows="2" maxlength="300" class="input">{{ old('address', $c->address) }}</textarea>
                            </div>
                        </div>
                    </div>

                    <div class="border-t border-slate-100 pt-6 dark:border-slate-800">
                        <h4 class="text-sm font-bold uppercase tracking-wide text-slate-400">Bank details</h4>
                        <div class="mt-4 grid gap-5 sm:grid-cols-2">
                            <div>
                                <label for="bank_name" class="label">Bank</label>
                                <input id="bank_name" name="bank_name" type="text" value="{{ old('bank_name', $c->bank_name) }}" class="input">
                            </div>
                            <div>
                                <label for="bank_account_name" class="label">Account name</label>
                                <input id="bank_account_name" name="bank_account_name" type="text" value="{{ old('bank_account_name', $c->bank_account_name) }}" class="input">
                            </div>
                            <div>
                                <label for="bank_account_number" class="label">Account number</label>
                                <input id="bank_account_number" name="bank_account_number" type="text" value="{{ old('bank_account_number', $c->bank_account_number) }}" class="input">
                            </div>
                            <div>
                                <label for="bank_branch" class="label">Branch</label>
                                <input id="bank_branch" name="bank_branch" type="text" value="{{ old('bank_branch', $c->bank_branch) }}" class="input">
                            </div>
                            <div>
                                {{-- Not required anywhere: a local Sri Lankan account has none. --}}
                                <label for="swift_code" class="label">SWIFT code <span class="font-normal text-slate-400">(optional)</span></label>
                                <input id="swift_code" name="swift_code" type="text" value="{{ old('swift_code', $c->swift_code) }}" class="input">
                            </div>
                            <div>
                                <label for="account_proof" class="label">Account proof <span class="font-normal text-slate-400">(PDF or photo)</span></label>
                                <input id="account_proof" name="account_proof" type="file" accept=".pdf,.png,.jpg,.jpeg" class="input">
                                @if ($c->account_proof_path)
                                    <a href="{{ route('media.show', $c->account_proof_path) }}" target="_blank"
                                        class="mt-1 inline-block text-xs font-medium text-brand-600 hover:underline dark:text-brand-400">View the document on file</a>
                                @endif
                            </div>
                        </div>
                    </div>

                    <div class="border-t border-slate-100 pt-6 dark:border-slate-800">
                        <h4 class="text-sm font-bold uppercase tracking-wide text-slate-400">Beneficiary details</h4>
                        <div class="mt-4 grid gap-5 sm:grid-cols-2">
                            <div>
                                <label for="beneficiary_name" class="label">Full name</label>
                                <input id="beneficiary_name" name="beneficiary_name" type="text" value="{{ old('beneficiary_name', $c->beneficiary_name) }}" class="input">
                            </div>
                            <div>
                                <label for="beneficiary_relationship" class="label">Relationship</label>
                                <input id="beneficiary_relationship" name="beneficiary_relationship" type="text" value="{{ old('beneficiary_relationship', $c->beneficiary_relationship) }}" class="input">
                            </div>
                            <div>
                                <label for="beneficiary_nic" class="label">NIC / Passport</label>
                                <input id="beneficiary_nic" name="beneficiary_nic" type="text" value="{{ old('beneficiary_nic', $c->beneficiary_nic) }}" class="input">
                            </div>
                            <div>
                                <label for="beneficiary_date_of_birth" class="label">Date of birth</label>
                                <input id="beneficiary_date_of_birth" name="beneficiary_date_of_birth" type="date" max="{{ now()->toDateString() }}"
                                    value="{{ old('beneficiary_date_of_birth', optional($c->beneficiary_date_of_birth)->toDateString()) }}" class="input">
                            </div>
                            <div>
                                <label for="beneficiary_phone" class="label">Phone</label>
                                <input id="beneficiary_phone" name="beneficiary_phone" type="text" value="{{ old('beneficiary_phone', $c->beneficiary_phone) }}" class="input">
                            </div>
                        </div>
                    </div>

                    <div class="flex justify-end">
                        <button type="submit" class="btn-primary">Save profile details</button>
                    </div>
                </form>
            </div>
        @elseif ($user->consultant)
            {{-- READ-ONLY, NOT ABSENT (2026-09-22). This panel used to be one
                 sentence saying the details existed and only a Super Admin
                 could change them — so an admin could not even LOOK at the
                 profile of a user they were working on: *"User Management eke
                 thiyen user lage profile eka view krnn puluwn widiyt hadann"*.
                 Editing stays Super Admin only; reading does not. --}}
            @php
                $c = $user->consultant;
                $profileSections = [
                    'Personal information' => [
                        'NIC / Passport' => $c->nic,
                        'Date of birth' => optional($c->date_of_birth)->format('M d, Y'),
                        'Gender' => $c->gender ? \Illuminate\Support\Str::headline($c->gender) : null,
                        'Address' => $c->address,
                    ],
                    'Bank details' => [
                        'Bank' => $c->bank_name,
                        'Account name' => $c->bank_account_name,
                        'Account number' => $c->bank_account_number,
                        'Branch' => $c->bank_branch,
                        'SWIFT code' => $c->swift_code,
                    ],
                    'Beneficiary details' => [
                        'Full name' => $c->beneficiary_name,
                        'Relationship' => $c->beneficiary_relationship,
                        'NIC / Passport' => $c->beneficiary_nic,
                        'Date of birth' => optional($c->beneficiary_date_of_birth)->format('M d, Y'),
                        'Phone' => $c->beneficiary_phone,
                    ],
                ];
            @endphp
            <div class="card p-6 lg:p-8">
                <h3 class="text-lg font-bold text-slate-900 dark:text-white">Profile details</h3>
                <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">
                    This consultant's own details, as they submitted them — they can only be edited by a Super Admin.
                </p>

                <div class="mt-6 space-y-6">
                    @foreach ($profileSections as $section => $fields)
                        <div @class(['border-t border-slate-100 pt-6 dark:border-slate-800' => ! $loop->first])>
                            <h4 class="text-sm font-bold uppercase tracking-wide text-slate-400">{{ $section }}</h4>
                            <dl class="mt-3 grid gap-x-6 gap-y-4 sm:grid-cols-2">
                                @foreach ($fields as $label => $value)
                                    <div>
                                        <dt class="text-xs text-slate-400">{{ $label }}</dt>
                                        {{-- An em dash rather than a blank, so a missing
                                             detail reads as missing and not as a broken row. --}}
                                        <dd class="text-sm text-slate-900 dark:text-white">{{ filled($value) ? $value : '—' }}</dd>
                                    </div>
                                @endforeach
                            </dl>
                        </div>
                    @endforeach

                    @if ($c->account_proof_path)
                        <div class="border-t border-slate-100 pt-6 dark:border-slate-800">
                            <a href="{{ route('media.show', $c->account_proof_path) }}" target="_blank"
                                class="text-sm font-medium text-brand-600 hover:underline dark:text-brand-400">View the bank document on file</a>
                        </div>
                    @endif
                </div>
            </div>
        @endif

        {{-- The STUDENT side of the same person. Every consultant registered
             through the site also has a student row, and until now none of it
             was on this page at all — an admin checking a registration had to
             open another screen for the NIC or the district. --}}
        @if ($user->student)
            @php
                $s = $user->student;
                $studentFields = [
                    'Student number' => $s->student_code,
                    'NIC / Passport' => $s->nic,
                    'Date of birth' => optional($s->date_of_birth)->format('M d, Y'),
                    'Gender' => $s->gender ? \Illuminate\Support\Str::headline($s->gender) : null,
                    'Phone' => $user->phone,
                    'Address' => $s->address,
                    'City' => $s->city,
                    'District' => $s->district,
                    'Guardian' => $s->guardian_name,
                    'Guardian phone' => $s->guardian_phone,
                    'Education level' => $s->education_level,
                    'Language' => $s->language,
                    'Class type' => $s->class_type,
                    'Course type' => $s->course_type,
                    'Registered' => optional($s->created_at)->format('M d, Y'),
                    'Status' => \Illuminate\Support\Str::headline($s->status ?? ''),
                ];
            @endphp
            <div class="card p-6 lg:p-8">
                <h3 class="text-lg font-bold text-slate-900 dark:text-white">Registration details</h3>
                <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">What this person gave when they registered as a student.</p>
                <dl class="mt-4 grid gap-x-6 gap-y-4 sm:grid-cols-2">
                    @foreach ($studentFields as $label => $value)
                        <div>
                            <dt class="text-xs text-slate-400">{{ $label }}</dt>
                            <dd class="text-sm text-slate-900 dark:text-white">{{ filled($value) ? $value : '—' }}</dd>
                        </div>
                    @endforeach
                </dl>
                @if ($s->no_objection_letter_path)
                    {{-- ⚠️ STATED, NOT LINKED. media.show is an unauthenticated
                         allowlist route (see MediaController) and this letter
                         carries somebody else's NIC — adding its folder to that
                         allowlist would put it behind a guessable public URL. --}}
                    <p class="mt-4 text-sm text-slate-500 dark:text-slate-400">A no-objection letter is on file for this registration.</p>
                @endif
            </div>
        @endif

            </div>
        </div>

        {{-- ⚠️ THE CONSULTANT'S OWN SERVICE GROUPS PAGE, ON THE ADMIN'S SCREEN
             (2026-09-23). *"consultant ge service group eka, eyata service
             income … user management eken userge edit page ekat awaham"* — an
             admin answering a consultant's question about their money had to
             log in as them to see these figures.

             Every number is produced by the SAME service the consultant's page
             calls (see UserManagementController::serviceGroupSnapshot), so the
             two screens cannot disagree about one account in the same minute. --}}
        @if ($serviceGroups)
            <div class="card p-6 lg:p-8">
                <div class="flex flex-wrap items-start justify-between gap-4">
                    <div>
                        <h3 class="text-lg font-bold text-slate-900 dark:text-white">Service Groups</h3>
                        <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">
                            What this consultant sees on their own Service Groups page — the balance sitting on each leg right now.
                        </p>
                    </div>
                    <div class="grid grid-cols-2 gap-6 sm:text-right">
                        <div>
                            <p class="text-xs uppercase tracking-wide text-slate-400">SG1 Leg Balance</p>
                            <p class="mt-1 text-lg font-bold text-slate-900 dark:text-white">LKR {{ number_format($serviceGroups['leg1']) }}</p>
                        </div>
                        <div>
                            <p class="text-xs uppercase tracking-wide text-slate-400">SG2 Leg Balance</p>
                            <p class="mt-1 text-lg font-bold text-slate-900 dark:text-white">LKR {{ number_format($serviceGroups['leg2']) }}</p>
                        </div>
                    </div>
                </div>

                {{-- Service Income: run-released slot money plus direct bonuses —
                     the Dashboard's figure, not consultants.total_earned. --}}
                <div class="mt-5 grid gap-3 sm:grid-cols-3">
                    @foreach ([
                        'Service income earned' => $serviceGroups['income']['earned'],
                        'Paid out' => $serviceGroups['income']['paid'],
                        'Unpaid' => $serviceGroups['income']['pending'],
                    ] as $label => $amount)
                        <div class="rounded-xl bg-slate-50 p-4 dark:bg-slate-800/50">
                            <p class="text-xs uppercase tracking-wide text-slate-400">{{ $label }}</p>
                            <p class="mt-1 font-bold text-slate-900 dark:text-white">LKR {{ number_format($amount, 2) }}</p>
                        </div>
                    @endforeach
                </div>
                <p class="mt-2 text-xs text-slate-400">Service income counts what a System Run has released, plus direct add bonuses — the same rule as their Dashboard.</p>

                <div class="mt-5 overflow-x-auto">
                    <table class="w-full min-w-[36rem] text-left text-sm">
                        <thead class="text-[11px] uppercase text-slate-400">
                            <tr>
                                <th class="py-2 pr-4 font-semibold">Account</th>
                                <th class="py-2 pr-4 font-semibold">SG1 Leg Balance</th>
                                <th class="py-2 font-semibold">SG2 Leg Balance</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100 dark:divide-slate-800">
                            @foreach ($serviceGroups['rows'] as $row)
                                <tr>
                                    <td class="py-2.5 pr-4">
                                        <span class="font-mono text-sm font-semibold text-slate-900 dark:text-white">{{ $row['code'] }}</span>
                                        @if ($row['is_main'])
                                            <span class="badge ml-1 bg-slate-100 text-slate-700 dark:bg-slate-800 dark:text-slate-200">Main</span>
                                        @endif
                                        @if (! $row['is_main'] && $row['under'])
                                            <span class="block text-xs text-slate-400">
                                                Inside <span class="font-mono">{{ $row['under'] }}</span>@if ($row['owner_group']) · Service Group {{ $row['owner_group'] }}@endif
                                            </span>
                                        @endif
                                        {{-- A shut account reads zero for a reason; saying so here
                                             is what stops "his money vanished" questions. --}}
                                        @if ($row['locked_manually'])
                                            <span class="block text-xs font-medium text-rose-600 dark:text-rose-400">Closed by an administrator</span>
                                        @elseif ($row['locked'])
                                            <span class="block text-xs text-amber-600 dark:text-amber-400">Locked — waiting on the previous account</span>
                                        @endif
                                    </td>
                                    @foreach ([1, 2] as $leg)
                                        <td class="py-2.5 @if ($leg === 1) pr-4 @endif">
                                            <span class="font-semibold text-slate-900 dark:text-white">LKR {{ number_format($row['leg'.$leg]) }}</span>
                                            @if (($row['after_run'][$leg] ?? 0) > 0 && $serviceGroups['last_run'])
                                                <span class="block text-xs text-slate-400">
                                                    LKR {{ number_format($row['after_run'][$leg]) }} came in after the last System Run (settled up to {{ $serviceGroups['last_run'] }}) — it settles at the next run
                                                </span>
                                            @endif
                                        </td>
                                    @endforeach
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>
        @endif

        {{-- Service Group accounts — the manual unlock override.
             Loaded live from the consultant's own account chain, so an account
             opened a moment ago on another screen shows up here immediately. --}}
        @if ($scnAccounts->isNotEmpty())
            <div class="card p-6 lg:p-8">
                <h3 class="text-lg font-bold text-slate-900 dark:text-white">Service Group accounts</h3>
                <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">
                    Placement accounts normally open in order — each one unlocks once the previous account reaches
                    {{ $scnAccounts->first()['unlock_at'] }} students. Override that here when an account needs to be opened early, or held shut.
                    A locked account earns no group service income; the direct add bonus is unaffected.
                </p>

                {{-- Two columns from lg up: with 9+ accounts a single stack
                     runs several screens deep and the page is mostly empty on
                     both sides. --}}
                <div class="mt-5 grid gap-3 lg:grid-cols-2">
                    @foreach ($scnAccounts as $acc)
                        <div id="acc-{{ $acc["model"]->id }}" class="rounded-xl border border-slate-200 p-4 dark:border-slate-700">
                            <div class="flex flex-wrap items-start justify-between gap-3">
                                <div class="min-w-0">
                                    <p class="font-mono text-sm font-bold text-slate-900 dark:text-white">
                                        {{ $acc['code'] }}
                                        @if ($acc['is_main'])
                                            <span class="badge ml-1 bg-slate-100 text-slate-700 dark:bg-slate-800 dark:text-slate-200">Main</span>
                                        @else
                                            <span class="badge ml-1 bg-slate-100 text-slate-600 dark:bg-slate-800 dark:text-slate-300">Placement</span>
                                        @endif
                                    </p>
                                    <p class="mt-1 text-xs text-slate-500 dark:text-slate-400">
                                        {{ number_format($acc['students']) }} student(s) placed
                                    </p>
                                </div>

                                @if ($acc['unlocked'])
                                    <span class="badge bg-emerald-50 text-emerald-700 dark:bg-emerald-500/10 dark:text-emerald-400">Unlocked</span>
                                @else
                                    <span class="badge bg-amber-50 text-amber-700 dark:bg-amber-500/10 dark:text-amber-400">Locked</span>
                                @endif
                            </div>

                            <p class="mt-2 text-xs text-slate-500 dark:text-slate-400">
                                @if ($acc['is_main'])
                                    A consultant's own account is always open — it is never gated by the unlock rule and cannot be locked.
                                @elseif ($acc['override'] === \App\Models\Consultant::LOCK_FORCED_OPEN)
                                    Manually unlocked by an admin{{ $acc['model']->manual_lock_at ? ' on '.$acc['model']->manual_lock_at->format('M d, Y') : '' }} — the student rule is being ignored for this account.
                                @elseif ($acc['override'] === \App\Models\Consultant::LOCK_FORCED_SHUT)
                                    Manually locked by an admin{{ $acc['model']->manual_lock_at ? ' on '.$acc['model']->manual_lock_at->format('M d, Y') : '' }} — it will not open on its own.
                                @elseif ($acc['unlocked'])
                                    Opened automatically by the student rule.
                                @else
                                    Waiting on the previous account to reach {{ $acc['unlock_at'] }} students.
                                @endif
                            </p>

                            {{-- Who is seated on each leg of THIS account (2026-09-22).
                                 Names, never a money figure: the leg balance belongs to
                                 the Service Groups screen and the release ladder below,
                                 and a second number for the same thing would drift. --}}
                            <div class="mt-3 grid gap-2 sm:grid-cols-2">
                                @foreach ([1, 2] as $leg)
                                    @php $members = $acc['legs'][$leg] ?? []; @endphp
                                    <div class="rounded-lg bg-slate-50 p-3 dark:bg-slate-800/50">
                                        <p class="text-xs font-semibold text-slate-600 dark:text-slate-300">
                                            Service Group {{ $leg }}
                                            <span class="font-normal text-slate-400">· {{ count($members) }} seated</span>
                                        </p>
                                        @if ($members === [])
                                            <p class="mt-1 text-xs text-slate-400">Nobody on this leg yet.</p>
                                        @else
                                            <ul class="mt-1 space-y-0.5">
                                                @foreach ($members as $member)
                                                    <li class="truncate text-xs text-slate-600 dark:text-slate-300">
                                                        {{ $member['name'] }}
                                                        @if ($member['joined_at'])
                                                            <span class="text-slate-400">· {{ $member['joined_at']->format('M d') }}</span>
                                                        @endif
                                                    </li>
                                                @endforeach
                                            </ul>
                                        @endif
                                    </div>
                                @endforeach
                            </div>

                            @unless ($acc['is_main'])
                                <form method="POST" action="{{ route('users.account-lock', $acc['model']) }}" data-inline="1" class="mt-3 flex flex-wrap items-end gap-3">
                                    @csrf @method('PATCH')
                                    <div class="min-w-[12rem] flex-1">
                                        <label for="state-{{ $acc['model']->id }}" class="label">Access</label>
                                        <select id="state-{{ $acc['model']->id }}" name="state" class="input">
                                            <option value="auto" @selected($acc['override'] === null)>Automatic ({{ $acc['unlock_at'] }}-student rule)</option>
                                            <option value="{{ \App\Models\Consultant::LOCK_FORCED_OPEN }}" @selected($acc['override'] === \App\Models\Consultant::LOCK_FORCED_OPEN)>Unlocked (override)</option>
                                            <option value="{{ \App\Models\Consultant::LOCK_FORCED_SHUT }}" @selected($acc['override'] === \App\Models\Consultant::LOCK_FORCED_SHUT)>Locked (override)</option>
                                        </select>
                                    </div>
                                    <button type="submit" class="btn-secondary">Apply</button>
                                </form>
                            @endunless

                            {{-- The staged release ladder. Offered on EVERY account
                                 including the main one — unlike the lock, every
                                 account earns group income and so has its own
                                 stage. --}}
                            @php
                                // 1-based for the reader; stage 0 stored = "Stage 1" next.
                                $currentStage = $acc['stage'] + 1;
                            @endphp
                            <div class="mt-3 border-t border-slate-100 pt-3 dark:border-slate-800">
                                <p class="text-xs text-slate-500 dark:text-slate-400">
                                    Release stage:
                                    <span class="font-semibold text-slate-700 dark:text-slate-200">{{ $acc['stage_label'] }}</span>
                                    — needs LKR {{ number_format($acc['stage_threshold']) }} on each side, releases LKR {{ number_format($acc['stage_payout']) }}.
                                    @if ($currentStage >= 3)
                                        <span class="text-slate-400">This is the repeating stage.</span>
                                    @endif
                                </p>

                                @if ($currentStage < 3)
                                    <form method="POST" action="{{ route('users.account-stage', $acc['model']) }}" data-inline="1" class="mt-2 flex flex-wrap items-end gap-3">
                                        @csrf @method('PATCH')
                                        <div class="min-w-[12rem] flex-1">
                                            <label for="stage-{{ $acc['model']->id }}" class="label">Move to stage</label>
                                            {{-- Only stages at or ahead of the current one are
                                                 offered: a used stage can never be handed back,
                                                 or this becomes an unlimited money tap. The
                                                 controller enforces this too. --}}
                                            <select id="stage-{{ $acc['model']->id }}" name="stage" class="input">
                                                @foreach ([1 => '1-to-1 · 5,000', 2 => '2-to-2 · 10,000', 3 => '3-to-3 · 15,000 (repeating)'] as $value => $label)
                                                    @if ($value >= $currentStage)
                                                        <option value="{{ $value }}" @selected($value === $currentStage)>Stage {{ $value }} — {{ $label }}</option>
                                                    @endif
                                                @endforeach
                                            </select>
                                        </div>
                                        <button type="submit" class="btn-secondary">Apply</button>
                                    </form>
                                    <p class="mt-1 text-xs text-slate-400">Moves forward only — a stage that has been used cannot be given back.</p>
                                @endif
                            </div>
                        </div>
                    @endforeach
                </div>
            </div>
        @endif


    {{-- Apply without losing the page.
         With nine or more accounts, every Apply reloaded the whole screen and
         threw away the scroll position, so setting three accounts meant three
         reloads and three scrolls back down. These post in the background and
         report next to the button that was pressed.

         ⚠️ Vanilla JS on purpose — Alpine is not auto-injected in this build
         (Livewire v4 is bundled manually), so a page that quietly depended on
         it would work locally and do nothing live.

         ⚠️ It degrades to a normal form post: if fetch or JSON fails for any
         reason the handler re-submits the form the ordinary way, so the action
         is never silently lost. --}}
    <script>
        (function () {
            var forms = document.querySelectorAll('form[data-inline="1"]');

            forms.forEach(function (form) {
                form.addEventListener('submit', function (e) {
                    e.preventDefault();

                    var button = form.querySelector('button[type="submit"]');
                    var original = button ? button.textContent : '';
                    var note = form.querySelector('.inline-note');

                    if (!note) {
                        note = document.createElement('span');
                        note.className = 'inline-note text-xs';
                        form.appendChild(note);
                    }

                    if (button) {
                        button.disabled = true;
                        button.textContent = 'Saving…';
                    }
                    note.textContent = '';

                    fetch(form.action, {
                        method: 'POST',
                        headers: {
                            'X-Requested-With': 'XMLHttpRequest',
                            'Accept': 'application/json',
                        },
                        body: new FormData(form),
                        credentials: 'same-origin',
                    })
                        .then(function (r) { return r.ok ? r.json() : Promise.reject(r); })
                        .then(function (data) {
                            note.textContent = data.message || 'Saved.';
                            note.className = 'inline-note text-xs ' + (data.ok
                                ? 'text-emerald-600 dark:text-emerald-400'
                                : 'text-rose-600 dark:text-rose-400');

                            if (data.card) {
                                var card = document.getElementById('acc-' + data.consultant_id);

                                if (card) {
                                    card.innerHTML = data.card;
                                }
                            }
                        })
                        .catch(function () {
                            // Something is wrong with the background request —
                            // fall back to the plain post rather than leaving
                            // the admin unsure whether it saved.
                            form.removeEventListener('submit', arguments.callee);
                            form.submit();
                        })
                        .finally(function () {
                            if (button) {
                                button.disabled = false;
                                button.textContent = original;
                            }
                        });
                });
            });
        })();
    </script>
</x-layouts.app>
