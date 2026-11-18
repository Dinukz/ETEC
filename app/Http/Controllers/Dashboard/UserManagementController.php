<?php

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use App\Models\Consultant;
use App\Models\Course;
use App\Models\Student;
use App\Models\User;
use App\Services\LecturerCourses;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Spatie\Permission\Models\Role;

class UserManagementController extends Controller
{
    /** Roles an admin may assign. Super Admin can additionally assign super_admin. */
    protected function assignableRoles(): array
    {
        // group_supporter (2026-09-17): Payments (view only). Admission Report removed 2026-10-05.
        $roles = ['admin', 'accountant', 'lecturer', 'group_supporter', 'consultant', 'student'];

        if (auth()->user()->isSuperAdmin()) {
            array_unshift($roles, 'super_admin');
        }

        return $roles;
    }

    /**
     * Make sure a role exists before it is assigned.
     *
     * ⚠️ The host has no CLI, so a role added after go-live (group_supporter,
     * 2026-09-17) is never seeded there — assignRole() would throw
     * RoleDoesNotExist on the first user given it. Idempotent.
     */
    protected function ensureRoleExists(string $role): void
    {
        Role::findOrCreate($role, 'web');
        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function index(Request $request)
    {
        $roles = Role::orderBy('name')->pluck('name');

        // Accounts whose email can only bounce (2026-11-10) — the "Mistyped
        // emails" button and filter. See mistypedEmailIds().
        $mistyped = $this->mistypedEmailIds();

        $users = User::query()
            // consultant + student for the SCN / SRN column — see User::roleCode().
            ->with(['roles', 'consultant', 'student'])
            ->when($request->filled('q'), function ($query) use ($request) {
                $term = trim((string) $request->string('q'));

                // ⚠️ SCN / SRN SEARCH (2026-09-17): "Consultant ekenk nm SCN numbers
                // tiken search wenn one student kenk nm SRN number eken". A
                // placement account's SCN (SCN-00230) has no login of its own, so
                // it finds the person who owns it.
                $query->where(fn ($q) => $q
                    ->where('name', 'like', "%{$term}%")
                    ->orWhere('email', 'like', "%{$term}%")
                    ->orWhereHas('student', fn ($s) => $s->where('student_code', 'like', "%{$term}%"))
                    ->orWhereHas('consultant', fn ($c) => $c
                        ->where('scn_code', 'like', "%{$term}%")
                        ->orWhere('consultant_code', 'like', "%{$term}%")
                        ->orWhereHas('hiddenAccounts', fn ($h) => $h->where('scn_code', 'like', "%{$term}%"))));
            })
            ->when($request->filled('role'), fn ($q) => $q->role($request->string('role')->toString()))
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')))
            ->when($request->input('email') === 'mistyped', fn ($q) => $q->whereIn('id', $mistyped))
            ->latest()
            ->paginate(self::perPage($request))
            ->withQueryString();

        return view('dashboard.users.index', [
            'users' => $users,
            'mistypedEmails' => count($mistyped),
            'roles' => $roles,
            'assignableRoles' => $this->assignableRoles(),
            'perPage' => self::perPage($request),
            'perPageOptions' => self::PER_PAGE_OPTIONS,
        ]);
    }

    /** How many rows a page may show. Anything else is ignored. */
    public const PER_PAGE_OPTIONS = [10, 50, 100, 500, 1000];

    /**
     * Every account whose email is certainly mistyped (2026-11-10) — the ones
     * whose mail bounces and got the site's mailbox suspended. See
     * App\Support\EmailAddressCheck. Worked out in PHP over one column: about
     * 6,000 addresses.
     *
     * @return array<int>
     */
    protected function mistypedEmailIds(): array
    {
        return User::query()->pluck('email', 'id')
            ->filter(fn ($email) => \App\Support\EmailAddressCheck::problem($email) !== null)
            ->keys()->map(fn ($id) => (int) $id)->all();
    }

    /**
     * Answer an Apply that came from the Edit User screen.
     *
     * ⚠️ ONE RETURN FOR BOTH CALLERS. The Service Group cards post in the
     * background so an admin setting nine accounts does not reload and re-scroll
     * nine times — but the same action must still work as a plain form post
     * (the page falls back to one if fetch fails, and the browser will too with
     * JavaScript off). So the flash message is identical either way; only the
     * envelope differs.
     */
    protected function applied(Request $request, string $message, bool $ok = true)
    {
        if ($request->expectsJson() || $request->ajax()) {
            return response()->json([
                'ok' => $ok,
                'message' => $message,
            ]);
        }

        return back()->with($ok ? 'success' : 'error', $message);
    }

    /**
     * ⚠️ ALLOW-LISTED, not taken from the query string. `?per_page=999999`
     * would otherwise load every user and their roles into memory in one go.
     */
    protected static function perPage(Request $request): int
    {
        $asked = (int) $request->query('per_page');

        return in_array($asked, self::PER_PAGE_OPTIONS, true) ? $asked : 50;
    }

    /**
     * ⚠️ ACCOUNTS THAT CARRY NO MONEY — the screen, which deletes nothing.
     *
     * The numbering had climbed to SCN-00229 because roughly a hundred test
     * identities were holding numbers, and the live site shares this database
     * with them. So this does not guess what is "test data": it shows the
     * financial footprint of every account and lets a person choose, with the
     * evidence beside each name. See DisposableAccountAudit.
     *
     * Submitting goes to bulkDestroy(), which re-checks its own rules per user.
     */
    public function cleanup(Request $request, \App\Services\DisposableAccountAudit $audit)
    {
        abort_unless($request->user()?->hasRole('super_admin'), 403, 'Only a super admin can review accounts for deletion.');

        return view('dashboard.users.cleanup', $audit->run($request->user()->id));
    }

    /**
     * Put the number pool back to the lowest free number.
     *
     * ⚠️ Cannot cause a collision — it recomputes from the codes the two tables
     * actually hold. On a database that still has real consultants it will not
     * drop to 100, and that is correct. See NumberPool::recompute().
     */
    public function recomputeNumbering(Request $request)
    {
        abort_unless($request->user()?->hasRole('super_admin'), 403, 'Only a super admin can reset the numbering.');

        ['from' => $from, 'to' => $to] = \App\Services\NumberPool::recompute();

        if ($from === $to) {
            return back()->with('success', 'Numbering already starts at '.\App\Services\NumberPool::format('SCN', $to).' — nothing to reclaim.');
        }

        return back()->with('success', sprintf(
            'Numbering moved from %s back to %s. The next student is %s.',
            \App\Services\NumberPool::format('SCN', $from),
            \App\Services\NumberPool::format('SCN', $to),
            \App\Services\NumberPool::format('SRN', $to),
        ));
    }

    /**
     * Delete several users at once.
     *
     * ⚠️ Runs each one through destroy()'s rules rather than a mass delete:
     * you cannot remove yourself, only a super admin may remove a super admin,
     * the last super admin is protected, and a consultant's hidden placement
     * accounts have to go with them. A bulk DELETE query would skip every one
     * of those and orphan the SCN accounts.
     */
    public function bulkDestroy(Request $request)
    {
        $data = $request->validate([
            'ids' => ['required', 'array', 'min:1'],
            'ids.*' => ['integer'],
        ]);

        $deleted = 0;
        $skipped = [];

        // 'consultant' eager-loaded because the hidden-account cleanup below
        // reaches through it — Model::preventLazyLoading() is active outside
        // production and would throw rather than quietly N+1.
        foreach (User::whereIn('id', $data['ids'])->with(['roles', 'consultant'])->get() as $user) {
            if ($user->id === auth()->id()) {
                $skipped[] = $user->name.' (you cannot delete yourself)';

                continue;
            }

            if ($user->hasRole('super_admin')) {
                if (! auth()->user()->isSuperAdmin()) {
                    $skipped[] = $user->name.' (only a super admin can remove a super admin)';

                    continue;
                }

                if (User::role('super_admin')->count() <= 1) {
                    $skipped[] = $user->name.' (the last super admin)';

                    continue;
                }
            }

            // Same rule as destroy(): the branch below a leaver is re-hung on
            // the leaver's own sponsor, and a deletion that cannot be healed
            // is skipped rather than silently cutting the tree.
            if ($user->consultant) {
                $stranded = \App\Services\UplineContinuity::wouldStrand($user->consultant);

                if ($stranded > 0) {
                    $skipped[] = $user->name." ({$stranded} consultant(s) below them, and no upline to move them to)";

                    continue;
                }

                \App\Services\UplineContinuity::liftDownlineOf($user->consultant);
            }

            $user->consultant?->hiddenAccounts()->get()->each->delete();
            $user->forceDelete();
            $deleted++;
        }

        $message = $deleted === 1 ? '1 user permanently deleted.' : "{$deleted} users permanently deleted.";

        if ($skipped !== []) {
            return back()->with('success', $message.' Skipped: '.implode('; ', $skipped).'.');
        }

        return back()->with('success', $message);
    }

    public function create()
    {
        LecturerCourses::heal();

        return view('dashboard.users.create', [
            'assignableRoles' => $this->assignableRoles(),
            // A new lecturer can be given their courses straight away (2026-10-25).
            'courses' => Course::orderBy('title')->get(['id', 'title', 'lecturer_id']),
        ]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email', 'max:180', 'unique:users,email', new \App\Rules\RealEmailAddress],
            'phone' => ['nullable', 'string', 'max:40'],
            'role' => ['required', Rule::in($this->assignableRoles())],
            'password' => ['required', 'confirmed', Password::defaults()],
            'status' => ['required', 'in:active,inactive,suspended'],
            // A lecturer's courses (2026-10-25) — see LecturerCourses.
            'course_ids' => ['nullable', 'array'],
            'course_ids.*' => ['integer', 'exists:courses,id'],
        ]);

        $this->ensureRoleExists($data['role']);

        // Before the transaction — creating the table must not run inside one.
        LecturerCourses::heal();

        $user = DB::transaction(function () use ($data) {
            $user = User::create([
                'name' => $data['name'],
                'email' => $data['email'],
                'phone' => $data['phone'] ?? null,
                'password' => Hash::make($data['password']),
                'status' => $data['status'],
                'email_verified_at' => now(), // admin-created accounts are pre-verified
            ]);

            $user->assignRole($data['role']);
            $this->syncProfile($user, $data['role']);

            return $user;
        });

        if ($data['role'] === 'lecturer' && ! empty($data['course_ids'])) {
            LecturerCourses::sync($user->id, $data['course_ids'], auth()->id());
        }

        // Send login credentials — outside the transaction, and never allowed
        // to fail the request: the account already exists at this point, and
        // an email outage must not surface as if account creation failed.
        // The PLAIN-TEXT password only exists in $data (the request payload)
        // at this exact moment — it is never stored anywhere and cannot be
        // recovered later, so this is the only chance to send it.
        $emailed = false;

        try {
            $emailed = \App\Services\TransactionalMail::accountCreated(
                $data['email'], $data['name'], \Illuminate\Support\Str::headline($data['role']), $data['password'],
            );
        } catch (\Throwable $e) {
            report($e);
        }

        $message = "User \"{$data['name']}\" created successfully.";
        $message .= $emailed
            ? ' Login details have been emailed to them.'
            : ' (The login-details email could not be sent — check Settings → Email, or share their password with them directly.)';

        return redirect()->route('users.index')->with('success', $message);
    }

