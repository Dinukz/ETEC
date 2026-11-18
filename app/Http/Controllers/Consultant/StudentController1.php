<?php

namespace App\Http\Controllers\Consultant;

use App\Http\Controllers\Controller;
use App\Models\CommissionSetting;
use App\Models\Consultant;
use App\Models\Course;
use App\Models\Group;
use App\Models\GroupMember;
use App\Models\Payment;
use App\Models\Student;
use App\Models\User;
use App\Services\EnrollmentService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;

class StudentController extends Controller
{
    /**
     * House baseline for the three optional per-course registration fields,
     * used only when the selected course has nothing saved of its own.
     */
    protected const DEFAULT_LANGUAGE = 'Sinhala';

    protected const DEFAULT_CLASS_TYPE = 'Online';

    protected const DEFAULT_COURSE_TYPE = 'Group';

    /**
     * Grace period before an unconfirmed registration is purged. Must stay in
     * step with the --days default of students:purge-unpaid, which is what
     * actually does the deleting.
     */
    public const GRACE_DAYS = 14;

    /** Resolve the signed-in consultant, or 403. */
    protected function consultant()
    {
        $consultant = auth()->user()->consultant;
        abort_if(! $consultant, 403, 'No consultant profile found for your account.');

        // Suspension is already enforced by EnforceConsultantFinanceStatus
        // before any request reaches this far — this is a second layer on
        // the three money-moving routes this resolver guards (students.store,
        // payments.confirm, payments.pay-balance), so they stay closed even
        // if the middleware is ever reordered or bypassed.
        abort_if($consultant->isFinanceSuspended(), 403, 'Your consultant account is suspended.');

        return $consultant;
    }

    public function index(Request $request)
    {
        $consultant = $this->consultant();

        // Include students placed in this consultant's hidden service-group accounts.
        $accountIds = $consultant->accountIds();

        $students = $this->ownedStudents($accountIds)
            ->with(['user.consultant', 'group', 'consultant', 'partnerConsultant'])
            ->when($request->filled('q'), function ($q) use ($request) {
                $term = $request->string('q');
                $q->whereHas('user', fn ($u) => $u->where('name', 'like', "%{$term}%")->orWhere('email', 'like', "%{$term}%"));
            })
            ->latest()
            ->paginate(12)
            ->withQueryString();

        // Payment status per referred student (latest payment for context).
        // 'consultant' is the fallback the Placement cell uses to name the
        // account a student sits in — eager-loaded because
        // Model::preventLazyLoading() is on outside production.
        $payments = Payment::whereIn('consultant_id', $accountIds)
            ->with(['course', 'consultant'])
            ->latest('id')
            ->get()
            ->groupBy('user_id');

        $stats = [
            'total' => $this->ownedStudents($accountIds)->count(),
            'grouped' => $this->ownedStudents($accountIds)->whereNotNull('group_id')->count(),
            'pending_payment' => Payment::whereIn('consultant_id', $accountIds)->where('status', 'pending')->count(),
        ];

        // Tier (Service Group 1 / Service Group 2) per referred student, keyed by
        // user_id. Sourced from GroupMember (actual placement), NOT Commission —
        // a student is placed into a slot the moment their payment is verified,
        // but only EARNS money once their leg pairs up with the opposite one, so
        // reading this from Commission left correctly-placed-but-unmatched
        // students showing as "Not assigned".
        $tiers = GroupMember::whereIn('group_id', Group::whereIn('consultant_id', $accountIds)->select('id'))
            ->pluck('tier', 'user_id');

        // The seat itself is only created once an accountant verifies the
        // payment, so a student still awaiting verification has no GroupMember
        // and used to read as "Not assigned" — even though the consultant
        // explicitly picked a service group when registering them. Fall back
        // to that choice so the intended placement is visible from day one.
        $requestedTiers = $this->requestedTiers($accountIds);

        return view('consultant.students.index', compact('students', 'payments', 'stats', 'consultant', 'tiers', 'requestedTiers'));
    }

