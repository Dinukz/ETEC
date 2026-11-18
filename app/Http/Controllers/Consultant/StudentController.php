<?php

namespace App\Http\Controllers\Consultant;

use App\Concerns\ResolvesOwnedStudents;
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
use App\Services\RegistrationPayer;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;

class StudentController extends Controller
{
    use ResolvesOwnedStudents;

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

        // ⚠️ + THE REGISTRATIONS THIS CONSULTANT PAYS FOR ON A DOWNLINE'S ACCOUNT
        // (2026-11-03) — see RegistrationPayer. Empty, and no query, for anyone
        // who pays for nothing; every query below is then exactly the one that
        // ran before. Every status, so a verified half still offers its balance.
        $paid = RegistrationPayer::paidBy($consultant);
        $paidIds = $paid->pluck('id')->map(fn ($id) => (int) $id)->all();

        $students = $this->studentsFor($accountIds, $paid)
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
        // 'requestedBy.user.roles' is who pays when that is not the holder — the
        // "Paid by" line and the view-only row (RegistrationPayer::payerOf reads
        // the roles). A payment with no payer has no key, so it costs no query,
        // and it is safe before the column exists.
        $payments = $this->paymentsFor($accountIds, $paidIds)
            ->with(['course', 'consultant', 'requestedBy.user.roles'])
            ->latest('id')
            ->get()
            ->groupBy('user_id');

        // The cards describe the table under them, so they count what it lists.
        $stats = [
            'total' => $this->studentsFor($accountIds, $paid)->count(),
            'grouped' => $this->studentsFor($accountIds, $paid)->whereNotNull('group_id')->count(),
            'pending_payment' => $this->paymentsFor($accountIds, $paidIds)->where('status', 'pending')->count(),
        ];

        // Tier (Service Group 1 / Service Group 2) per referred student, keyed by
        // user_id. Sourced from GroupMember (actual placement), NOT Commission —
        // a student is placed into a slot the moment their payment is verified,
        // but only EARNS money once their leg pairs up with the opposite one, so
        // reading this from Commission left correctly-placed-but-unmatched
        // students showing as "Not assigned".
        $tiers = $this->tiersFor($accountIds, $paid);

        // The seat itself is only created once an accountant verifies the
        // payment, so a student still awaiting verification has no GroupMember
        // and used to read as "Not assigned" — even though the consultant
        // explicitly picked a service group when registering them. Fall back
        // to that choice so the intended placement is visible from day one.
        $requestedTiers = $this->requestedTiers($accountIds, $paidIds);

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
        //
        // ⚠️ + THE ONES THIS CONSULTANT PAYS FOR ON A DOWNLINE'S ACCOUNT
        // (2026-11-03): "payment gewann thiyenne eka add krana kenata". See
        // RegistrationPayer — empty, and no query, for anyone who pays for
        // nothing, so this is then exactly the query that ran before.
        $paid = RegistrationPayer::paidBy($consultant);
        $paidOpenIds = $paid->whereIn('status', ['pending', 'rejected'])->pluck('id')->map(fn ($id) => (int) $id)->values()->all();

        $pendingUserIds = $this->paymentsFor($accountIds, $paidOpenIds)
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

        // See index() for why 'consultant' and 'requestedBy.user.roles' are eager-loaded here.
        // 'verifications' backs the rejection reason shown on a rejected row —
        // the consultant needs to know WHY before re-attaching a slip.
        $payments = $this->paymentsFor($accountIds, $paidOpenIds)
            ->whereIn('status', ['pending', 'rejected'])
            ->with(['course', 'consultant', 'requestedBy.user.roles', 'verifications' => fn ($q) => $q->latest('id')])
            ->latest('id')
            ->get()
            ->groupBy('user_id');

        $stats = [
            'total' => $this->studentsFor($accountIds, $paid)->count(),
            'grouped' => $this->studentsFor($accountIds, $paid)->whereNotNull('group_id')->count(),
            'pending_payment' => $pendingUserIds->count(),
        ];

        // See index() — sourced from GroupMember, not Commission, so a placed
        // but not-yet-paired student still shows which Service Group they're in.
        $tiers = $this->tiersFor($accountIds, $paid);