    public function edit(User $user)
    {
        // The downline-report panel below reads a self-healing column, so make
        // sure it exists before the page asks for it. Pre-transaction and
        // idempotent — see FinanceSchemaRepair's docblock.
        \App\Services\FinanceSchemaRepair::ensure();

        return view('dashboard.users.edit', [
            // 'consultant' eager-loaded for the downline-report panel:
            // preventLazyLoading() is active outside production, so reading it
            // in the view would 500 the page rather than fire a second query.
            // 'student' joined the eager loads for the profile panels below.
            'user' => $user->load(['roles', 'consultant', 'student']),
            'assignableRoles' => $this->assignableRoles(),
            // Their Service Group (SCN) accounts, so an admin who searched for
            // this consultant can act on the accounts from here rather than
            // hunting for them on another screen.
            'scnAccounts' => $this->scnAccountsFor($user),
            // Every SCN this person holds, main first — see scnCodesFor().
            'scnCodes' => $this->scnCodesFor($user),
            // The pending registration a re-send would chase, or null.
            'resendPayment' => $this->resendablePaymentFor($user),
            // What this consultant sees on their own Service Groups page.
            'serviceGroups' => $this->serviceGroupSnapshot($user),
            // A lecturer's courses (2026-10-25): every course, and the ones given to them.
            'courses' => $user->hasRole('lecturer') ? Course::orderBy('title')->get(['id', 'title', 'lecturer_id']) : collect(),
            'assignedCourseIds' => $user->hasRole('lecturer') ? LecturerCourses::assignedIds($user->id) : [],
        ]);
    }

