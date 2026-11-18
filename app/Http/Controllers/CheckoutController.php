<?php

namespace App\Http\Controllers;

use App\Models\Consultant;
use App\Models\Course;
use App\Models\Enrollment;
use App\Services\EnrollmentService;
use Illuminate\Http\Request;

class CheckoutController extends Controller
{
    public function __construct(protected EnrollmentService $enrollments)
    {
    }

    public function show(Course $course)
    {
        abort_unless($course->status === 'published', 404);

        $existing = Enrollment::where('user_id', auth()->id())->where('course_id', $course->id)->first();
        if ($existing && in_array($existing->status, ['active', 'completed'], true)) {
            return redirect()->route('student.courses')->with('success', 'You are already enrolled in this course.');
        }

        $referralConsultant = $this->referralConsultant();

        return view('pages.checkout', [
            'course' => $course,
            'existing' => $existing,
            'referralConsultant' => $referralConsultant,
            'placementAccounts' => $this->placementOptions(),
            // Resolved here, never re-derived in Blade — store() enforces the
            // same rule, and two copies of "who may do this" is how they drift.
            'canIssueFreeCard' => $this->canIssueFreeCard(),
        ]);
    }

    /**
     * May this buyer enrol on a free card?
     *
     * Owner's decision, 2026-09-07: **consultants only.** A free card enrols
     * somebody for nothing, so it is not something a member of the public
     * should be able to ask for — but it is routine for a consultant, who
     * already has the same option on the Student Registration screen
     * (StudentController::confirmPayment()).
     *
     * A hidden placement account is a bucket, not a person, and never logs in;
     * `$user->consultant` only ever resolves a real one, but the is_hidden
     * check is stated rather than assumed.
     */
    protected function canIssueFreeCard(): bool
    {
        $consultant = auth()->user()?->consultant;

        return $consultant !== null && ! $consultant->is_hidden;
    }

    /**
     * The SCN accounts this buyer may place under, for the picker.
     *
     * ⚠️ SCOPED TO THE BUYER'S OWN NETWORK (corrected 2026-08-19).
     *
     * The first build listed EVERY consultant account in the system, so a
     * consultant logged in as SCN-00106 was offered SCN-00100..00105 —
     * strangers' accounts — and none of their own. Owner: "SCN-106 adala main
     * or hide or downline account newei wena account... log wela inn consultant
     * adala account tika withrk penwana widiyta [hadann]."
     *
     * That also matches what the placement is FOR: an existing consultant
     * buying another course opens a further SCN account inside THEIR OWN tree
     * ("eyta meke kamathi SCN number ekak yatath aluth SCN account eka place
     * kragann puluwn"), so the only sensible targets are their own accounts and
     * their downline's.
     *
     * A buyer who is not a consultant yet has no network, so the scope falls
     * back to whoever referred them — otherwise a brand-new buyer arriving on a
     * ?ref= link would face an empty picker.
     *
     * Suspended accounts are excluded on the same rule the ?ref= resolver uses
     * (Consultant::notFinanceSuspended) — a restricted consultant still
     * collects referrals, a suspended one does not.
     *
     * @return \Illuminate\Support\Collection<int, array<string, mixed>>
     */
    protected function placementOptions()
    {
        $consultant = auth()->user()?->consultant;

        // reachableAccountIds() is exactly "my accounts + my whole downline's
        // accounts" — the same boundary the Service Groups page authorises
        // against, so the two screens can never disagree about who is in reach.
        $scope = $consultant
            ? $consultant->reachableAccountIds()
            : ($this->referralConsultant()?->reachableAccountIds() ?? []);

        if ($scope === []) {
            return collect();
        }

        return Consultant::query()
            ->whereIn('id', $scope)
            ->whereNotNull('scn_code')
            ->where('status', '!=', 'suspended')
            ->notFinanceSuspended()
            ->with(['user:id,name', 'owner.user:id,name'])
            ->orderBy('scn_code')
            ->get()
            // ⚠️ SET-MAINS ARE NOT PLACEABLE (2026-08-22).
            //
            // Accounts arrive in sets of three: a set-main plus its two legs.
            // The set-main represents NO Service Group (serviceGroupLeg() is
            // null for it), so placing under it produces a placement that names
            // no leg. Owner: "Placement kraddi 4th eke main account eka hide
            // krann, mokada ethanat enn one 5th and 6th account."
            //
            // A person's own non-hidden MAIN account also has a null leg but
            // MUST stay offerable — there the Service Group is chosen
            // explicitly on the form instead.
            ->reject(fn (Consultant $account) => $account->is_hidden && $account->serviceGroupLeg() === null)
            ->map(fn (Consultant $account) => [
                'id' => $account->id,
                'code' => $account->scn_code,
                // display_name already resolves a hidden account to its owner's
                // name, so no extra suffix is needed to tell them apart.
                'label' => $account->scn_code.' — '.$account->display_name,
            ])
            ->values();
    }