        // See index(): this page is ENTIRELY unverified students, so without
        // this fallback every single row read "Not assigned".
        $requestedTiers = $this->requestedTiers($accountIds, $paid->pluck('id')->map(fn ($id) => (int) $id)->all());

        return view('consultant.students.pending', compact('students', 'payments', 'stats', 'consultant', 'tiers', 'requestedTiers'));
    }

    /**
     * Is this email or NIC already on somebody's record? Returns the sentence to
     * show, or null when the person is genuinely new.
     *
     * ⚠️ SOFT-DELETED ROWS COUNT. A registration deleted from the Pending
     * Students screen is FORCE-deleted precisely so the email is freed again
     * (see destroy()); anything still soft-deleted is a live record that a
     * restore would bring back, so treating it as absent would recreate the
     * "email already taken" dead end from the other direction.
     *
     * The message names the student code and who registered them, because "this
     * person already exists" with no way to find them is the complaint one step
     * later — the consultant needs to know whether it was their own entry or a
     * colleague's before they go looking.
     */
    protected function existingStudentFor(string $email, ?string $nic): ?string
    {
        $byEmail = User::where('email', $email)->first();

        $student = $byEmail?->student;

        if ($student === null && filled($nic)) {
            // NIC / passport is matched case-insensitively and without spaces —
            // "V1234567 8" and "v12345678" are one person, and the form has no
            // formatting rules to stop either being typed.
            $student = Student::whereRaw('LOWER(REPLACE(nic, " ", "")) = ?', [
                strtolower(str_replace(' ', '', $nic)),
            ])->first();
        }

        if ($student === null && $byEmail === null) {
            return null;
        }

        $who = $student?->user?->name ?? $byEmail?->name ?? 'Someone';
        $code = $student?->student_code;
        $registeredBy = $student?->consultant?->scn_code ?? $student?->consultant?->consultant_code;

        $matched = $byEmail !== null ? 'email address' : 'NIC / passport number';

        return sprintf(
            'This %s is already registered to %s%s.%s Each student can only be registered once.',
            $matched,
            $who,
            $code ? ' ('.$code.')' : '',
            $registeredBy ? ' Registered under '.$registeredBy.'.' : '',
        );
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
     * @param  array<int>  $paidIds  payments this consultant pays for elsewhere — see paymentsFor()
     */
    protected function requestedTiers(array $accountIds, array $paidIds = []): Collection
    {
        return $this->paymentsFor($accountIds, $paidIds)
            ->whereIn('requested_tier', [1, 2])
            ->orderByDesc('id')
            ->pluck('requested_tier', 'user_id');
    }

    /**
     * The students on these accounts, plus the ones this consultant pays for on
     * a downline's account (2026-11-03, RegistrationPayer). With nothing paid
     * elsewhere this IS ownedStudents() — the builder that ran before; otherwise
     * it wraps it, so the shared trait stays one rule.
     *
     * ⚠️ ONE where() GROUP. Appending ->orWhereIn() to ownedStudents() instead
     * would AND the caller's search and whereNotNull() onto the paid half only,
     * and the search box would stop filtering this consultant's own rows.
     *
     * @param  array<int>  $accountIds
     * @param  Collection<int, object>  $paid  RegistrationPayer::paidBy()
     */
    protected function studentsFor(array $accountIds, Collection $paid): Builder
    {
        if ($paid->isEmpty()) {
            return $this->ownedStudents($accountIds);
        }

        $owned = $this->ownedStudents($accountIds)->select('students.id');
        $paidUserIds = $paid->pluck('user_id')->map(fn ($id) => (int) $id)->unique()->values()->all();

        return Student::query()->where(fn ($q) => $q
            ->whereIn('students.id', $owned)
            ->orWhereIn('students.user_id', $paidUserIds));
    }

    /**
     * Payments on these accounts, plus the given ones this consultant pays for
     * elsewhere. With none, exactly the query that ran before.
     *
     * @param  array<int>  $accountIds
     * @param  array<int>  $paidIds
     */
    protected function paymentsFor(array $accountIds, array $paidIds): Builder
    {
        return $paidIds === []
            ? Payment::whereIn('consultant_id', $accountIds)
            : Payment::where(fn ($q) => $q->whereIn('consultant_id', $accountIds)->orWhereIn('id', $paidIds));
    }

    /**
     * The seated Service Group per user_id — today's lookup, plus the students
     * this consultant pays for, read from the seat on the account they were
     * PLACED on (the "CON:" printed beside it).
     *
     * ⚠️ THAT SEAT WINS for them. The 2,500 climb seats the same student on this
     * consultant's own accounts too, each on whatever leg the climb took there
     * (132 on SG2 can sit on 112's main on SG1), so today's lookup alone could
     * print another leg next to "CON: SCN-132".
     *
     * ⚠️ AND EACH STUDENT AGAINST THEIR OWN ACCOUNT ONLY. Paying for one student
     * on 132 and another on 130 must not read the first one's climb seat on 130.
     *
     * @param  array<int>  $accountIds
     * @param  Collection<int, object>  $paid  RegistrationPayer::paidBy()
     */
    protected function tiersFor(array $accountIds, Collection $paid): Collection
    {
        $tiers = GroupMember::whereIn('group_id', Group::whereIn('consultant_id', $accountIds)->select('id'))
            ->pluck('tier', 'user_id');

        if ($paid->isEmpty()) {
            return $tiers;
        }

        $placedOn = $paid->groupBy(fn ($row) => (int) $row->user_id)
            ->map(fn ($rows) => $rows->pluck('consultant_id')->map(fn ($id) => (int) $id)->unique()->values()->all());

        return GroupMember::query()
            ->join('groups', 'groups.id', '=', 'group_members.group_id')
            ->whereIn('group_members.user_id', $placedOn->keys()->all())
            ->whereIn('groups.consultant_id', $paid->pluck('consultant_id')->unique()->values()->all())
            ->orderBy('group_members.id')
            ->get(['group_members.user_id', 'group_members.tier', 'groups.consultant_id'])
            ->filter(fn ($seat) => in_array((int) $seat->consultant_id, $placedOn->get((int) $seat->user_id, []), true))
            ->pluck('tier', 'user_id')
            ->union($tiers);
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

        // ⚠️ AND EVERY DOWNLINE PERSON'S MAIN ACCOUNT (2026-11-03) — the owner:
        // "Partner = 130 (main), 131 or 132". Kept apart from
        // $partnerConsultants, which stays sub-accounts only
        // (PartnerConsultantOptionsTest pins it). This consultant's own main is
        // never offered: with themselves as Requested By, that is None.
        $partnerOptions = $partnerConsultants->map(fn ($p) => [
            'id' => (int) $p->id,
            'owner_id' => (int) $p->owner_consultant_id,
            'label' => ($p->scn_code ?? $p->consultant_code).' — '.($p->owner?->user?->name ?? 'Owner'),
            'is_main' => false,
        ])->concat(
            $requestedByConsultants
                ->reject(fn ($c) => (int) $c->id === (int) $consultant->id)
                ->map(fn ($c) => [
                    'id' => (int) $c->id,
                    'owner_id' => (int) $c->id,
                    'label' => ($c->scn_code ?? $c->consultant_code).' — '.$c->user->name.' (main account)',
                    'is_main' => true,
                ]),
        )->sortBy([['owner_id', 'asc'], ['is_main', 'desc'], ['id', 'asc']])->values();

        // Which Requested By may use which account — see partnerChains().
        $partnerChains = $this->partnerChains($consultant, $partnerOptions->pluck('owner_id')->all());

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

        return view('consultant.students.create', compact('courses', 'consultant', 'requestedByConsultants', 'partnerConsultants', 'partnerOptions', 'partnerChains', 'groupPreviews', 'courseDefaults'));
    }

    /**
     * Each account holder mapped to [holder, the person above, ..., this
     * consultant]. The Partner list shows an account when Requested By is its
     * holder or anywhere above them — the same question store() asks on the
     * server (RequestedByPaysForADownlineAccountTest checks every pair agrees).
     *
     * Bounded three ways: downlineBranchesBelow() caps depth and breadth and
     * guards its own frontier against cycles; this loop stops at 60 hops; $seen
     * stops a hand-edited cycle. A person the capped walk never reached keeps
     * [person, this consultant], which the server accepts as well.
     *
     * @param  array<int>  $holderIds
     * @return array<int, array<int>>
     */
    protected function partnerChains(Consultant $viewer, array $holderIds): array
    {
        $viewerId = (int) $viewer->id;
        $branches = Consultant::downlineBranchesBelow($viewer->accountIds(), [$viewerId]);
        $chains = [];

        foreach (array_unique(array_map('intval', $holderIds)) as $personId) {
            $chain = [];
            $seen = [];
            $at = $personId;

            for ($hops = 0; $at !== null && $at !== $viewerId && $hops < 60; $hops++) {
                if (isset($seen[$at])) {
                    break;
                }

                $seen[$at] = true;
                $chain[] = $at;

                // 'parent' is the PERSON above; null means directly under the viewer.
                $parent = $branches[$at]['parent'] ?? null;
                $at = $parent === null ? null : (int) $parent;
            }

            $chain[] = $viewerId;
            $chains[$personId] = array_values(array_unique($chain));
        }

        return $chains;
    }

    public function store(Request $request)
    {
        $consultant = $this->consultant();

        // ⚠️ EVERY STUDENT DETAIL IS REQUIRED NOW — GOING-LIVE TIGHTENING (2026-09-06).
        //
        // These were `nullable` so the team could register test students in a
        // few keystrokes. Live, a student with no phone or NIC is one nobody can
        // contact, verify or issue a certificate to, and the gap is only ever
        // found later. Owner: "okkoma validation ekak dala okkoma fill karana
        // widiyata."
        //
        // ⚠️ FOUR ARE DELIBERATELY LEFT OPTIONAL, and requiring any of them
        // would refuse registrations that are perfectly valid:
        //
        //   requested_by / partner   who registers and pays / which account the
        //                            student sits on. Most registrations are the
        //                            consultant's own.
        //   password                 blank means the system generates one and
        //                            emails it — the normal path. See the
        //                            resend-email rule.
        //   no_objection_letter      only meaningful when the NIC is not the
        //                            student's own (a minor on a parent's NIC),
        //                            which is what required_if pins below.
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            // RealEmailAddress (2026-11-10): a mistyped address ("gamil.com",
            // "email.com") only bounces, and the bounces got the site's mailbox
            // suspended. Refused here, with the likely right address offered.
            'email' => ['required', 'email', 'max:180', new \App\Rules\RealEmailAddress],
            'phone' => ['required', 'string', 'max:40'],
            'date_of_birth' => ['required', 'date', 'before:today'],
            'gender' => ['required', 'in:male,female,other'],
            'address' => ['required', 'string', 'max:300'],
            'city' => ['required', 'string', 'max:100'],
            'district' => ['required', 'string', 'max:100'],
            'nic' => ['required', 'string', 'max:20'],
            // Explicit yes/no rather than "unticked means yes" — the two owner
            // fields below hang off it, and a silent default decides whether a
            // no-objection letter is demanded at all.
            'nic_owned_by_student' => ['required', 'boolean'],
            'nic_owner_name' => ['required_if:nic_owned_by_student,0', 'nullable', 'string', 'max:120'],
            'nic_owner_relationship' => ['required_if:nic_owned_by_student,0', 'nullable', 'string', 'max:80'],
            'no_objection_letter' => ['required_if:nic_owned_by_student,0', 'nullable', 'file', 'mimes:pdf,png,jpg,jpeg', 'max:10240'],
            'course_id' => ['required', 'exists:courses,id'],
            'language' => ['required', 'string', 'max:80'],
            'class_type' => ['required', 'string', 'max:80'],
            'course_type' => ['required', 'string', 'max:80'],
            'requested_by_consultant_id' => ['nullable', 'exists:consultants,id'],
            'partner_consultant_id' => ['nullable', 'exists:consultants,id'],
            'placement_tier' => ['required', 'in:1,2'],
            'password' => ['nullable', 'confirmed', Password::defaults()],
        ]);

        // ⚠️ "CONSULTANT (REQUESTED BY)" IS WHO REGISTERS THE STUDENT AND PAYS
        // (2026-11-03): this consultant or one of their downline, validated
        // exactly as before, and NEVER overwritten by the partner pick below. It
        // used to flip to "whoever holds the partner account", which the owner
        // called wrong — SCN-112 registering on SCN-132 (SCN-130's) stays
        // Requested By 112, and 112 pays. See RegistrationPayer.
        $requestedBy = $consultant;
        $requestedById = (int) $request->input('requested_by_consultant_id');

        if ($requestedById && $requestedById !== $consultant->id) {
            if (! in_array($requestedById, $consultant->downlineIds(), true)) {
                return back()->withInput()->withErrors(['requested_by_consultant_id' => 'You can only register on behalf of consultants in your downline.']);
            }

            $requestedBy = Consultant::find($requestedById);
        }

        // ⚠️ THE PARTNER IS THE ACCOUNT, AND THE ACCOUNT DECIDES THE 2,500.
        //
        // Only $placement reaches EnrollmentService (payments.consultant_id and
        // students.consultant_id), which is what the climb, the weekly quota and
        // promotion read: the 2,500 climbs from the account picked exactly as if
        // its holder had registered the student (RegisterOnADownlineAccountTest
        // pins the chain; RequestedByPaysForADownlineAccountTest pins it
        // identical whoever registers).
        //
        // ⚠️ The DIRECT bonus is the one exception (2026-11-16): on somebody
        // else's account it goes to Requested By — the $payer stored below, read
        // by RegistrationPayer::directEarner() in the engine.
        //
        // The owner's workflow since 2026-09-25: *"SCN 112 consultant, eyage SCN
        // 113 account eka system run eken balance krgann one nisa, eyage yathe inn
        // SCN 120 account ekat student kenk add krnwa"* — he picks the ACCOUNT he
        // wants filled.
        //
        // Allowed: Requested By's own service-group accounts, or ANY account of
        // somebody below Requested By, their MAIN included ("130 (main), 131 or
        // 132"). Refused: Requested By's own main (that is None), anything above
        // or beside them, any stranger. Requested By's group sits inside this
        // consultant's own (checked above), so the 2026-09-25 boundary holds.
        $placement = $requestedBy;
        $payer = null;
        $partnerId = (int) $request->input('partner_consultant_id');

        if ($partnerId) {
            $partner = Consultant::find($partnerId);

            // The PERSON who holds the picked account.
            $holderId = ! $partner ? 0 : (int) ($partner->is_hidden ? $partner->owner_consultant_id : $partner->id);
            $group = array_merge([(int) $requestedBy->id], array_map('intval', $requestedBy->downlineIds()));

            if ($holderId === 0 || ! in_array($holderId, $group, true)) {
                return back()->withInput()->withErrors(['partner_consultant_id' => 'Choose an account belonging to the Requested By consultant or to someone below them.']);
            }

            if (! $partner->is_hidden && $holderId === (int) $requestedBy->id) {
                return back()->withInput()->withErrors(['partner_consultant_id' => "That is the Requested By consultant's own main account — leave Partner Consultant on None to use it."]);
            }

            $placement = $partner;

            // ⚠️ RECORDED ONLY FOR SOMEBODY ELSE'S ACCOUNT. Every combination that
            // existed before — one's own account, or Requested By = the holder,
            // which is also what a page left open from before this change posts —
            // keeps NULL and behaves exactly as it always did.
            if ($holderId !== (int) $requestedBy->id) {
                $payer = $requestedBy;
            }
        }

        $course = Course::findOrFail($data['course_id']);
        $nicOwnedByStudent = $request->boolean('nic_owned_by_student', true);

        // ⚠️ THE SAME PERSON MUST NOT BE REGISTERED TWICE (2026-09-07).
        //
        // Reported by the owner: "ekama Email, NIC / Passport eka duplicate
        // wenwa, kalin damm ekak aye dann puluwn — ekaparak withrk dan puluwn
        // widiyt, eka kalin add krla thiyeno nm 'already have' kiyla pop up
        // ekak penwann."
        //
        // Neither field had ANY uniqueness rule (the validate() block above),
        // and store() below deliberately REUSES a user found by email — so a
        // second registration of the same person silently succeeded, quietly
        // attached to whichever consultant typed it in second, and the only
        // hint was a note further down saying no welcome email was sent.
        //
        // ⚠️ CHECKED HERE, AT STUDENT REGISTRATION, AND NOWHERE ELSE — the
        // owner was explicit: "Student Register weddi withrk check krala". The
        // public checkout, the payment resubmit and the promotion paths all
        // still reuse an existing login on purpose; a person genuinely coming
        // back for another course goes through those, not through this form.
        if ($duplicate = $this->existingStudentFor($data['email'], $data['nic'] ?? null)) {
            return back()->withInput()->withErrors([
                'duplicate' => $duplicate,
            ]);
        }

        // ⚠️ THE PAYER COLUMN — only for a registration that needs it, and BEFORE
        // any transaction opens (an ALTER auto-commits under MySQL). ensure(),
        // not heal(): it reads the REAL column every time, so a cached "ready"
        // can never send the write below at a column that is not there.
        if ($payer !== null) {
            RegistrationPayer::ensure();

            // ⚠️ NEVER SILENTLY FALL BACK TO "THE HOLDER PAYS". Saving without the
            // payer would hand every payment control to the holder — the exact
            // thing the owner asked to stop — with nothing on screen to say so.
            if (! RegistrationPayer::ready()) {
                return back()->withInput()->withErrors([
                    'partner_consultant_id' => 'The registration could not record '.($payer->scn_code ?: $payer->consultant_code).' as the one who pays, so nothing was saved. Please try again in a minute; if it keeps happening, tell an administrator.',
                ]);
            }
        }

        // Normalise the password: use what was supplied, or auto-generate one to show later.
        $suppliedPassword = $data['password'] ?? null;
        $plainPassword = $suppliedPassword ?: Str::password(10);
        $generatedPassword = null;
        $newUser = null;
        $paymentCode = null;

        DB::transaction(function () use ($request, $data, $placement, $payer, $course, $nicOwnedByStudent, $suppliedPassword, $plainPassword, &$generatedPassword, &$newUser, &$paymentCode) {
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

            // ⚠️ WHO PAYS — in the SAME transaction as the payment, so there is
            // never a moment the holder could act on it as their own.
            if ($payer !== null) {
                Payment::where('user_id', $user->id)->where('course_id', $course->id)
                    ->where('status', 'pending')->latest('id')->firstOrFail()
                    ->forceFill([RegistrationPayer::COLUMN => (int) $payer->id])
                    ->save();
            }

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

        if ($payer !== null) {
            $onCode = $placement->scn_code ?: $placement->consultant_code;
            $payerCode = $payer->scn_code ?: $payer->consultant_code;

            // No amounts: the direct income is course-specific (Elliott Wave is
            // 5,000), and the engine decides it. Since 2026-11-16 the direct bonus
            // is the payer's (RegistrationPayer::directEarner()).
            $msg .= " Placed on {$onCode}: the service income climbs up from {$onCode} exactly as before, and the direct bonus goes to {$payerCode}. {$payerCode} pays for this registration — confirm the payment plan and attach the slip from Pending Students; the account's holder sees the student as view only.";
        }

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
        abort_unless(RegistrationPayer::canHandle($consultant, $payment), 403);

        if ($payment->status !== 'pending') {
            return back()->with('error', 'This payment is no longer pending, so there is nothing to chase up.');
        }

        $payment->loadMissing(['user.student', 'course']);
        $user = $payment->user;

        if (! $user) {
            return back()->with('error', 'This registration has no student account attached to it.');
        }

        // ⚠️ A CERTAINLY-WRONG ADDRESS (2026-11-10) — say so, and BEFORE a new
        // temporary password is issued below: nothing could deliver it, and the
        // one the student was already given would stop working.
        if (($problem = \App\Support\EmailAddressCheck::problem($user->email)) !== null) {
            return back()->with('error', "Not sent to {$user->email}: {$problem} An administrator can correct it in User Management.");
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
            // ⚠️ THE NEW PASSWORD IS ALREADY SAVED (2026-11-10). Hiding it here left
            // the student with no working password at all — the old one replaced,
            // the new one never delivered — on every press while mail was down.
            return back()->with('error', "The email to {$user->email} could not be sent. Check Settings → Email and try again."
                .($newPassword ? " Their temporary password is now: {$newPassword} — give it to them yourself; the old one no longer works." : ''));
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
        abort_unless(RegistrationPayer::canHandle($consultant, $payment), 403);

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
            || $user->hasAnyRole(['super_admin', 'admin', 'accountant', 'lecturer', 'group_supporter', 'consultant'])
            || Payment::where('user_id', $user->id)
                ->where('id', '!=', $payment->id)
                ->where('status', 'verified')
                ->exists();

        // ⚠️ NOR WHILE ANOTHER OF THEIR REGISTRATIONS IS PAID FOR BY SOMEBODY THIS
        // CONSULTANT MAY NOT ACT FOR (2026-11-03, RegistrationPayer). Deleting the
        // person would take that registration with them through the cascade —
        // the one canHandle() refuses this consultant on its own row. Only
        // payments WITH a payer count, so every other case is exactly as before.
        $paidByAnother = ! $keepAccount && Payment::where('user_id', $user->id)
            ->whereKeyNot($payment->id)
            ->get()
            ->contains(fn (Payment $other) => RegistrationPayer::payerOf($other) !== null
                && ! RegistrationPayer::canHandle($consultant, $other));

        $keepAccount = $keepAccount || $paidByAnother;

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

        return back()->with('success', match (true) {
            $paidByAnother => "The unpaid registration {$payment->payment_code} was removed. {$studentName}'s account was kept because another of their registrations is paid for by another consultant.",
            $keepAccount => "The unpaid registration {$payment->payment_code} was removed. {$studentName}'s account was kept because it has other verified activity.",
            default => "{$studentName} was permanently deleted. That email address can be registered again straight away.",
        });
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
        abort_unless(RegistrationPayer::canHandle($consultant, $payment), 403);

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
            //
            // ⚠️ 2026-09-22: yesterday, today or tomorrow only, and never a day
            // a System Run already settled — see ConsultantPaymentDates.
            'payment_date' => \App\Support\ConsultantPaymentDates::rules(),
            // ⚠️ REQUIRED NOW, EXCEPT ON A FREE CARD — GOING-LIVE (2026-09-06).
            //
            // Both were nullable for testing, so a payment could be confirmed
            // with no evidence at all and the accountant had nothing to verify
            // against. A free card is the one genuine exception: there is no
            // money, so there is no slip and no reference to give — requiring
            // them would make free-card registrations impossible to confirm.
            'payment_reference_number' => ['required_unless:payment_type,free_card', 'nullable', 'string', 'max:120'],
            'payment_receipt' => ['required_unless:payment_type,free_card', 'nullable', 'file', 'mimes:pdf,png,jpg,jpeg', 'max:10240'],
        ], ['payment_date.in' => \App\Support\ConsultantPaymentDates::message()]);

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
        abort_unless(RegistrationPayer::canHandle($consultant, $payment), 403);

        if ($payment->status !== 'rejected') {
            return back()->with('error', 'Only a rejected payment can be re-submitted.');
        }

        // ⚠️⚠️ A REJECTED BALANCE PAYMENT STAYS A BALANCE PAYMENT (2026-09-30).
        //
        // Reported on SRN-07405: a verified half payment, then a balance slip
        // the accountant rejected — and "Re-attach Slip" offered Full / Half /
        // Free card, with FULL pre-selected (no option matched 'balance', so the
        // browser fell to the first one). This method then rewrote the row's
        // type and amount from whatever was picked. Re-submitted as "full" and
        // verified, the balance row would have become a brand-new full
        // purchase: the student billed the whole course a second time, and
        // CommissionService::handleVerifiedPayment() seeing a fresh purchase to
        // pay commission on. "eya half gewala thiyenne."
        //
        // So for a balance row the plan is not a choice. The type stays
        // 'balance' WHATEVER the form sends — an old copy of the page open on a
        // phone still posts payment_type=full, and that must not matter — and
        // the amount is what is actually left to pay, worked out the same way
        // payBalance() does. Everything else about re-submitting is unchanged.
        $isBalance = $payment->payment_type === 'balance';
        $balance = null;

        if ($isBalance) {
            // Another balance already on its way or settled: this rejected one
            // is dead, and re-submitting it would pay the balance twice.
            $alreadyOpen = Payment::where('user_id', $payment->user_id)
                ->where('course_id', $payment->course_id)
                ->where('payment_type', 'balance')
                ->whereIn('status', ['pending', 'verified'])
                ->whereKeyNot($payment->id)
                ->exists();

            if ($alreadyOpen) {
                return back()->with('error', 'A balance payment for this student has already been recorded — this rejected one cannot be re-submitted as well.');
            }

            // Worked out while this row is still REJECTED, which
            // remainingBalance() leaves out — so it is the course price less
            // what was genuinely paid, not less this slip itself.
            $balance = EnrollmentService::remainingBalance($payment->course, $payment->user_id);

            if ($balance <= 0) {
                return back()->with('error', 'This student has nothing left to pay on this course.');
            }
        }

        $data = $request->validate([
            // Not asked for a balance row — and ignored if sent (see above).
            'payment_type' => $isBalance ? ['nullable'] : ['required', 'in:full,half,free_card'],
            // Yesterday, today or tomorrow, never a settled day (2026-09-22).
            'payment_date' => \App\Support\ConsultantPaymentDates::rules(),
            // Required for a balance, exactly as payBalance() requires it: real
            // money always changes hands for a balance, so there is always a
            // slip number to give.
            'payment_reference_number' => $isBalance
                ? ['required', 'string', 'max:120']
                : ['nullable', 'string', 'max:120'],
            // Required here, unlike confirmPayment(): the payment was rejected,
            // and re-submitting the same evidence that was just turned down is
            // never the intent — a corrected slip is the whole point.
            'payment_receipt' => ['required', 'file', 'mimes:pdf,png,jpg,jpeg', 'max:10240'],
        ], ['payment_date.in' => \App\Support\ConsultantPaymentDates::message()]);

        $type = $isBalance ? 'balance' : $data['payment_type'];
        $amount = $isBalance ? $balance : EnrollmentService::amountForType($payment->course, $type);

        $receiptPath = $request->file('payment_receipt')->store('receipts', 'public');

        DB::transaction(function () use ($payment, $data, $receiptPath, $type, $amount) {
            $payment->update([
                'payment_type' => $type,
                'amount' => $amount,
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
        abort_unless(RegistrationPayer::canHandle($consultant, $payment), 403);

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

        // ⚠️ REQUIRED — GOING-LIVE (2026-09-06). No free-card exception here:
        // this is the BALANCE of a half payment, so real money is always
        // changing hands and there is always a slip to show for it.
        $data = $request->validate([
            // Yesterday, today or tomorrow, never a settled day (2026-09-22).
            'payment_date' => \App\Support\ConsultantPaymentDates::rules(),
            'payment_reference_number' => ['required', 'string', 'max:120'],
            'payment_receipt' => ['required', 'file', 'mimes:pdf,png,jpg,jpeg', 'max:10240'],
        ], ['payment_date.in' => \App\Support\ConsultantPaymentDates::message()]);

        // Genuinely before any transaction opens — see PaymentService::verify()
        // for why an ALTER TABLE cannot run mid-transaction.
        \App\Services\CommissionSchemaRepair::ensure();

        $receiptPath = $request->hasFile('payment_receipt')
            ? $request->file('payment_receipt')->store('receipts', 'public')
            : null;

        $balanceRow = [
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
        ];

        // ⚠️ THE BALANCE IS PAID BY WHOEVER PAID THE HALF (2026-11-03) — copied
        // only when one is recorded, so a database without the column is never
        // written to, and the holder stays view only for the balance too.
        // forceFill: the column is not mass-assignable (see RegistrationPayer).
        $payerId = $payment->getAttribute(RegistrationPayer::COLUMN);

        if ($payerId === null) {
            Payment::create($balanceRow);
        } else {
            (new Payment)->forceFill($balanceRow + [RegistrationPayer::COLUMN => (int) $payerId])->save();
        }

        return back()->with('success', 'Balance payment recorded. Awaiting accountant verification.');
    }
}