    /**
     * The consultant's own Service Groups page, as an admin needs to read it.
     *
     * Asked for 2026-09-23: *"mata awshya wenne consultant ge service group eka,
     * eyata service income … user management eken userge edit page ekat
     * awaham"*. The earlier change put the SEATS on this page; the numbers the
     * owner was actually after are the LEG BALANCES and the settled income.
     *
     * ⚠️ THE SAME SERVICES THE CONSULTANT'S OWN PAGE CALLS, NOT A SECOND
     * ARITHMETIC. ServiceGroupFlush::state() produces the very figures
     * consultant/groups/index.blade.php prints (`leg1_total` / `leg2_total` are
     * its "SG1 / SG2 Leg Balance"), arrivedAfterRun() produces its "came in
     * after the last System Run" line, and SettledIncome::totals() is what the
     * Dashboard and Service Income both read. A separate calculation here would
     * be a second answer to a money question, which is exactly how this project
     * has produced contradictions before.
     *
     * Read-only: nothing here flushes, approves, places or credits anything.
     *
     * @return array<string, mixed>|null  null when the user holds no SCN account
     */
    protected function serviceGroupSnapshot(User $user): ?array
    {
        $consultant = $user->consultant;

        if (! $consultant) {
            return null;
        }

        $ordered = $consultant->orderedAccountIds();

        // 'owner' eager-loaded exactly as the consultant's own page does: the
        // flush state reads it, and preventLazyLoading turns that into a 500
        // rather than a quiet extra query.
        $accounts = Consultant::with('owner')
            ->whereIn('id', $ordered)
            ->get()
            ->sortBy(fn ($a) => array_search((int) $a->id, $ordered, true));

        $flusher = app(\App\Services\ServiceGroupFlush::class);
        $lastRun = \App\Services\ServiceGroupFlush::lastRun();
        $cutoff = \App\Services\ServiceGroupFlush::defaultCutoff();

        // Where each account hangs and which of the owner's two Service Groups
        // it sits in — by CLIMBING, never by ordinal (AccountPlacementMap's own
        // docblock forbids the swap).
        $placements = app(\App\Services\AccountPlacementMap::class)->for($consultant);

        $rows = $accounts->map(function ($account) use ($flusher, $lastRun, $cutoff, $placements, $consultant) {
            $group = \App\Models\Group::where('consultant_id', $account->id)->orderBy('id')->first();
            $state = $flusher->state($account, $group, $cutoff);

            return [
                'code' => $account->scn_code ?: $account->consultant_code,
                'is_main' => (int) $account->id === (int) $consultant->id,
                'under' => $placements[$account->id]['under_code'] ?? null,
                'owner_group' => $placements[$account->id]['owner_group'] ?? null,
                'leg1' => (float) ($state['leg1_total'] ?? 0),
                'leg2' => (float) ($state['leg2_total'] ?? 0),
                'locked' => (bool) ($state['locked'] ?? false),
                'locked_manually' => (bool) ($state['locked_manually'] ?? false),
                'after_run' => ($lastRun && $group) ? [
                    1 => (float) $flusher->arrivedAfterRun($account, $group, 1, $lastRun),
                    2 => (float) $flusher->arrivedAfterRun($account, $group, 2, $lastRun),
                ] : [1 => 0.0, 2 => 0.0],
            ];
        })->values();

        $main = $rows->firstWhere('is_main', true);

        return [
            'code' => $consultant->scn_code ?: $consultant->consultant_code,
            'rows' => $rows->all(),
            // The headline pair, read off the main account exactly as the
            // consultant's own page does.
            'leg1' => (float) ($main['leg1'] ?? 0),
            'leg2' => (float) ($main['leg2'] ?? 0),
            'last_run' => $lastRun?->cutoffLabel(),
            // Service Income — run-released slot money plus direct bonuses.
            'income' => \App\Services\SettledIncome::totals($consultant->accountIds()),
        ];
    }

    /**
     * Every Service Group account this user owns, in unlock order, each with
     * its current lock state and WHY.
     *
     * Read fresh on every request rather than cached anywhere, so an account
     * opened moments ago on another screen (HiddenAccountService::addExtraAccount)
     * appears here immediately — asked for explicitly 2026-08-17 ("thaw eyage
     * new craete krapu account thiyeno nm ewath load wenn one").
     *
     * @return \Illuminate\Support\Collection<int, array<string, mixed>>
     */
    /**
     * Every SCN code this person holds, main account first.
     *
     * Uses orderedAccountIds() rather than accountIds(): the latter is only a
     * SET for rolling figures up, while this is a list a human reads, so the
     * main must come first and the placements follow in the order they opened.
     *
     * @return array<int, string>
     */
    protected function scnCodesFor(User $user): array
    {
        $consultant = $user->consultant;

        if (! $consultant) {
            return [];
        }

        $ids = $consultant->orderedAccountIds();

        return Consultant::whereIn('id', $ids)
            ->get(['id', 'scn_code', 'consultant_code'])
            ->sortBy(fn ($account) => array_search($account->id, $ids, true))
            ->map(fn ($account) => $account->scn_code ?? $account->consultant_code)
            ->filter()
            ->values()
            ->all();
    }

    /**
     * The still-pending registration an admin could chase up, or null.
     *
     * The reminder quotes a payment code and an expiry date, so it needs a real
     * pending Payment — there is nothing to re-send for a user with no unpaid
     * registration, and the button says so rather than failing when pressed.
     */
    protected function resendablePaymentFor(User $user): ?\App\Models\Payment
    {
        return \App\Models\Payment::where('user_id', $user->id)
            ->where('status', 'pending')
            ->with('course')
            ->latest('id')
            ->first();
    }