    public function pending(Request $request)
    {
        $consultant = $this->consultant();

        // Include this consultant's hidden service-group accounts.
        $accountIds = $consultant->accountIds();

        // REJECTED payments belong on this page too (2026-08-18).
        //
        // A rejection used to be terminal from the consultant's side: the
        // student silently dropped off Pending Students with no way to attach
        // a corrected slip, so the registration was stranded — the owner's
        // report was that reject "sampurnen remove weno", and it should
        // instead let the slip be re-attached. They stay listed until the
        // payment is actually verified.
        $pendingUserIds = Payment::whereIn('consultant_id', $accountIds)
            ->whereIn('status', ['pending', 'rejected'])
            ->pluck('user_id');

        // ⚠️ SELECTED PURELY ON THE PAYMENT (2026-08-22).
        //
        // This used to AND `Student::whereIn('consultant_id', $accountIds)`
        // with the payment-derived user list. Those are two DIFFERENT ownership
        // columns, and after a placement checkout they point at different
        // accounts — so the intersection was empty for everyone and the row was
        // invisible to every consultant, while the header count (already
        // payment-based) still read "Payment Pending 1" above an empty table.
        //
        // $pendingUserIds is already scoped to $accountIds by the Payment query
        // above, so dropping the student-side predicate leaks nothing.
        $students = Student::query()
            ->with(['user.consultant', 'group', 'consultant', 'partnerConsultant'])
            ->whereIn('user_id', $pendingUserIds)
            ->when($request->filled('q'), function ($q) use ($request) {
                $term = $request->string('q');
                $q->whereHas('user', fn ($u) => $u->where('name', 'like', "%{$term}%")->orWhere('email', 'like', "%{$term}%"));
            })
            ->latest()
            ->paginate(12)
            ->withQueryString();

        // See index() for why 'consultant' is eager-loaded here.
        // 'verifications' backs the rejection reason shown on a rejected row —
        // the consultant needs to know WHY before re-attaching a slip.
        $payments = Payment::whereIn('consultant_id', $accountIds)
            ->whereIn('status', ['pending', 'rejected'])
            ->with(['course', 'consultant', 'verifications' => fn ($q) => $q->latest('id')])
            ->latest('id')
            ->get()
            ->groupBy('user_id');

        $stats = [
            'total' => $this->ownedStudents($accountIds)->count(),
            'grouped' => $this->ownedStudents($accountIds)->whereNotNull('group_id')->count(),
            'pending_payment' => $pendingUserIds->count(),
        ];

        // See index() — sourced from GroupMember, not Commission, so a placed
        // but not-yet-paired student still shows which Service Group they're in.
        $tiers = GroupMember::whereIn('group_id', Group::whereIn('consultant_id', $accountIds)->select('id'))
            ->pluck('tier', 'user_id');

        // See index(): this page is ENTIRELY unverified students, so without
        // this fallback every single row read "Not assigned".
        $requestedTiers = $this->requestedTiers($accountIds);

        return view('consultant.students.pending', compact('students', 'payments', 'stats', 'consultant', 'tiers', 'requestedTiers'));
    }

    /**
     * Every student belonging to these accounts, by EITHER ownership column.
     *
     * ⚠️ A STUDENT IS OURS TWO DIFFERENT WAYS (2026-08-22).
     *
     * `students.consultant_id` is who signed them up. `payments.consultant_id`
     * is the account their money was placed under. Those normally agree, but a
     * placement checkout deliberately sets the payment to a chosen SCN account
     * — so filtering on the student column alone silently hid every buyer who
     * placed themselves somewhere other than their sponsor, and filtering on
     * the payment column alone would hide a registered student who has not
     * paid a cent yet (no payment row exists to match).
     *
     * Only the union is correct on both counts. Both sides are scoped to
     * $accountIds, so it widens the list without leaking anyone else's
     * students.
     *
     * @param  array<int>  $accountIds
     */
    protected function ownedStudents(array $accountIds): \Illuminate\Database\Eloquent\Builder
    {
        $payerUserIds = Payment::whereIn('consultant_id', $accountIds)->distinct()->pluck('user_id');

        return Student::query()->where(function ($q) use ($accountIds, $payerUserIds) {
            $q->whereIn('consultant_id', $accountIds)
                ->orWhereIn('user_id', $payerUserIds);
        });
    }

