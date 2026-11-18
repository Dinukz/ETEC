<?php

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use App\Models\Consultant;
use App\Models\SystemRun;
use App\Notifications\SystemRunCompleted;
use App\Notifications\SystemRunScheduled;
use App\Services\ServiceGroupFlush;
use App\Services\TransactionalMail;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * The daily SYSTEM RUN (owner-specified 2026-08-14).
 *
 * Replaces the per-account manual flush button that consultants had. Flushing
 * is now a single, network-wide operation an admin or accountant performs once
 * a day, settling the PREVIOUS day's activations:
 *
 *   1. Send the notice ("… System Run will be done at 1.00 pm today …") so
 *      consultants get a window to clear pending payments and report mistakes.
 *   2. Consultants fix what they can.
 *   3. Run it. Every eligible account flushes at once: the matched pair is
 *      released for payout and the unmatched remainder on the heavier leg is
 *      cancelled into company revenue.
 *
 * A run is IRREVERSIBLE and moves money across the whole network, so it is
 * deliberately a two-step action — the preview on index() has to be looked at
 * and the confirm token echoed back before run() will do anything. Every run
 * is recorded in `system_runs`.
 */
class SystemRunController extends Controller
{
    /** The preview screen: what today's run would do, plus the notice form. */
    public function index(Request $request, ServiceGroupFlush $flush)
    {
        // Genuinely pre-transaction, and this is a page load — the only safe
        // place to self-heal schema (MySQL implicitly commits on DDL).
        \App\Services\CommissionSchemaRepair::ensure();

        $cutoff = $this->cutoffFrom($request);

        // ⚠️ THE "HOLDING MONEY" LIST IS OFF BY DEFAULT (2026-09-14). Building it
        // walks every account holding a slot row and made the page slow enough to
        // 504; the flush list alone opens fast. The "Show these accounts" button
        // reloads with ?holding=1 to compute it. See ServiceGroupFlush::preview().
        $preview = $flush->preview($cutoff, includeSkipped: $request->boolean('holding'));

        $recentRuns = \Illuminate\Support\Facades\Schema::hasTable('system_runs')
            ? SystemRun::with('runBy')->latest('id')->take(10)->get()
            : collect();

        // Drives the "Send pending emails" button - see sendPendingNotices().
        $pendingNotices = \App\Services\PendingNotice::pendingCount();

        // Flag a date earlier than the last run's — see isEarlierThanLastRun().
        $lastRun = ServiceGroupFlush::lastRun();
        $earlierThanLast = $this->isEarlierThanLastRun($cutoff, $lastRun);

        // ⚠️ SLIPS THAT MISSED THEIR OWN RUN (2026-09-24). A payment banked on
        // the 21st but verified on the 22nd did not exist when the 21st run was
        // performed, so the next run sweeps it up and stamps it with ITS date —
        // reported as "eka penwann one 21 system run eke, nathuw 22 dawase
        // newei". Nothing told the operator the money was there, so it could
        // only ever be found after it had landed on the wrong day.
        $lateArrivals = ServiceGroupFlush::lateArrivals($lastRun);

        return view('dashboard.system-run.index', compact('preview', 'recentRuns', 'cutoff', 'pendingNotices', 'lastRun', 'earlierThanLast', 'lateArrivals'));
    }

    /**
     * Send the pre-run notice to every active consultant.
     *
     * Separate from the run itself on purpose: the whole value of the notice is
     * the gap between it and the run, so they must never be one button.
     */
    public function announce(Request $request)
    {
        $data = $request->validate([
            'run_at' => ['required', 'string', 'max:40'],
        ]);

        $today = Carbon::now('Asia/Colombo');
        $yesterday = $today->copy()->subDay();

        $activationsDate = $yesterday->format('jS \o\f F');
        $errorsDate = $yesterday->format('F j');

        $consultants = Consultant::real()
            ->with('user')
            ->where('status', 'active')
            ->get()
            ->filter(fn ($c) => $c->user);

        $sent = 0;
        $emailFailures = 0;

        foreach ($consultants as $consultant) {
            // In-app bell first — it cannot fail on a mail-server problem, and
            // it is the channel consultants actually watch.
            try {
                $consultant->user->notify(new SystemRunScheduled($data['run_at'], $activationsDate, $errorsDate));
            } catch (\Throwable $e) {
                report($e);
            }

            // Queued, not sent. Several hundred blocking SMTP round trips in
            // one request is what put this page over the proxy timeout.
            $ok = \App\Services\PendingNotice::queue(
                'system_run_notice',
                $consultant->user->email,
                $consultant->user->name,
                [$data['run_at'], $activationsDate, $errorsDate],
            );

            $ok ? $sent++ : $emailFailures++;
        }

        // "queued" not "sent": delivery is the outbox's job now, and saying
        // sent when nothing has left the building is how a stalled queue goes
        // unnoticed for a day.
        $message = 'System Run notice queued for '.$consultants->count().' consultant(s) — run time '.$data['run_at'].'. The in-app notification is already showing; emails go out in the background.';

        if ($emailFailures > 0) {
            $message .= " {$emailFailures} email(s) could not be sent (the in-app notification still went out).";
        }

        return back()->with('success', $message);
    }