    /**
     * A super admin corrects a consultant's Personal / Bank / Beneficiary
     * details from the Edit User screen.
     *
     * ⚠️ SUPER ADMIN ONLY, AND CHECKED HERE. The whole User Management route
     * group is role:super_admin|admin, so an admin-only rule has to live in the
     * method — same idiom as cleanup() and recomputeNumbering(). These are the
     * bank details money is paid into; an ordinary admin may not touch them.
     *
     * ⚠️ THE RULES ARE DELIBERATELY NOT ProfileController'S. Those were made
     * required-across-the-board at go-live, which is right for a consultant
     * completing their own profile and wrong here: an admin fixing one typo in
     * an account number would be forced to re-enter every other field, and a
     * half-filled profile could never be corrected at all. Everything is
     * nullable; only the shape is enforced.
     *
     * ⚠️ THE PROFILE LOCK IS NEITHER SET NOR CLEARED. users.profile_locked_at
     * makes the consultant's own page read-only, and until now an admin could
     * only clear it and ask them to re-save. Writing directly is the point of
     * this action — but clearing the lock would silently re-open the bank
     * details to the consultant, and setting it would lock out someone still
     * filling their profile in. Auditable logs every change either way.
     */
    public function updateProfileDetails(Request $request, User $user)
    {
        abort_unless(
            auth()->user()?->isSuperAdmin(),
            403,
            'Only a super admin can edit these details.',
        );

        $consultant = $user->consultant;

        if (! $consultant) {
            return back()->with('error', 'This user is not a consultant, so there are no such details to edit.');
        }

        $data = $request->validate([
            'nic' => ['nullable', 'string', 'max:20'],
            'date_of_birth' => ['nullable', 'date', 'before:today'],
            'gender' => ['nullable', 'in:male,female,other'],
            'address' => ['nullable', 'string', 'max:300'],

            'bank_name' => ['nullable', 'string', 'max:120'],
            'bank_account_name' => ['nullable', 'string', 'max:120'],
            'bank_account_number' => ['nullable', 'string', 'max:40'],
            'bank_branch' => ['nullable', 'string', 'max:120'],
            'swift_code' => ['nullable', 'string', 'max:20'],
            'account_proof' => ['nullable', 'file', 'mimes:pdf,png,jpg,jpeg', 'max:10240'],

            'beneficiary_name' => ['nullable', 'string', 'max:120'],
            'beneficiary_relationship' => ['nullable', 'string', 'max:80'],
            'beneficiary_nic' => ['nullable', 'string', 'max:20'],
            'beneficiary_date_of_birth' => ['nullable', 'date', 'before:today'],
            'beneficiary_phone' => ['nullable', 'string', 'max:40'],
        ]);

        if ($request->hasFile('account_proof')) {
            $data['account_proof_path'] = $request->file('account_proof')->store('consultants/account-proof', 'public');
        }

        unset($data['account_proof']);

        $consultant->update($data);

        return back()->with('success', "{$user->name}'s details have been updated.");
    }

    /**
     * Re-send the registration email for a user who still has an unpaid
     * registration.
     *
     * Copied from Consultant\StudentController::resendEmail() so the two cannot
     * drift, including the rule that matters most:
     *
     * ⚠️ A NEW TEMPORARY PASSWORD IS ISSUED ONLY WHILE password_must_change IS
     * STILL SET — i.e. the student has never completed a first login. Once they
     * have chosen their own password we must not silently replace it, or a
     * well-meant "re-send" locks them out of the account they are already
     * using. Pinned for the consultant-side action by
     * PendingRegistrationActionsTest.
     */
    public function resendRegistrationEmail(User $user)
    {
        $payment = $this->resendablePaymentFor($user);

        // ⚠️ NOTHING UNPAID → SEND THEIR LOGIN DETAILS (2026-09-22). This used to
        // refuse, and the button sat greyed out: "api userwa search krahama eka
        // button eka fade wela non clickable". User 3452 on live had paid (her
        // only payment verified) and had never logged in — exactly who needs it.
        if (! $payment) {
            return $this->resendLoginDetails($user);
        }

        if ($refused = $this->refuseMistypedAddress($user)) {
            return $refused;
        }

        $newPassword = null;

        if ($user->password_must_change) {
            $newPassword = Str::password(10);
            $user->forceFill(['password' => Hash::make($newPassword)])->save();
        }

        $sent = \App\Services\TransactionalMail::registrationReminder(
            $user->email,
            $user->name,
            $payment->course?->title ?? 'your course',
            $payment->payment_code,
            $user->username ?: $payment->payment_code,
            $newPassword,
            $payment->created_at?->copy()->addDays(14)->format('jS F Y') ?? '—',
        );

        if (! $sent) {
            return $this->sendFailed($user, $newPassword);
        }

        $msg = "Registration email re-sent to {$user->email}. Ask them to check Spam/Promotions too.";

        if ($newPassword) {
            $msg .= " A new temporary password was issued: {$newPassword}";
        }

        return back()->with('success', $msg);
    }

    /**
     * Login details for someone with no unpaid registration.
     *
     * Same password rule as the reminder above: a new temporary password ONLY
     * while password_must_change is set (they have never logged in). Anyone who
     * chose their own gets a reset link instead — their password is never
     * replaced, so a re-send cannot lock them out.
     */
    protected function resendLoginDetails(User $user)
    {
        if ($refused = $this->refuseMistypedAddress($user)) {
            return $refused;
        }

        $newPassword = null;
        $resetUrl = null;

        if ($user->password_must_change) {
            $newPassword = Str::password(10);
            $user->forceFill(['password' => Hash::make($newPassword)])->save();
        } else {
            $resetUrl = route('password.reset', [
                'token' => \Illuminate\Support\Facades\Password::broker()->createToken($user),
                'email' => $user->email,
            ]);
        }

        $sent = \App\Services\TransactionalMail::loginDetails(
            $user->email,
            $user->name,
            $user->username ?: $user->email,
            $newPassword,
            $resetUrl,
        );

        if (! $sent) {
            return $this->sendFailed($user, $newPassword);
        }

        return back()->with('success', $newPassword
            ? "Login details sent to {$user->email}. A new temporary password was issued: {$newPassword}"
            : "Login details sent to {$user->email}, with a link to reset their password. Their current password still works.");
    }

    /**
     * ⚠️ A CERTAINLY-WRONG ADDRESS (2026-11-10) is refused BEFORE a new temporary
     * password is issued: nothing could deliver it, and the one the person was
     * already given would stop working. See App\Support\EmailAddressCheck.
     */
    protected function refuseMistypedAddress(User $user)
    {
        $problem = \App\Support\EmailAddressCheck::problem($user->email);

        return $problem === null
            ? null
            : back()->with('error', "Not sent to {$user->email}: {$problem} Correct the email on this page first, then re-send.");
    }

    /**
     * ⚠️ THE NEW PASSWORD IS ALREADY SAVED when a send fails (2026-11-10). Hiding
     * it left the person with no working password at all — the old one replaced,
     * the new one never delivered — on every press while mail was down.
     */
    protected function sendFailed(User $user, ?string $newPassword)
    {
        $reason = \App\Services\TransactionalMail::lastError();

        return back()->with('error', "The email to {$user->email} could not be sent."
            .($reason ? ' The mail server said: "'.$reason.'"' : ' Check Settings and try again.')
            .($newPassword ? " Their temporary password is now: {$newPassword} — give it to them yourself; the old one no longer works." : ''));
    }