    /**
     * The service group each student was REQUESTED into at registration,
     * keyed by user_id — the consultant's own choice, recorded on the payment
     * long before any seat exists.
     *
     * Ordered id DESC so that when pluck() collapses duplicate user_ids the
     * LAST row written wins — the oldest payment, i.e. the original
     * registration choice rather than a later 'balance' top-up.
     *
     * @param  array<int>  $accountIds
     */
    protected function requestedTiers(array $accountIds): \Illuminate\Support\Collection
    {
        return Payment::whereIn('consultant_id', $accountIds)
            ->whereIn('requested_tier', [1, 2])
            ->orderByDesc('id')
            ->pluck('requested_tier', 'user_id');
    }

    public function create()
    {
        $consultant = $this->consultant();
        $courses = Course::published()->orderBy('title')
            ->get(['id', 'title', 'price', 'discount_price', 'default_language', 'default_class_type', 'default_course_type']);

        // Auto-fill map for the registration form: selecting a course pre-fills
        // its Language / Class Type / Course Type (still editable per student).
        //
        // Falling back to the house baseline when a course has none saved is
        // deliberate. Those three columns are optional and almost every course
        // on production still has them blank — the 2026-07-27 backfill migration
        // that was meant to seed them can't run there (no CLI), so the map was
        // handing the form three empty strings and picking a course appeared to
        // do nothing at all. An explicit per-course value always wins.
        $courseDefaults = $courses->mapWithKeys(fn ($c) => [$c->id => [
            'language' => $c->default_language ?: self::DEFAULT_LANGUAGE,
            'class_type' => $c->default_class_type ?: self::DEFAULT_CLASS_TYPE,
            'course_type' => $c->default_course_type ?: self::DEFAULT_COURSE_TYPE,
        ]]);

        // "Requested By" options: this consultant plus everyone in their downline.
        $ownerIds = array_merge([$consultant->id], $consultant->downlineIds());

        $requestedByConsultants = Consultant::real()->with('user')
            ->whereIn('id', $ownerIds)
            ->where('status', 'active')
            ->get()
            ->filter(fn ($c) => $c->user)
            ->sortBy(fn ($c) => $c->id === $consultant->id ? '' : $c->user->name)
            ->values();

        // "Partner Consultant" options: the hidden service-group accounts belonging
        // to this consultant (and downline) — SCN00101 / SCN00102 style buckets.
        $partnerConsultants = Consultant::hidden()->with('owner.user')
            ->whereIn('owner_consultant_id', $ownerIds)
            ->where('status', 'active')
            ->orderBy('owner_consultant_id')
            ->orderBy('id')
            ->get();

        // Live "how full is each service group" preview, sourced from each
        // account's own tier counters (not derived from position — tiers can
        // now fill independently since placement honours the explicit choice
        // made below).
        $settings = CommissionSetting::current();
        $previewAccountIds = array_merge(
            $requestedByConsultants->pluck('id')->all(),
            $partnerConsultants->pluck('id')->all(),
        );

        // An account can have more than one open group (e.g. one tier full while
        // the other still has room); take the oldest — the one placement would
        // actually use next — for the preview.
        $openGroups = Group::whereIn('consultant_id', $previewAccountIds)
            ->where('status', 'open')
            ->orderBy('id')
            ->get()
            ->groupBy('consultant_id')
            ->map(fn ($groups) => $groups->first());

        $tier1Capacity = (int) $settings->first_tier_students;
        $tier2Capacity = (int) $settings->second_tier_students;

        $groupPreviews = collect($previewAccountIds)->mapWithKeys(fn ($id) => [$id => [
            'tier1_filled' => (int) ($openGroups[$id]->tier1_filled ?? 0),
            'tier1_capacity' => $tier1Capacity,
            'tier2_filled' => (int) ($openGroups[$id]->tier2_filled ?? 0),
            'tier2_capacity' => $tier2Capacity,
        ]]);

        return view('consultant.students.create', compact('courses', 'consultant', 'requestedByConsultants', 'partnerConsultants', 'groupPreviews', 'courseDefaults'));
    }