    /**
     * Execute the run.
     *
     * The typed confirmation is not decoration: this permanently forfeits money
     * on every account in the network at once, and there is no undo anywhere in
     * this codebase — `consultants.total_earned` has no recompute path and
     * cancelled commissions are not reversible into a balance.
     */
    public function run(Request $request, ServiceGroupFlush $flush)
    {
        // A run walks every active consultant and moves money. PHP killing it
        // half way leaves the network partly settled, which is far worse than
        // it taking a while - so let it finish even if the browser gives up
        // first. (The proxy can still return 504 to the USER; the work
        // completes regardless, and flush() stamps released_at as it goes.)
        @set_time_limit(0);
        @ignore_user_abort(true);

        $request->validate([
            'confirm' => ['required', 'string'],
        ]);

        if (strtoupper(trim($request->string('confirm')->toString())) !== 'RUN') {
            return back()->with('error', 'Type RUN in the confirmation box to start the System Run.');
        }

        $cutoff = $this->cutoffFrom($request);

        // ⚠️ AN EARLIER DATE THAN THE LAST RUN MUST BE CONFIRMED (2026-09-13).
        //
        // Run #39 was given 9 Sep after runs #37/#38 had already used 10 Sep, and
        // nothing said so. Running for an old date is a normal thing to do, so it
        // stays possible — but at 3-to-3 it also closes off everything entered
        // AFTER that date (see ServiceGroupFlush::legPlan()), so doing it by
        // accident must not be. Checked here as well as on the page, because a
        // checkbox in a form is not a guard on its own.
        $lastRun = ServiceGroupFlush::lastRun();

        if ($this->isEarlierThanLastRun($cutoff, $lastRun) && ! $request->boolean('confirm_earlier')) {
            return $this->backToRun($cutoff)->with('error', sprintf(
                'That date is earlier than the last System Run, which settled up to %s. Tick the box to confirm you mean to run for %s.',
                $lastRun->cutoffLabel(),
                $cutoff->format('D j M'),
            ));
        }

        // ⚠️ DO NOT SWEEP AN OLDER DAY'S MONEY ONTO THIS ONE BY ACCIDENT
        // (2026-09-24).
        //
        // A run settles DATE(paid_at) <= its cutoff, so running the 22nd also
        // settles a 21st slip that was verified too late for the 21st run — and
        // that money is then recorded as the 22nd's. The owner reported exactly
        // that and called it wrong: "eka penwann one 21 system run eke, nathuw
        // 22 dawase newei."
        //
        // The sweep itself stays — money must never be stranded on a leg
        // because a date was missed. What changes is that it can no longer
        // happen silently: the older date is named, and the operator either
        // runs that date first or says they mean to fold it into this one.
        //
        // Checked here as well as on the page, for the same reason the earlier-
        // date guard above is: a checkbox in a form is not a guard.
        // ⚠️ NOT WHEN THIS IS ALREADY A DELIBERATE OLD-DATE RUN. Running an
        // earlier date is itself confirmed above (confirm_earlier), and the
        // money is landing on an OLDER day, not being dragged onto a newer one
        // — which is the only thing this guard exists to prevent. Asking for a
        // second tick there is noise, and it made an old-date run refuse
        // itself whenever any older money happened to be waiting.
        $sweptDates = $request->boolean('confirm_earlier')
            ? collect()
            : ServiceGroupFlush::lateArrivals($lastRun)
                ->filter(fn ($row) => $row['date'] < $cutoff->copy()->setTimezone('Asia/Colombo')->toDateString());

        // ⚠️ THE MESSAGE NO LONGER SENDS ANYONE TO AN OLD DATE (2026-10-01). It
        // said "Run 9 Sep instead". A run for an old date is an old-date run:
        // at 3-to-3 it closes off everything entered after that date to the
        // company (legPlan, owner's rule of 2026-09-14). Recommending it to
        // settle a few late rows could cost far more than it fixed.
        if ($sweptDates->isNotEmpty() && ! $request->boolean('confirm_sweeps_earlier')) {
            return $this->backToRun($cutoff)->with('error', sprintf(
                'LKR %s dated %s arrived after the last run, so this run will settle it on %s. Tick the box to confirm — the money is paid either way; only the date it is filed under changes.',
                number_format((float) $sweptDates->sum('amount'), 2),
                $sweptDates->map(fn ($row) => Carbon::parse($row['date'])->format('j M'))->join(', ', ' and '),
                $cutoff->format('j M'),
            ));
        }

        // ⚠️ SUPER-ADMIN TEST RUN (2026-08-24).
        //
        // The run itself was never time-limited — it can be started whenever.
        // What made it unusable for testing is the completion notice: every run
        // messages EVERY active consultant, so trying one out told the entire
        // network their income had been settled. Owner: "oana welwaka system
        // run eka yawnn puluwn wenn hadann, eka testing karann ona nisa, super
        // admin ta withrk hamben access ekak widiyta hadann."
        //
        // So a silent run exists, and the role is checked HERE rather than
        // trusted from the form: the route also admits admins and accountants,
        // and a hidden input is not an authorisation.
        $silent = $request->boolean('silent') && auth()->user()?->hasRole('super_admin');

        // ⚠️ SCOPED BY THE PICKED DATE (2026-09-14). For one evening this ignored
        // the date and settled everything, to make a 3-to-3 account end at zero.
        // The owner needs runs for old dates — "day wise select krann one" — so
        // the date scopes the run again, and the zero is made in legPlan().
        $result = $flush->runAll($cutoff);

        if (\Illuminate\Support\Facades\Schema::hasTable('system_runs')) {
            SystemRun::create([
                'run_by' => auth()->id(),
                'cutoff_at' => $result['cutoff'],
                'accounts_flushed' => $result['flushed'],
                'total_released' => $result['payout'],
                'total_forfeited' => $result['forfeit'],
                'accounts' => $result['accounts'] ? json_encode($result['accounts']) : null,
                'failures' => $result['failures'] ? implode("\n", $result['failures']) : null,
            ]);
        }

        // Fired for every genuinely-confirmed run, whether or not anything
        // actually flushed — the message is "today's activations are settled",
        // which is true either way, and matches the accounts unit's own
        // WhatsApp habit of always posting the "Has Been Done ✅" confirmation.
        //
        // A silent test run skips it. The MONEY still moves exactly the same
        // way: this only decides whether the network is told.
        if (! $silent) {
            $this->sendCompletionNotice($result['cutoff']);
        }

        $notice = $silent
            ? ' No notice was sent — this was a silent test run.'
            : ' Completion notice sent to all consultants.';

        if ($result['flushed'] === 0) {
            return $this->backToRun($cutoff)->with('success', 'System Run finished — no account had both service groups balanced, so nothing was flushed.'.$notice);
        }

        $message = 'System Run complete — '.$result['flushed'].' account(s) flushed. LKR '
            .number_format($result['payout'], 2).' released for approval, LKR '
            .number_format($result['forfeit'], 2).' of unmatched income went to company revenue.'.$notice;

        if ($result['failures']) {
            $message .= ' '.count($result['failures']).' account(s) errored and were skipped — see the run log.';
        }

        return $this->backToRun($cutoff)->with('success', $message);
    }