    protected function scnAccountsFor(User $user)
    {
        $consultant = $user->consultant;

        if (! $consultant) {
            return collect();
        }

        $staging = app(\App\Services\ServiceGroupStaging::class);
        $config = \App\Services\ServiceGroupStaging::config();

        // orderedAccountIds() is the SAME sequence the unlock chain walks, so
        // the rows read top-to-bottom in the order they actually open.
        $ordered = $consultant->orderedAccountIds();

        $accounts = Consultant::with(['user', 'owner.user'])
            ->whereIn('id', $ordered)
            ->get()
            ->sortBy(fn ($a) => array_search($a->id, $ordered, true));

        $rate = (float) \App\Models\CommissionSetting::current()->commission_per_student;

        // WHO IS ACTUALLY SEATED ON EACH LEG — asked for 2026-09-22, *"user ge
        // athulat giyama e inne SCN kenk nm eyage service group eke detail
        // penwan widiyt hadal denn"*. The card said only how many students were
        // placed on the account as a whole, so an admin had to leave this page
        // for the Service Groups screen to answer "who is on Service Group 1?".
        //
        // ⚠️ SEATS ONLY, NEVER A MONEY FIGURE. group_members.commission_amount
        // is what the seat recorded at the time; the leg BALANCE is a
        // commission-row sum the flush owns (ServiceGroupFlush::legBalance()).
        // Printing the seat sum here would put a second, drifting number for
        // "what is on this leg" next to the release ladder.
        //
        // One query for every leg of every account, not one per account.
        $seats = \App\Models\GroupMember::query()
            ->join('groups', 'groups.id', '=', 'group_members.group_id')
            ->leftJoin('users', 'users.id', '=', 'group_members.user_id')
            ->whereIn('groups.consultant_id', $ordered)
            ->orderBy('group_members.id')
            ->get([
                'groups.consultant_id as account_id',
                'group_members.tier as tier',
                'group_members.joined_at as joined_at',
                'users.name as member_name',
            ])
            ->groupBy('account_id');

        return $accounts->map(function ($account) use ($staging, $config, $consultant, $rate, $seats) {
            $override = $account->lockOverride();

            // How many one-time stages this ACCOUNT has consumed (0, 1 or 2+).
            // Per account, never per person — see ServiceGroupFlush's docblock.
            $stage = max(0, (int) ($account->lifetime_pair_stage ?? 0));
            $step = \App\Services\ServiceGroupStaging::stepFor($stage, $config);

            return [
                'model' => $account,
                'code' => $account->scn_code ?? $account->consultant_code,
                // The person's own account is never gated by the unlock chain
                // and must stay that way — the owner was explicit that only
                // the hidden placement accounts are manually lockable.
                'is_main' => $account->id === $consultant->id,
                'students' => $staging->studentsOn($account->id),
                'unlock_at' => $config['unlock'],
                'unlocked' => $staging->isUnlocked($account, $config),
                'override' => $override,
                // The staged release ladder (reinstated 2026-08-18).
                'stage' => $stage,
                'stage_label' => $step.'-to-'.$step,
                'stage_threshold' => $step * $rate,
                'stage_payout' => $step * 2 * $rate,
                // Service Group 1 and 2 of THIS account, each with the people
                // seated on it. An unseated row (tier null) belongs to neither
                // leg yet and is left out of both rather than guessed into one.
                'legs' => collect([1, 2])->mapWithKeys(fn ($tier) => [$tier => ($seats[$account->id] ?? collect())
                    ->filter(fn ($seat) => (int) $seat->tier === $tier)
                    ->map(fn ($seat) => [
                        'name' => $seat->member_name ?: 'Deleted user',
                        'joined_at' => $seat->joined_at,
                    ])
                    ->values()
                    ->all(),
                ])->all(),
            ];
        })->values();
    }

    /**
     * Manually open or shut one of a consultant's Service Group accounts, or
     * hand it back to the automatic 12-students rule.
     *
     * Only HIDDEN placement accounts may be set: a real consultant's own
     * account is never gated by the unlock chain (ServiceGroupStaging::
     * previousAccountId() filters it out), so an override on it would be a
     * control that silently does nothing in one direction and takes away
     * income in the other.
     */
    public function updateAccountLock(Request $request, Consultant $consultant)
    {
        $data = $request->validate([
            'state' => ['required', Rule::in([Consultant::LOCK_FORCED_OPEN, Consultant::LOCK_FORCED_SHUT, 'auto'])],
        ]);

        if (! \App\Services\FinanceSchemaRepair::lockOverrideReady()) {
            return back()->with('error', 'The manual account lock is not available on this database yet — open Commission Health and run the repair first.');
        }

        if (! $consultant->is_hidden) {
            return back()->with('error', 'A consultant\'s own account is always open and cannot be locked — only their Service Group placement accounts can.');
        }

        $attributes = [
            'manual_lock_override' => $data['state'] === 'auto' ? null : $data['state'],
            'manual_lock_at' => $data['state'] === 'auto' ? null : now(),
            'manual_lock_by' => $data['state'] === 'auto' ? null : auth()->id(),
        ];

        // ⚠️ AN UNLOCK STARTS FROM THE MONEY THAT IS ALREADY THERE (2026-08-27).
        //
        // The release ladder lives in consultants.lifetime_pair_stage, and it is
        // the ONLY thing that decides what a flush is worth — stepForAccount()
        // reads that column and never looks at the legs. A locked account can
        // never advance it, because flush() refuses on the isUnlocked() guard
        // long before reaching the stage bump.
        //
        // So an account that sat locked while students piled onto it was still
        // at stage 0 on the day an admin opened it, and the next run judged
        // 20,000 a side as a 1-to-1 worth 2,500 a side. One stage per run means
        // the backlog then trickled out over three runs — and on the third the
        // account finally reaches the repeating stage, where legPlan() forfeits
        // everything past 7,500 a side to the company. Measured: 40,000 held,
        // 30,000 released across three days, 10,000 cancelled to group_overflow.
        //
        // Reported: "manual karoth unlock eka, e account eke kiyk thibbath eka 0
        // idn patan ganne, eka wardi — e welawe thiyen amount eken eke system
        // run eka yann one."
        //
        // So opening an account seeds its ladder from the balance it is already
        // carrying: whatever stage that money would have earned had the account
        // been open all along.
        //
        // FORWARD ONLY. max() against the current stage, never below it — a
        // stage this person has already consumed must not be handed back, which
        // is the same rule updateAccountStage() enforces.
        if ($data['state'] === Consultant::LOCK_FORCED_OPEN) {
            $earned = $this->stageEarnedByBalance($consultant);

            if ($earned !== null) {
                $attributes['lifetime_pair_stage'] = $earned;
            }
        }

        $consultant->update($attributes);

        $code = $consultant->scn_code ?? $consultant->consultant_code;

        $message = match ($data['state']) {
            Consultant::LOCK_FORCED_OPEN => "{$code} is now unlocked — it can earn group income regardless of the previous account's student count.",
            Consultant::LOCK_FORCED_SHUT => "{$code} is now locked — it earns no group income until this is changed back.",
            default => "{$code} now follows the automatic rule again (it opens once the previous account reaches ".\App\Services\ServiceGroupStaging::config()['unlock'].' students).',
        };

        return $this->applied($request, $message);
    }