    public function store(Request $request)
    {
        $consultant = $this->consultant();

        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email', 'max:180'],
            'phone' => ['nullable', 'string', 'max:40'],
            'date_of_birth' => ['nullable', 'date', 'before:today'],
            'gender' => ['nullable', 'in:male,female,other'],
            'address' => ['nullable', 'string', 'max:300'],
            'city' => ['nullable', 'string', 'max:100'],
            'district' => ['nullable', 'string', 'max:100'],
            'nic' => ['nullable', 'string', 'max:20'],
            'nic_owned_by_student' => ['nullable', 'boolean'],
            'nic_owner_name' => ['required_if:nic_owned_by_student,0', 'nullable', 'string', 'max:120'],
            'nic_owner_relationship' => ['required_if:nic_owned_by_student,0', 'nullable', 'string', 'max:80'],
            'no_objection_letter' => ['nullable', 'file', 'mimes:pdf,png,jpg,jpeg', 'max:10240'],
            'course_id' => ['required', 'exists:courses,id'],
            'language' => ['nullable', 'string', 'max:80'],
            'class_type' => ['nullable', 'string', 'max:80'],
            'course_type' => ['nullable', 'string', 'max:80'],
            'requested_by_consultant_id' => ['nullable', 'exists:consultants,id'],
            'partner_consultant_id' => ['nullable', 'exists:consultants,id'],
            'placement_tier' => ['required', 'in:1,2'],
            'password' => ['nullable', 'confirmed', Password::defaults()],
        ]);

        // Resolve who the registration is attributed to: this consultant, or one of
        // their downline consultants. The chosen consultant earns the commission.
        $requestedBy = $consultant;
        $requestedById = (int) $request->input('requested_by_consultant_id');

        if ($requestedById && $requestedById !== $consultant->id) {
            if (! in_array($requestedById, $consultant->downlineIds(), true)) {
                return back()->withInput()->withErrors(['requested_by_consultant_id' => 'You can only register on behalf of consultants in your downline.']);
            }

            $requestedBy = Consultant::find($requestedById);
        }

        // Placement account: the student physically fills a service group of the
        // requested-by consultant, or one of that consultant's hidden accounts.
        // Income always rolls up to the real (requested-by) consultant.
        $placement = $requestedBy;
        $partnerId = (int) $request->input('partner_consultant_id');

        if ($partnerId) {
            $partner = Consultant::hidden()->find($partnerId);

            if (! $partner || (int) $partner->owner_consultant_id !== (int) $requestedBy->id) {
                return back()->withInput()->withErrors(['partner_consultant_id' => 'Choose a service-group account that belongs to the selected consultant.']);
            }

            $placement = $partner;
        }

        $course = Course::findOrFail($data['course_id']);
        $nicOwnedByStudent = $request->boolean('nic_owned_by_student', true);

        // Normalise the password: use what was supplied, or auto-generate one to show later.
        $suppliedPassword = $data['password'] ?? null;
        $plainPassword = $suppliedPassword ?: Str::password(10);
        $generatedPassword = null;
        $newUser = null;
        $paymentCode = null;

        DB::transaction(function () use ($request, $data, $placement, $course, $nicOwnedByStudent, $suppliedPassword, $plainPassword, &$generatedPassword, &$newUser, &$paymentCode) {
            // Reuse an existing user by email, otherwise create one.
            $user = User::where('email', $data['email'])->first();

            if (! $user) {
                $generatedPassword = $suppliedPassword ? null : $plainPassword;

                $user = User::create([
                    'name' => $data['name'],
                    'email' => $data['email'],
                    'phone' => $data['phone'] ?? null,
                    'password' => Hash::make($plainPassword),
                    'status' => 'active',
                    'email_verified_at' => now(),
                    'password_must_change' => true,
                ]);

                $newUser = $user;
            }

            // Pending enrollment + payment → the consultant confirms the payment plan
            // later from the Pending Students page (see confirmPayment()). The chosen
            // Service Group is honoured once the payment is verified (CommissionService).
            app(EnrollmentService::class)->enroll($user, $course, $placement, [
                'awaiting_type' => true,
                'requested_tier' => (int) $data['placement_tier'],
            ]);

            $paymentCode = Payment::where('user_id', $user->id)->where('course_id', $course->id)
                ->where('status', 'pending')->latest('id')->value('payment_code');

            // Fill in the registration-form details captured beyond the base enroll flow.
            $noticePath = $request->hasFile('no_objection_letter')
                ? $request->file('no_objection_letter')->store('students/noc', 'public')
                : null;

            $user->student?->update(array_filter([
                'date_of_birth' => $data['date_of_birth'] ?? null,
                'gender' => $data['gender'] ?? null,
                'address' => $data['address'] ?? null,
                'city' => $data['city'] ?? null,
                'district' => $data['district'] ?? null,
                'nic' => $data['nic'] ?? null,
                'nic_owned_by_student' => $nicOwnedByStudent,
                'nic_owner_name' => $nicOwnedByStudent ? null : ($data['nic_owner_name'] ?? null),
                'nic_owner_relationship' => $nicOwnedByStudent ? null : ($data['nic_owner_relationship'] ?? null),
                'no_objection_letter_path' => $noticePath,
                'language' => $data['language'] ?? null,
                'class_type' => $data['class_type'] ?? null,
                'course_type' => $data['course_type'] ?? null,
                'partner_consultant_id' => $data['partner_consultant_id'] ?? null,
            ], fn ($v) => $v !== null));
        });

        $msg = "Student \"{$data['name']}\" added and enrolled in {$course->title}. Confirm their payment plan from Pending Students within 14 days, or the registration will be removed automatically.";

        $emailError = null;

        if ($newUser) {
            // In-app bell + the actual email (via Mail::html, the reliable path).
            $newUser->notify(new \App\Notifications\WelcomeStudentCredentials($paymentCode));

            $sent = \App\Services\TransactionalMail::welcome(
                $newUser->email,
                $newUser->name,
                $course->title,
                $paymentCode ?? $newUser->email,
                $plainPassword,
            );

            $msg .= $sent
                ? ' Login details have been emailed to the student (ask them to check their Spam/Promotions folder too).'
                : '';

            if (! $sent) {
                $emailError = 'The student account was created, but the welcome email could not be sent. Check Settings → Email.';
            }

            if ($generatedPassword) {
                $msg .= " Temporary login password: {$generatedPassword}";
            }
        } else {
            // No email because this address already had an account — so there are
            // no new credentials to send. Make that explicit rather than silent.
            $emailError = 'No welcome email was sent because an account with this email already exists. The student keeps their existing login.';
        }

        // Stay on the registration form rather than bouncing to Direct
        // Registrations — consultants add students in runs, and the receipt
        // below is what they screenshot/send, so throwing them onto another
        // page mid-flow just meant navigating straight back.
        $redirect = redirect()->route('consultant.students.create')
            ->with('success', $msg)
            ->with('student_created', [
                'name' => $data['name'],
                'payment_code' => $paymentCode,
                'course' => $course->title,
                'expires_on' => now()->addDays(self::GRACE_DAYS)->format('jS F Y'),
            ]);

        return $emailError ? $redirect->with('error', $emailError) : $redirect;
    }

    /**
     * Re-send the registration email to a student who still hasn't paid — the
     * common case being that the first one landed in Spam, or was sent before
     * the student had access to that inbox.
     *
     * A fresh temporary password is generated and included ONLY while
     * password_must_change is still set, i.e. the student has never completed a
     * first login. Once they have chosen their own password we must not
     * silently replace it, so the reminder goes out without credentials and
     * points at Forgot Password instead.
     */
    public function resendEmail(Payment $payment)
    {
        $consultant = $this->consultant();
        abort_unless(in_array($payment->consultant_id, $consultant->reachableAccountIds(), true), 403);

        if ($payment->status !== 'pending') {
            return back()->with('error', 'This payment is no longer pending, so there is nothing to chase up.');
        }

        $payment->loadMissing(['user.student', 'course']);
        $user = $payment->user;

        if (! $user) {
            return back()->with('error', 'This registration has no student account attached to it.');
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
            $payment->created_at?->copy()->addDays(self::GRACE_DAYS)->format('jS F Y') ?? '—',
        );

        if (! $sent) {
            return back()->with('error', "The email to {$user->email} could not be sent. Check Settings → Email and try again.");
        }

        $msg = "Registration email re-sent to {$user->email}. Ask them to check Spam/Promotions too.";

        if ($newPassword) {
            $msg .= " A new temporary password was issued: {$newPassword}";
        }

        return back()->with('success', $msg);
    }

    /**
     * Cancel a registration that was never paid for, removing it completely so
     * the same email address can be registered again straight away.
     *
     * Soft-deleting is what caused the reported "email already taken" dead end:
     * the users table keeps a unique index on email, and enrollments a unique
     * (user_id, course_id), so a leftover row blocks re-registration even when
     * it is invisible in the UI. Hence forceDelete() — the same call
     * UserManagementController::destroy() and students:purge-unpaid both use.
     *
     * The safety rules mirror students:purge-unpaid exactly: never touch an
     * account that holds any verified payment, and never delete a person who
     * has a staff or consultant role. In those cases only this one unpaid
     * payment (and its enrollment) is removed and the account itself survives.
     */
    public function destroyRegistration(Payment $payment)
    {
        $consultant = $this->consultant();
        abort_unless(in_array($payment->consultant_id, $consultant->reachableAccountIds(), true), 403);

        if ($payment->status !== 'pending') {
            return back()->with('error', 'Only a registration still awaiting verification can be removed. Ask an administrator to reverse a verified payment.');
        }

        // roles is loaded up front because hasAnyRole() below reads that
        // relation, and Model::preventLazyLoading() is on outside production.
        $payment->loadMissing('user.roles');
        $user = $payment->user;
        $studentName = $user?->name ?? 'This student';

        // Verified money anywhere on the account, or a role beyond plain
        // student, means the person stays — only the unpaid registration goes.
        $keepAccount = ! $user
            || $user->hasAnyRole(['super_admin', 'admin', 'accountant', 'lecturer', 'consultant'])
            || Payment::where('user_id', $user->id)
                ->where('id', '!=', $payment->id)
                ->where('status', 'verified')
                ->exists();

        DB::transaction(function () use ($payment, $user, $keepAccount) {
            if ($keepAccount) {
                // enrollments.payment_id is nullOnDelete, so the enrollment
                // would otherwise linger and its unique (user_id, course_id)
                // would block re-enrolling in the same course.
                \App\Models\Enrollment::where('user_id', $payment->user_id)
                    ->where('course_id', $payment->course_id)
                    ->whereIn('status', ['pending', 'cancelled'])
                    ->delete();

                $payment->delete();

                return;
            }

            // Everything hanging off the user (student profile, payments,
            // enrollments, group memberships) cascade-deletes at the DB level.
            $user->forceDelete();
        });

        return back()->with('success', $keepAccount
            ? "The unpaid registration {$payment->payment_code} was removed. {$studentName}'s account was kept because it has other verified activity."
            : "{$studentName} was permanently deleted. That email address can be registered again straight away.");
    }

    /**
     * Consultant confirms the payment plan (free/half/full) for a student they
     * registered. Sets the real amount, marks the payment awaiting accountant
     * verification, and switches the student's login username from their
     * payment code to their student code.
     */
    public function confirmPayment(Request $request, Payment $payment)
    {
        $consultant = $this->consultant();
        abort_unless(in_array($payment->consultant_id, $consultant->reachableAccountIds(), true), 403);

        if ($payment->payment_type) {
            return back()->with('error', 'This payment has already been confirmed.');
        }

        $data = $request->validate([
            'payment_type' => ['required', 'in:full,half,free_card'],
            // The date on the slip — was silently forced to now(), so a slip
            // handed to the consultant days earlier always showed as "paid
            // today" on the accountant's Payments list. Capped at today: this
            // records when the student actually paid, and nothing can have
            // been paid in the future.
            'payment_date' => ['required', 'date', 'before_or_equal:today'],
            'payment_reference_number' => ['nullable', 'string', 'max:120'],
            'payment_receipt' => ['nullable', 'file', 'mimes:pdf,png,jpg,jpeg', 'max:10240'],
        ]);

        $receiptPath = $request->hasFile('payment_receipt')
            ? $request->file('payment_receipt')->store('receipts', 'public')
            : null;

        DB::transaction(function () use ($payment, $data, $receiptPath) {
            $payment->update([
                'payment_type' => $data['payment_type'],
                'amount' => EnrollmentService::amountForType($payment->course, $data['payment_type']),
                'reference_number' => $data['payment_reference_number'] ?? $payment->reference_number,
                'receipt_path' => $receiptPath ?? $payment->receipt_path,
                // The chosen DATE, with today's clock time kept on it — same
                // convention as EnrollmentService's other paid_at writes, and
                // it keeps same-day entries ordered instead of all landing on
                // a flat midnight.
                'paid_at' => \Illuminate\Support\Carbon::parse($data['payment_date'])->setTimeFrom(now()),
            ]);

            // Login identifier moves from the payment code to the student code
            // now that the account has a confirmed payment plan.
            $studentCode = Student::where('user_id', $payment->user_id)->value('student_code');

            if ($studentCode && $payment->user) {
                $payment->user->update(['username' => $studentCode]);
            }
        });

        return back()->with('success', "Payment plan recorded for {$payment->payment_code}. Awaiting accountant verification.");
    }

    /**
     * Re-attach a corrected slip to a REJECTED payment and send it back for
     * verification.
     *
     * A rejection used to be the end of the road for a registration: the
     * accountant marked it rejected, the student disappeared from Pending
     * Students, and nothing in the consultant's UI could revive it — the only
     * remaining option was to delete and re-register, which burns the payment
     * code and the student's login. The owner asked for the slip to be
     * re-attachable instead (2026-08-18).
     *
     * The SAME payment row is reused rather than creating a replacement, so
     * the payment code, the student's enrolment and the whole rejection
     * history stay intact and auditable. Only an accountant can verify it, so
     * this hands the row back to exactly the same queue it came from.
     */
    public function resubmitPayment(Request $request, Payment $payment)
    {
        $consultant = $this->consultant();
        abort_unless(in_array($payment->consultant_id, $consultant->reachableAccountIds(), true), 403);

        if ($payment->status !== 'rejected') {
            return back()->with('error', 'Only a rejected payment can be re-submitted.');
        }

        $data = $request->validate([
            'payment_type' => ['required', 'in:full,half,free_card'],
            'payment_date' => ['required', 'date', 'before_or_equal:today'],
            'payment_reference_number' => ['nullable', 'string', 'max:120'],
            // Required here, unlike confirmPayment(): the payment was rejected,
            // and re-submitting the same evidence that was just turned down is
            // never the intent — a corrected slip is the whole point.
            'payment_receipt' => ['required', 'file', 'mimes:pdf,png,jpg,jpeg', 'max:10240'],
        ]);

        $receiptPath = $request->file('payment_receipt')->store('receipts', 'public');

        DB::transaction(function () use ($payment, $data, $receiptPath) {
            $payment->update([
                'payment_type' => $data['payment_type'],
                'amount' => EnrollmentService::amountForType($payment->course, $data['payment_type']),
                'reference_number' => $data['payment_reference_number'] ?? $payment->reference_number,
                'receipt_path' => $receiptPath,
                'paid_at' => \Illuminate\Support\Carbon::parse($data['payment_date'])->setTimeFrom(now()),
                'status' => 'pending',
                // Cleared so the row reads as genuinely awaiting a decision
                // again — a leftover verified_at would date the NEW submission
                // to the moment it was rejected.
                'verified_at' => null,
            ]);
        });

        return back()->with('success', "A corrected slip has been attached to {$payment->payment_code}. It is back with the accountant for verification.");
    }

    /**
     * A half-paying student's remaining course balance. Recorded as a SECOND
     * Payment row (payment_type = 'balance') rather than editing the original
     * one, so it goes through the same accountant-verification queue and
     * leaves a full audit trail of both instalments. It earns no commission
     * of its own — the seat and its money were already fully paid out the
     * moment the original half payment was verified (see
     * CommissionService::handleVerifiedPayment) — the whole amount becomes
     * company revenue once verified.
     */
    public function payBalance(Request $request, Payment $payment)
    {
        $consultant = $this->consultant();
        abort_unless(in_array($payment->consultant_id, $consultant->reachableAccountIds(), true), 403);

        if ($payment->payment_type !== 'half' || $payment->status !== 'verified') {
            return back()->with('error', 'Only a verified half payment has a balance to settle.');
        }

        $alreadyOpen = Payment::where('user_id', $payment->user_id)
            ->where('course_id', $payment->course_id)
            ->where('payment_type', 'balance')
            ->whereIn('status', ['pending', 'verified'])
            ->exists();

        if ($alreadyOpen) {
            return back()->with('error', 'A balance payment for this student has already been recorded.');
        }

        $balance = EnrollmentService::remainingBalance($payment->course, $payment->user_id);

        if ($balance <= 0) {
            return back()->with('error', 'This student has nothing left to pay.');
        }

        $data = $request->validate([
            'payment_date' => ['required', 'date', 'before_or_equal:today'],
            'payment_reference_number' => ['nullable', 'string', 'max:120'],
            'payment_receipt' => ['nullable', 'file', 'mimes:pdf,png,jpg,jpeg', 'max:10240'],
        ]);

        // Genuinely before any transaction opens — see PaymentService::verify()
        // for why an ALTER TABLE cannot run mid-transaction.
        \App\Services\CommissionSchemaRepair::ensure();

        $receiptPath = $request->hasFile('payment_receipt')
            ? $request->file('payment_receipt')->store('receipts', 'public')
            : null;

        Payment::create([
            'payment_code' => \App\Services\CodeGeneratorService::next('payments', 'payment_code', 'PYM', 5, 1),
            'user_id' => $payment->user_id,
            'course_id' => $payment->course_id,
            'enrollment_id' => $payment->enrollment_id,
            'consultant_id' => $payment->consultant_id,
            'requested_tier' => $payment->requested_tier,
            'amount' => $balance,
            'currency' => $payment->currency,
            'method' => $payment->method,
            'payment_type' => 'balance',
            'reference_number' => $data['payment_reference_number'] ?? null,
            'receipt_path' => $receiptPath,
            'status' => 'pending',
            'paid_at' => \Illuminate\Support\Carbon::parse($data['payment_date'])->setTimeFrom(now()),
        ]);

        return back()->with('success', 'Balance payment recorded. Awaiting accountant verification.');
    }
}