    /**
     * Back to the run screen ON THE SAME CUTOFF that was just settled.
     *
     * ⚠️ Was back(), which drops the ?cutoff and lands on the DEFAULT — end of
     * yesterday. So after settling with "Include today" the screen came back
     * showing yesterday, where every account still holding today's money reads
     * as unable to flush. Running twice in a row meant hunting for "Include
     * today" again each time: "system run eka onama parak krann include today
     * button eka obala SCN account wala thiyen amount tika flush out kragann".
     */
    protected function backToRun(Carbon $cutoff)
    {
        return redirect()->route('system-run.index', ['cutoff' => $cutoff->toDateString()]);
    }

    /**
     * Is the chosen date earlier than the date the last run settled up to?
     *
     * Compared as Sri Lanka DATE strings on purpose. cutoff_at is stored as
     * Sri Lanka wall-clock time and its datetime cast misreads it as UTC, so
     * comparing Carbon instances would be 5h30m off; the date part of the raw
     * value is exactly the day that was chosen.
     */
    protected function isEarlierThanLastRun(Carbon $cutoff, ?SystemRun $lastRun): bool
    {
        if (! $lastRun) {
            return false;
        }

        return strcmp($cutoff->toDateString(), substr((string) $lastRun->getRawOriginal('cutoff_at'), 0, 10)) < 0;
    }