    public function store(Request $request, Course $course)
    {
        abort_unless($course->status === 'published', 404);

        $data = $request->validate([
            'method' => ['required', 'in:bank_transfer,online,cash'],
            // ⚠️ PROOF IS REQUIRED HERE TOO (2026-09-10).
            //
            // Both of these were plain `nullable`, so a buyer could press
            // Submit for Verification with no slip and no reference at all and
            // the payment still landed in the accountant's queue as something
            // to check. Reported by the owner: "courses buy kran thana payment
            // eka validation run wen na".
            //
            // Same rule, same words, as the consultant's own registration
            // screen (StudentController::confirmPayment) — a free card is the
            // one plan with nothing to prove, because nothing is collected.
            'reference_number' => ['required_unless:payment_type,free_card', 'nullable', 'string', 'max:120'],
            'receipt' => ['required_unless:payment_type,free_card', 'nullable', 'file', 'mimes:pdf,png,jpg,jpeg', 'max:10240'],
            'notes' => ['nullable', 'string', 'max:1000'],
            // The SCN account this buyer places themselves under, and which of
            // its two Service Groups. Optional so a plain ?ref= purchase (and
            // every existing link) still works untouched.
            'placement_consultant_id' => ['nullable', 'integer'],
            'placement_tier' => ['nullable', 'in:1,2'],
            // A free card is a payment PLAN, not a payment method — `method`
            // stays whatever was picked. `payments.method` is a raw MySQL ENUM
            // with no doctrine/dbal in this app, so adding a value there would
            // need a hand-written ALTER; `payment_type` already accepts
            // 'free_card' (2026_07_17_090000, widened 2026_08_04_100000).
            'payment_type' => ['nullable', 'in:full,free_card'],
        ]);

        $existing = Enrollment::where('user_id', auth()->id())->where('course_id', $course->id)->first();
        if ($existing && in_array($existing->status, ['active', 'completed'], true)) {
            return redirect()->route('student.courses')->with('success', 'You are already enrolled in this course.');
        }

        // ⚠️ CHECKED HERE, NOT ONLY HIDDEN IN THE VIEW (2026-09-07).
        //
        // A free card enrols somebody for nothing, so who may ask for one is a
        // real gate, not a rendering detail — the option is simply absent from
        // the form for anyone else, and a hand-posted value must be refused the
        // same way a hand-posted placement id already is. Owner's choice:
        // consultants only. Staff still verify it before it counts, so this is
        // the first of two locks, not the only one.
        if (($data['payment_type'] ?? null) === 'free_card' && ! $this->canIssueFreeCard()) {
            return back()->withInput()->withErrors([
                'payment_type' => 'Only a consultant can issue a free card. Choose a payment plan.',
            ]);
        }

        // Re-resolved against the SAME allowlist the picker was built from, so
        // a hand-posted id cannot reach a suspended account, a non-consultant
        // row, or the buyer's own account. Never trust the submitted id alone.
        $placement = null;

        if ($request->filled('placement_consultant_id')) {
            $allowed = $this->placementOptions()->pluck('id')->all();

            if (! in_array((int) $data['placement_consultant_id'], $allowed, true)) {
                return back()->withInput()->withErrors([
                    'placement_consultant_id' => 'That Service Group account is not available to place under. Pick one from the list.',
                ]);
            }

            $placement = Consultant::find((int) $data['placement_consultant_id']);
        }

        // The Service Group is what decides which leg of the sponsor this
        // buyer's income climbs to, so it is required once an account is
        // picked — leaving it blank would fall back to fill order and the
        // unmatched side is eventually forfeited to the company at a flush.
        if ($placement && ! $request->filled('placement_tier')) {
            return back()->withInput()->withErrors([
                'placement_tier' => 'Choose Service Group 1 or 2 for the account you selected.',
            ]);
        }

        // ⚠️ AN EXISTING CONSULTANT MUST PICK — NO SILENT FALLBACK (2026-08-27).
        //
        // Below, a buyer who picked nothing falls back to
        // $request->user()->consultant — their own MAIN. For a brand-new student
        // that is harmless (they have no accounts and the sponsor chain takes
        // over). For someone who ALREADY holds accounts it is the bug the owner
        // has now reported three times:
        //
        //   The purchase opens a set of three, and AccountPlacementMap reads its
        //   position from this payment. With the main as the target and NO
        //   requested_tier, the set lands directly under the main and belongs to
        //   NEITHER Service Group. The main represents no group, so:
        //     - the climb stops instantly (creditOwnAccountAncestors breaks at
        //       the main), and every student added on that set credits only the
        //       account they sat on;
        //     - the account they thought they bought from earns nothing;
        //     - the main's rollup still counts the set-main as a direct child,
        //       so the money surfaces there instead.
        //
        // Reproduced exactly as reported: "2nd SCN account ekat calculate wen na
        // 2500, habai main account ekt calculate wenwa." A groupless set is also
        // where the "cross" comes from — with no owner group, the headline falls
        // back to each row's raw tier, so the money lands in whichever side the
        // student happened to be seated on.
        //
        // Picking the MAIN is still allowed — the form takes a Service Group for
        // it explicitly (see placementOptions), which gives the set a real group.
        // What is no longer allowed is picking NOTHING and being placed anyway.
        // ⚠️ SCOPED TO BUYERS WHO ARE THEMSELVES CONSULTANTS.
        //
        // NOT "the picker has options": for a plain student arriving on a
        // referral link the picker is populated from the SPONSOR's network, so
        // that test would have demanded a placement from an ordinary buyer and
        // broken the referral flow. Caught by
        // CheckoutResubmitPlacementTest::test_a_referral_still_sponsors_a_buyer_who_is_not_a_consultant.
        //
        // Only an existing consultant opens a set of three on purchase, so only
        // they can strand one under their own main.
        $buyerConsultant = $request->user()?->consultant;

        if (! $placement && $buyerConsultant && ! $buyerConsultant->is_hidden) {
            return back()->withInput()->withErrors([
                'placement_consultant_id' => 'Choose which of your SCN accounts this course is bought under. The new accounts are opened there, so it decides where the income calculates.',
            ]);
        }

        if ($request->hasFile('receipt')) {
            $data['receipt_path'] = $request->file('receipt')->store('receipts', 'public');
        }

        // An explicit pick beats the ?ref= link — the buyer chose it on this
        // screen, deliberately, over whatever link brought them here.
        //
        // ⚠️ AN EXISTING CONSULTANT IS NEVER "REFERRED" BY A LINK (2026-08-22).
        //
        // This used to fall straight through to referralConsultant() for
        // everyone. A consultant who had ever browsed someone else's ?ref= link
        // still had that code in their session, so their OWN course purchase
        // was attributed to that consultant — who then collected the 2,500 slot
        // income and the 4,000 direct bonus on a purchase they had nothing to
        // do with. The buyer, meanwhile, saw a stranger's SCN attached to the
        // accounts they had just paid for.
        //
        // A consultant buying another course is placing themselves inside their
        // OWN network, so with no deliberate pick the sponsor is their own
        // account: CommissionService recognises that as a self-purchase and
        // pays no slot or direct bonus on it, while still crediting their real
        // upline. Keeping the referral fallback for a buyer who is not a
        // consultant yet is correct — there the link IS how they arrived.
        $sponsor = $placement ?? $request->user()->consultant ?? $this->referralConsultant();

        if ($placement) {
            // Recorded on the payment, which is what CommissionService reads to
            // seat the student on the right leg, and what the promotion path
            // later reads to set the new consultant's own placement_tier.
            $data['requested_tier'] = (int) $data['placement_tier'];
        }

        $this->enrollments->enroll($request->user(), $course, $sponsor, $data);

        // ⚠️ The referral code deliberately SURVIVES checkout (2026-08-22).
        //
        // It used to be forgotten here. For a buyer who is not a consultant yet
        // that is their only network scope, so coming back to fix a detail —
        // the very thing this page invites, "Submitting again updates your
        // payment details" — showed them an empty placement picker and quietly
        // dropped the sponsor from the re-submit.
        //
        // Keeping it cannot mis-attribute: a fresh ?ref= link overwrites the
        // session, and EnrollmentService only re-points an existing student on
        // a DELIBERATE placement, never on a bare referral.

        return redirect()->route('student.courses')->with(
            'success',
            "Enrollment request for \"{$course->title}\" received. Your payment is pending verification — you'll get access as soon as it's confirmed.",
        );
    }

    /** Consultant captured from a ?ref= link at registration/browse time, if any. */
    protected function referralConsultant(): ?Consultant
    {
        $code = session('referral_code');

        return $code
            ? Consultant::where('referral_code', $code)->where('status', '!=', 'suspended')->notFinanceSuspended()->first()
            : null;
    }
}