    /**
     * The stage an account's CURRENT balance has already earned, so an unlock
     * can resume the ladder there instead of restarting at 1-to-1.
     *
     * Judged on the SMALLER leg, because a stage is a PAIR: a side with nothing
     * facing it releases nothing, whatever the other side holds.
     *
     * Returns null when there is nothing to judge — no group yet, or the
     * staging/flush schema not repaired on this database — so the caller leaves
     * the stage alone rather than writing a guessed zero.
     */
    protected function stageEarnedByBalance(Consultant $consultant): ?int
    {
        if (! \App\Services\FinanceSchemaRepair::stagingReady()
            || ! \App\Services\FinanceSchemaRepair::flushReady()) {
            return null;
        }

        $group = \App\Models\Group::where('consultant_id', $consultant->id)->orderBy('id')->first();

        if (! $group) {
            return null;
        }

        $flusher = app(\App\Services\ServiceGroupFlush::class);
        $config = \App\Services\ServiceGroupStaging::config();
        $rate = (float) \App\Models\CommissionSetting::current()->commission_per_student;

        // ⚠️ SEEDED FROM WHAT THIS ACCOUNT ACTUALLY SETTLES (2026-09-01).
        //
        // legBalance() used to take a `rollup` flag, and its DEFAULT was the
        // display scope — which for a main was the whole rolled-up Service
        // Group, i.e. its children's money. Unlocking a main therefore seeded
        // its ladder from balances it did not own and could jump it straight to
        // 3-to-3, the one stage that forfeits. Found by audit, not by a report.
        //
        // The flag was removed on 2026-09-02 along with the rollup itself, so
        // there is now only one answer and this call cannot ask for the wrong
        // one. Left explicit as a note because the bug it fixed is silent —
        // nothing on screen says which balance seeded a stage.
        $matched = min(
            $flusher->legBalance($consultant, $group, 1),
            $flusher->legBalance($consultant, $group, 2),
        );

        // The HIGHEST stage this balance can pay in full — so the very next run
        // releases as much of it as the rules allow, which is what "e welawe
        // thiyen amount eken eke system run eka yann one" asks for.
        //
        // Thresholds, not a cumulative walk. A cumulative walk promotes 2,500 a
        // side to stage 1, whose threshold is 5,000 — the account would then
        // flush NOTHING and be worse off than before the fix.
        $earned = match (true) {
            $matched >= $config['repeat'] * $rate => 2,
            $matched >= $config['second'] * $rate => 1,
            default => 0,
        };

        return max($flusher->stageOf($consultant), $earned);
    }

    /**
     * Move one account forward along the staged-release ladder.
     *
     * Stage 1 = 1-to-1 (2,500 a side → releases 5,000), stage 2 = 2-to-2
     * (5,000 → 10,000), stage 3 = the repeating 3-to-3 (7,500 → 15,000). An
     * account walks these automatically as it flushes; this control exists
     * because existing consultants who have already had their cheap releases
     * must not be handed them a second time.
     *
     * ⚠️ FORWARD ONLY. A consumed stage can never be re-granted — the owner
     * was explicit ("meka stage 1 and 2 eka unta passe aye select krann bari
     * wenn one"). Without that guard this would be an unlimited money tap: an
     * admin could hand one account endless 5,000 releases by resetting it to
     * stage 1 after every flush.
     */
    public function updateAccountStage(Request $request, Consultant $consultant)
    {
        $data = $request->validate([
            // 1-based in the UI ("Stage 1" = the next flush is the 1-to-1);
            // stored 0-based to match ServiceGroupStaging::stepFor().
            'stage' => ['required', 'integer', 'min:1', 'max:3'],
        ]);

        if (! \App\Services\FinanceSchemaRepair::stagingReady()) {
            return back()->with('error', 'The staged release is not available on this database yet — open Commission Health and run the repair first.');
        }

        $target = (int) $data['stage'] - 1;
        $current = max(0, (int) ($consultant->lifetime_pair_stage ?? 0));
        $code = $consultant->scn_code ?? $consultant->consultant_code;

        if ($target < $current) {
            return $this->applied($request, "{$code} has already used that stage — this can only move forward, never back.", ok: false);
        }

        if ($target === $current) {
            return $this->applied($request, "{$code} is already on that stage.");
        }

        $consultant->update(['lifetime_pair_stage' => $target]);

        $config = \App\Services\ServiceGroupStaging::config();
        $step = \App\Services\ServiceGroupStaging::stepFor($target, $config);
        $rate = (float) \App\Models\CommissionSetting::current()->commission_per_student;

        return $this->applied($request, sprintf(
            '%s is now on the %s stage — it needs LKR %s on each side and releases LKR %s.',
            $code, $step.'-to-'.$step, number_format($step * $rate), number_format($step * 2 * $rate),
        ));
    }