    /**
     * The "System Run Has Been Done ✅" confirmation, to every active
     * consultant — the completion half of the pair started by announce().
     *
     * Dated by the CUTOFF (the day being settled), not today — matching
     * announce()'s activationsDate and the accounts unit's own wording
     * ("13th of August System Run Has Been Done", sent on the 14th).
     */
    /**
     * Undo one account's settlement from a past run.
     *
     * ⚠️ Super admin only, and checked HERE — the route group also admits
     * admins and accountants, and this un-books company revenue and re-credits
     * a consultant's totals.
     */
    public function reverseAccount(Request $request, SystemRun $run, \App\Services\SystemRunReversal $reversal)
    {
        abort_unless(auth()->user()?->hasRole('super_admin'), 403, 'Only a super admin can reverse a System Run.');

        $accountId = (int) $request->input('account_id');

        if ($accountId <= 0) {
            return back()->with('error', 'Pick which account to reverse.');
        }

        $result = $reversal->reverse($run, $accountId);

        return back()->with($result['ok'] ? 'success' : 'error', $result['message']);
    }

    /**
     * Send a batch of queued notices by hand.
     *
     * The scheduler drains the same outbox every minute, but only if the
     * server actually runs `schedule:run` - routes/console.php says in as many
     * words that it may not. This button means a stalled queue is visible and
     * fixable from the screen rather than silently never delivered.
     */
    public function sendPendingNotices()
    {
        @set_time_limit(0);

        $result = \App\Services\PendingNotice::drain(100);

        if ($result['sent'] === 0 && $result['remaining'] === 0) {
            return back()->with('success', 'No emails were waiting to be sent.');
        }

        $message = $result['sent'].' email(s) sent.';

        if ($result['failed'] > 0) {
            $message .= ' '.$result['failed'].' could not be delivered and will be retried.';
        }

        if ($result['remaining'] > 0) {
            $message .= ' '.$result['remaining'].' still waiting - press the button again to continue.';
        }

        return back()->with('success', $message);
    }

    protected function sendCompletionNotice(Carbon $cutoff): void
    {
        $settledDateLabel = $cutoff->format('jS \o\f F');

        $consultants = Consultant::real()
            ->with('user')
            ->where('status', 'active')
            ->get()
            ->filter(fn ($c) => $c->user);

        foreach ($consultants as $consultant) {
            try {
                $consultant->user->notify(new SystemRunCompleted($settledDateLabel));
            } catch (\Throwable $e) {
                report($e);
            }

            // Queued rather than sent — see App\Services\PendingNotice. The
            // in-app bell above is immediate; the email follows from the
            // outbox, either on the scheduler or from the "Send pending
            // emails" button on this screen.
            \App\Services\PendingNotice::queue(
                'system_run_completed',
                $consultant->user->email,
                $consultant->user->name,
                [$settledDateLabel],
            );
        }
    }

    /**
     * Which moment the run settles up to.
     *
     * DEFAULTS to the end of yesterday (Asia/Colombo) — the normal daily
     * rhythm the owner described, where a run settles the previous day's
     * activations.
     *
     * But the accountant may pick any date up to and including TODAY. The
     * first build clamped the ceiling to yesterday, which made same-day
     * registrations impossible to settle by any means: the date input would
     * not offer today, and posting it anyway was silently rewound. The owner
     * hit exactly that — "me tika mn kalin damm tikak eth run krann den na"
     * (2026-08-14) — with a preview stuck on 0 accounts and the Run button
     * disabled. Defaulting to yesterday keeps the routine; clamping to
     * yesterday removed a decision that is theirs to make.
     *
     * The future is still refused: a cutoff beyond today settles nothing extra
     * (nothing can be registered later than now) and only misleads.
     */
    protected function cutoffFrom(Request $request): Carbon
    {
        $default = ServiceGroupFlush::defaultCutoff();

        if (! $request->filled('cutoff')) {
            return $default;
        }

        try {
            $chosen = Carbon::parse($request->string('cutoff')->toString(), 'Asia/Colombo')->endOfDay();
        } catch (\Throwable $e) {
            return $default;
        }

        $ceiling = ServiceGroupFlush::latestSelectableCutoff();

        return $chosen->greaterThan($ceiling) ? $ceiling : $chosen;
    }
}