    /**
     * Grant or revoke ONE consultant's access to their downline's admissions.
     *
     * ⚠️ OFF FOR EVERYONE UNTIL SOMEBODY CLICKS THIS. The owner's requirement in
     * full: "meka permission ekak widiyt admin user management eke enable krnn
     * puluwn widiyt hadann, mokada aluthin ena consultant lata me function eka
     * block krnn one nisa — user management eken access denn, naththan meka hide
     * krann one." So a consultant who signs up tomorrow gets nothing new: their
     * Admission Report stays scoped to their own accounts and the downline
     * controls are not rendered at all, rather than rendered and refused.
     *
     * Granting widens what they may READ, never what they may do, and only as
     * far as their own network — ReportController intersects every filter with
     * reachableAccountIds(), so this can never expose a consultant outside it.
     *
     * Stored as a column rather than a Spatie permission because this host has
     * no CLI to seed one; see FinanceSchemaRepair::downlineReportReady().
     */
    public function updateDownlineReport(Request $request, User $user)
    {
        $data = $request->validate(['enabled' => ['required', 'boolean']]);

        $consultant = $user->consultant;

        if (! $consultant) {
            return back()->with('error', 'That user is not a consultant, so there is no network to report on.');
        }

        if (! \App\Services\FinanceSchemaRepair::downlineReportReady()) {
            return back()->with('error', 'This database has not picked up the downline-report setting yet. Open Commission Health and run Repair, then try again.');
        }

        $enabled = (bool) $data['enabled'];

        $consultant->forceFill([
            'can_view_downline_report' => $enabled,
            // Kept even when switching off, so a later question of "who turned
            // this on, and when" still has an answer.
            'downline_report_granted_at' => $enabled ? now() : $consultant->downline_report_granted_at,
            'downline_report_granted_by' => $enabled ? auth()->id() : $consultant->downline_report_granted_by,
        ])->save();

        return back()->with('success', $enabled
            ? "{$user->name} can now see the Admission Report for everyone in their network."
            : "{$user->name}'s Admission Report is back to their own accounts only.");
    }

    /**
     * Which optional pages a consultant may open (2026-09-22).
     *
     * Owner: "Admission report eka, payment summary report, My bookings, All
     * bookings ewa access eken dena widiyt dala denn one buy one access denn
     * saha okkom access denn". OFF for everyone until set here. The whole list
     * is replaced, so unticking a page takes it away.
     *
     * The grant belongs to the PERSON — a placement account resolves to its
     * owner, the same as the downline switch above.
     */
    public function updatePageAccess(Request $request, User $user)
    {
        $data = $request->validate([
            'pages' => ['nullable', 'array'],
            'pages.*' => ['string', Rule::in(array_keys(Consultant::PAGE_ACCESS))],
        ]);

        $consultant = $user->consultant?->owningPerson();

        if (! $consultant) {
            return back()->with('error', 'That user is not a consultant, so there are no consultant pages to give.');
        }

        // Before any write — the column is added at runtime (no CLI here).
        \App\Services\FinanceSchemaRepair::ensure();

        $pages = array_values(array_intersect(array_keys(Consultant::PAGE_ACCESS), $data['pages'] ?? []));

        $consultant->forceFill([
            'page_access' => json_encode($pages),
            'page_access_updated_at' => now(),
            'page_access_updated_by' => auth()->id(),
        ])->save();

        try {
            activity()->performedOn($consultant)
                ->withProperties(['pages' => $pages])
                ->log('Page access set for '.$user->name.': '.($pages ? implode(', ', $pages) : 'none'));
        } catch (\Throwable $e) {
            report($e);
        }

        $labels = array_map(fn ($key) => Consultant::PAGE_ACCESS[$key], $pages);

        return back()->with('success', $pages
            ? "{$user->name} can now open: ".implode(', ', $labels).'.'
            : "{$user->name} no longer has any of the optional pages.");
    }

    /**
     * ⚠️ THE COURSES A LECTURER TEACHES (2026-10-25). Owner: "lecturer add
     * krnakot saha eka user management eken edit kranakot eyta course type eka
     * dann e course type ekata adala exam and assignement ... penwann one".
     *
     * On these courses the lecturer sees and runs every exam and assignment,
     * whoever wrote it. The whole list is replaced, so unticking a course takes
     * it away. A course whose own lecturer this is stays theirs regardless.
     */
    public function updateLecturerCourses(Request $request, User $user)
    {
        $data = $request->validate([
            'course_ids' => ['nullable', 'array'],
            'course_ids.*' => ['integer', 'exists:courses,id'],
        ]);

        if (! $user->hasRole('lecturer')) {
            return back()->with('error', 'Courses can only be given to a lecturer.');
        }

        try {
            $ids = LecturerCourses::sync($user->id, $data['course_ids'] ?? [], auth()->id());
        } catch (\RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        $titles = Course::whereIn('id', $ids)->orderBy('title')->pluck('title')->all();

        try {
            activity()->performedOn($user)
                ->withProperties(['course_ids' => $ids])
                ->log('Courses given to lecturer '.$user->name.': '.($titles ? implode(', ', $titles) : 'none'));
        } catch (\Throwable $e) {
            report($e);
        }

        return back()->with('success', $titles
            ? "{$user->name} now sees the exams and assignments of: ".implode(', ', $titles).'.'
            : "{$user->name} has no courses given to them (their own courses still show).");
    }

    public function update(Request $request, User $user)
    {
        $emailRules = ['required', 'email', 'max:180', Rule::unique('users', 'email')->ignore($user->id)];

        // Only a NEW address is checked (2026-11-10): a mistyped one already on
        // file must not stop an admin saving the rest of the form — and correcting
        // it is exactly what this screen is for.
        if (strcasecmp(trim((string) $request->input('email')), (string) $user->email) !== 0) {
            $emailRules[] = new \App\Rules\RealEmailAddress;
        }

        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'email' => $emailRules,
            'phone' => ['nullable', 'string', 'max:40'],
            'status' => ['required', 'in:active,inactive,suspended'],
        ]);

        $user->update($data);

        return back()->with('success', 'User details updated.');
    }

    /** Change a user's role (promote/demote). Handles profile creation for consultant/student. */
    public function updateRole(Request $request, User $user)
    {
        $data = $request->validate([
            'role' => ['required', Rule::in($this->assignableRoles())],
        ]);

        // Prevent an admin (non-super) from stripping the last super_admin or elevating to super_admin.
        if (! auth()->user()->isSuperAdmin() && ($user->hasRole('super_admin') || $data['role'] === 'super_admin')) {
            abort(403, 'Only a Super Admin can manage Super Admin roles.');
        }

        // Don't allow removing the final super_admin from the system.
        if ($user->hasRole('super_admin') && $data['role'] !== 'super_admin'
            && User::role('super_admin')->count() <= 1) {
            return back()->with('error', 'Cannot change the role of the last Super Admin.');
        }

        // Captured before the sync, so the notification can say what it changed FROM.
        $previousRole = $user->roles->first()?->name;
        $previousLabel = $previousRole ? Str::headline($previousRole) : null;
        $newLabel = Str::headline($data['role']);

        $this->ensureRoleExists($data['role']);

        DB::transaction(function () use ($user, $data) {
            $user->syncRoles([$data['role']]);
            $this->syncProfile($user, $data['role']);
        });

        // Tell the user their access changed. Sent AFTER the transaction
        // commits — an email cannot be un-sent if the write rolls back — and
        // never allowed to fail the request: the role change itself already
        // succeeded, so a mail outage must not surface as an error.
        $emailed = false;

        if ($previousRole !== $data['role']) {
            try {
                $user->notify(new \App\Notifications\RoleChanged($data['role'], $previousLabel));
            } catch (\Throwable $e) {
                report($e);
            }

            try {
                $emailed = \App\Services\TransactionalMail::roleChanged($user->email, $user->name, $newLabel, $previousLabel);
            } catch (\Throwable $e) {
                report($e);
            }
        }

        $message = "{$user->name} is now a {$newLabel}.";
        $message .= $emailed
            ? ' They have been notified by email.'
            : ' (The notification email could not be sent — check Settings → Email.)';

        return back()->with('success', $message);
    }

    /** Set a new password for a user (admin/super admin action, no current password required). */
    public function updatePassword(Request $request, User $user)
    {
        if ($user->hasRole('super_admin') && ! auth()->user()->isSuperAdmin()) {
            abort(403, 'Only a Super Admin can change a Super Admin\'s password.');
        }

        $data = $request->validate([
            'password' => ['required', 'confirmed', Password::defaults()],
        ]);

        // Clearing password_must_change is the point of this action. The flag
        // used to be cleared ONLY by completing the emailed reset link, so an
        // admin setting a password here left the user still flagged - and still
        // being bounced to /profile on every click. Reported 2026-09-11.
        $user->update([
            'password' => Hash::make($data['password']),
            'password_must_change' => false,
        ]);

        return back()->with('success', "Password updated for {$user->name}.");
    }

    /** Lets an Admin re-open a user's self-service profile after their first-save lock. */
    public function unlockProfile(User $user)
    {
        $user->update(['profile_locked_at' => null]);

        return back()->with('success', "{$user->name}'s profile has been unlocked — they can edit their details again.");
    }

    public function destroy(User $user)
    {
        if ($user->id === auth()->id()) {
            return back()->with('error', 'You cannot delete your own account.');
        }

        if ($user->hasRole('super_admin') && ! auth()->user()->isSuperAdmin()) {
            abort(403);
        }

        if ($user->hasRole('super_admin') && User::role('super_admin')->count() <= 1) {
            return back()->with('error', 'Cannot delete the last Super Admin.');
        }

        // ⚠️ CLOSE THE TREE OVER THE GAP BEFORE DELETING (2026-09-11).
        //
        // consultants.parent_consultant_id is ON DELETE SET NULL, so removing
        // a consultant silently empties the upline of everyone directly below
        // them — and everyone under THOSE people disappears from their real
        // sponsor's screens with no error anywhere. That is what happened to
        // Anishka Prabath's branch on 7 September. See App\Services\UplineContinuity.
        if ($user->consultant) {
            $stranded = \App\Services\UplineContinuity::wouldStrand($user->consultant);

            if ($stranded > 0) {
                return back()->with('error', "{$user->name} has {$stranded} consultant(s) below them and no upline of their own, so there is nowhere to move that branch. Move it by hand first, then delete.");
            }

            \App\Services\UplineContinuity::liftDownlineOf($user->consultant);
        }

        // A consultant's two hidden placement accounts (SCN-00101/00102) are
        // OWNED by them — no login, no independent existence — so they must be
        // deleted too, not left orphaned. Their own groups/commissions cascade
        // at the DB level once the hidden account row itself goes.
        $user->consultant?->hiddenAccounts()->get()->each->delete();

        // Hard delete, not soft delete — the user explicitly wants the email
        // freed up for reuse right away. All related rows (consultant/student
        // profile, payments, commissions, etc.) cascade-delete at the DB level.
        $user->forceDelete();

        return back()->with('success', 'User permanently deleted.');
    }

    /** Ensure the matching Student/Consultant profile row exists when a user takes that role. */
    protected function syncProfile(User $user, string $role): void
    {
        if ($role === 'consultant') {
            // Sponsor = the consultant who referred this user while they were a student.
            $sponsorId = Student::where('user_id', $user->id)->value('consultant_id');

            // ...and WHICH LEG of that sponsor they hang off (2026-08-19).
            //
            // Taken from the Service Group they chose at checkout, which
            // EnrollmentService recorded on the payment as requested_tier. Only
            // matters when the sponsor is a MAIN account — a hidden one derives
            // its leg from its ordinal (CommissionService::sideUnderParent) —
            // but recording it always means a deliberate choice is never lost.
            //
            // Oldest payment first: that is the registration they were placed
            // through, not a later top-up.
            $placementTier = \App\Services\FinanceSchemaRepair::placementTierReady()
                ? \App\Models\Payment::where('user_id', $user->id)
                    ->whereIn('requested_tier', [1, 2])
                    ->orderBy('id')
                    ->value('requested_tier')
                : null;

            // ⚠️ THE STUDENT LINE (2026-10-01). Students added on one account
            // stand in a line; promoted, this person hangs under the nearest
            // CONSULTANT above them on it, so their students pay that person and
            // then everyone the old way paid — "8 → 5 → SCN 112". Only when the
            // row is being CREATED: nobody already a consultant is ever moved
            // (owner: existing people are display only). StudentLine returns
            // null whenever it cannot prove the account above is credited
            // exactly as before, and then nothing here changes.
            if (! Consultant::where('user_id', $user->id)->exists()) {
                $line = \App\Services\StudentLine::attachmentFor(
                    (int) $user->id,
                    $sponsorId === null ? null : (int) $sponsorId,
                    $placementTier === null ? null : (int) $placementTier,
                );

                if ($line !== null) {
                    $sponsorId = $line['parent_consultant_id'];
                    $placementTier = $line['placement_tier'];
                }
            }

            Consultant::firstOrCreate(
                ['user_id' => $user->id],
                array_filter([
                    'parent_consultant_id' => $sponsorId,
                    'placement_tier' => $placementTier,
                    'consultant_code' => 'CON-'.str_pad((string) $user->id, 5, '0', STR_PAD_LEFT),
                    'referral_code' => strtoupper(Str::random(8)),
                    'status' => 'active',
                    'approved_by' => auth()->id(),
                    'approved_at' => now(),
                ], fn ($v) => $v !== null),
            );
        }

        if ($role === 'student') {
            Student::firstOrCreate(
                ['user_id' => $user->id],
                [
                    'student_code' => \App\Services\NumberPool::format('SRN', \App\Services\NumberPool::take()),
                    'status' => 'active',
                    'enrolled_at' => now(),
                ],
            );
        }
    }
}
