<?php

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use App\Models\Commission;
use App\Models\CommissionSetting;
use App\Models\Consultant;
use App\Models\GroupMember;
use App\Models\Payment;
use App\Models\Setting;
use App\Services\CommissionService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * "Commission Health" — why is a student's income missing?
 *
 * There is no CLI on this host, so an admin needs to be able to see the real
 * state of the commission engine and repair it from the browser. Reports the
 * configuration the engine actually reads, what was recorded for recent
 * payments, and a plain-language verdict; the repair action applies pending
 * migrations, clears caches and replays history for payments verified before
 * a rule existed.
 */
class CommissionHealthController extends Controller
{
    public function index()
    {
        $settings = CommissionSetting::current();

        $hasColumn = Schema::hasColumn('commission_settings', 'direct_commission');
        $hasSlotsColumn = Schema::hasColumn('commissions', 'slots');
        $directRate = (float) ($settings->direct_commission ?? 0);
        $slotRate = (float) ($settings->commission_per_student ?? 0);

        $config = [
            'course_price' => (float) ($settings->course_price ?? 0),
            'slot_rate' => $slotRate,
            'direct_rate' => $directRate,
            'half_amount' => (float) Setting::get('half_payment_amount', 18000),
            'tier1' => (int) ($settings->first_tier_students ?? 0),
            'tier2' => (int) ($settings->second_tier_students ?? 0),
            'has_direct_column' => $hasColumn,
            'row_id' => $settings->id,
        ];

        // Is the deployed code the current code? A stale upload is the single
        // most common reason a fix appears to have done nothing.
        $code = [
            'Bonus paid at verification' => method_exists(CommissionService::class, 'awardDirectBonus'),
            'Backfill command available' => class_exists(\App\Console\Commands\BackfillCommissions::class),
            'Network tree page' => class_exists(\App\Http\Controllers\Consultant\NetworkController::class),
            'Downline walks placement accounts' => $this->downlineFixPresent(),
            'Backfill can retroactively award slots to already-placed students' => $this->retroactiveSlotFixPresent(),
            // The 2026-08-09 rules. Both of these deliberately assert the
            // OPPOSITE of what this page checked before that date — group
            // income is staged/paired again, and the direct bonus is uncapped
            // — so a green tick here means the new build really is deployed.
            // ⚠️ RE-POINTED 2026-09-07 — it was asking for a method deleted long
            // ago. This read `method_exists(CommissionService::class,
            // 'settleStages')`, and settleStages() has not existed since the
            // release model moved out of the engine and into the System Run. So
            // this row printed "the uploaded code is out of date" on every
            // correct build, and the page's headline could never go green —
            // which is exactly what CommissionHealthCodeChecksTest now forbids.
            //
            // What actually implements staged release today is
            // ServiceGroupFlush::stageOf() / stepForAccount() (the per-account
            // 1-to-1 / 2-to-2 / 3-to-3 thresholds), with ServiceGroupStaging
            // still owning the unlock chain. Ask for those.
            'Group income is released in stages (1-to-1 / 2-to-2 / 3-to-3)' => class_exists(
                \App\Services\ServiceGroupStaging::class,
            ) && method_exists(\App\Services\ServiceGroupFlush::class, 'stageOf'),
            'Direct bonus is uncapped' => ! method_exists(CommissionService::class, 'seatIsWithinCap'),
            'Accounts unlock in sequence after 12 students' => method_exists(
                \App\Services\ServiceGroupStaging::class, 'isUnlocked',
            ),
            'Group totals count service-group income only' => method_exists(
                \App\Console\Commands\BackfillCommissions::class, 'resyncGroupTotals',
            ),
            'Cached settings self-heal after a column is added' => method_exists(
                CommissionSetting::class, 'resolveActive',
            ),
            // 2026-10-22: the Elliott Wave course pays 5,000 direct, every other
            // course the standard. Green = the live engine really reads it.
            //
            // ⚠️ class_exists() TOO: the same day, App\Support\CourseMoney was
            // missing on live while CommissionService.php was new, and a check
            // on the method alone stayed green over a broken Courses page.
            'Each course can set its own direct bonus' => method_exists(
                CommissionService::class, 'directRateFor',
            ) && class_exists(\App\Support\CourseMoney::class),
            // 2026-10-22: a repeat purchase earns the account it is placed on
            // its own 2,500 (RepeatPurchaseRules). Needs both files.
            'A repeat purchase earns its own 2,500' => method_exists(
                CommissionService::class, 'paysPerPurchase',
            ) && class_exists(\App\Services\RepeatPurchaseRules::class),
            // 2026-11-16: registering on a downline's account, the direct bonus
            // goes to Requested By (the 2,500 still climbs from the account).
            'Requested By earns the direct bonus on a downline account' => class_exists(
                \App\Services\RegistrationPayer::class,
            ) && method_exists(\App\Services\RegistrationPayer::class, 'directEarner'),
        ];

        // Split genuinely-pending migrations from ones whose table already
        // exists — the latter are only a bookkeeping problem, but they are what
        // makes `php artisan migrate` abort with "table already exists".
        $pending = [];
        $drifted = [];

        foreach ($this->pendingMigrations() as $name => $path) {
            $table = $this->tableCreatedBy($path);

            if ($table && Schema::hasTable($table)) {
                $drifted[] = $name;
            } else {
                $pending[] = $name;
            }
        }

        $payments = Payment::with(['user:id,name,email'])->latest('id')->take(10)->get();

        $seats = GroupMember::whereIn('payment_id', $payments->pluck('id'))
            ->join('groups', 'groups.id', '=', 'group_members.group_id')
            ->get(['group_members.payment_id', 'group_members.tier', 'group_members.commission_amount', 'groups.consultant_id'])
            ->groupBy('payment_id');

        $money = Commission::whereIn('payment_id', $payments->pluck('id'))->get()->groupBy('payment_id');

        $accountCodes = Consultant::whereIn('id', $seats->flatten()->pluck('consultant_id')->merge($money->flatten()->pluck('consultant_id'))->unique())
            ->pluck('scn_code', 'id');

        $rows = $payments->map(fn (Payment $p) => [
            'code' => $p->payment_code,
            'student' => $p->user->name ?? '—',
            'email' => $p->user->email ?? '—',
            'status' => $p->status,
            'plan' => $p->payment_type,
            'amount' => (float) $p->amount,
            'consultant_id' => $p->consultant_id,
            'verified_at' => $p->verified_at,
            'seats' => ($seats[$p->id] ?? collect())->map(fn ($s) => [
                'account' => $accountCodes[$s->consultant_id] ?? ('#'.$s->consultant_id),
                'tier' => (int) $s->tier,
                'settled' => (float) $s->commission_amount,
            ])->values(),
            'money' => ($money[$p->id] ?? collect())->map(fn ($c) => [
                'kind' => $c->type === 'referral' ? 'Direct Bonus' : 'Group Income',
                'amount' => (float) $c->amount,
                'tier' => $c->tier,
                'account' => $accountCodes[$c->consultant_id] ?? ('#'.$c->consultant_id),
            ])->values(),
        ]);

        // Payments the engine has never been able to look at again.
        $missingBonus = Payment::where('status', 'verified')
            ->whereNotNull('consultant_id')
            ->whereDoesntHave('commissions', fn ($q) => $q->where('type', 'referral'))
            ->count();

        $problems = [];

        foreach ($code as $label => $ok) {
            if (! $ok) {
                $problems[] = "The uploaded code is out of date — \"{$label}\" is missing. Re-upload the app files, then clear caches.";
            }
        }

        if (! $hasColumn) {
            $problems[] = 'The direct_commission column does not exist yet. Run Repair below to apply the pending migration.';
        } elseif ($directRate <= 0) {
            $problems[] = 'The Direct Add Bonus is set to 0, so no bonus can ever be paid. Set it under Settings → Service Groups & Income, then run Repair.';
        }

        if (! $hasSlotsColumn) {
            $problems[] = 'The commissions.slots column does not exist yet. This will silently break service-group income the first time a slot is awarded. Run Repair below.';
        }

        if ($slotRate <= 0) {
            $problems[] = 'The Service Group Slot amount is 0, so no group income can ever be paid.';
        }

        if ($drifted) {
            $problems[] = count($drifted).' migration(s) were applied to this database but never recorded, which is why "php artisan migrate" fails with "table already exists". Use "Fix Migration Records" below — it only updates the bookkeeping and runs no SQL against your data.';
        }

        if ($pending) {
            $problems[] = count($pending).' migration(s) have genuinely not been applied yet.';
        }

        if ($missingBonus > 0 && $directRate > 0) {
            $problems[] = "{$missingBonus} verified payment(s) have no direct bonus recorded — they were verified before the rule existed. Run Repair to record what they missed.";
        }

        // Pending rows are exceptions under the current rule. Normal direct,
        // hidden slot, and upline income auto-approve at payment verification.
        $awaiting = Commission::where('status', 'pending')->count();

        if ($awaiting > 0) {
            $amount = number_format((float) Commission::where('status', 'pending')->sum('amount'));
            $problems[] = "{$awaiting} service income exception(s) worth LKR {$amount} are still pending review or on hold. Normal direct bonus and 2,500 slot/upline income should auto-approve at payment verification; use the Service Income page to review these exceptions.";
        }

        // Upline money sitting on the wrong Service Group of its ancestor.
        // Read-only here on purpose: an admin sees the count and a sample
        // before deciding to move anything.
        $legMismatches = collect();

        try {
            $legMismatches = app(\App\Services\UplineLegAudit::class)->mismatches();
        } catch (\Throwable $e) {
            report($e);
        }

        // Counted the same way the backfill approves, so the number on the
        // button is the number the admin gets — a preview that overstates is
        // the exact class of bug the System Run screen already had.
        $historicApprovable = app(\App\Services\AutoApprovalBackfill::class)->pending();

        // Courses bought on a free card that never opened their SCN accounts
        // (2026-09-14) — see MissingAccountSets.
        $missingAccountSets = app(\App\Services\MissingAccountSets::class)->payments()->count();

        // ⚠️ Money hidden by a held_at stamp from before 2026-09-15, when
        // missing the weekly quota hid a consultant's whole balance. Nothing is
        // lost — the stamp only makes legScopeQuery() skip the row — so this is
        // a count of what is invisible, and the button below puts it back. See
        // ConsultantFinanceService::applySideEffects().
        $heldIncome = ['count' => 0, 'amount' => 0.0];

        try {
            if (Schema::hasColumn('commissions', 'held_at')) {
                $held = \App\Models\Commission::whereNotNull('held_at')->where('status', '!=', 'cancelled');

                $heldIncome = [
                    'count' => (int) (clone $held)->count(),
                    'amount' => (float) (clone $held)->sum('amount'),
                ];
            }
        } catch (\Throwable $e) {
            report($e);
        }

        return view('dashboard.commission-health.index', compact('config', 'code', 'pending', 'drifted', 'rows', 'problems', 'missingBonus', 'legMismatches', 'historicApprovable', 'missingAccountSets', 'heldIncome'));
    }

    /**
     * Make the commission configuration sound, clear caches, then replay history.
     *
     * Deliberately does NOT run `migrate`. This database has migrations whose
     * tables already exist but were never recorded in the ledger, so a blanket
     * migrate aborts on "table already exists" and repairs nothing. The one
     * schema change commissions actually need is applied directly and
     * idempotently instead; ledger drift is a separate, opt-in fix.
     */
    /**
     * Move upline commissions that are sitting on the WRONG Service Group of
     * their ancestor onto the right one.
     *
     * Separate from repair(): that one heals SCHEMA and re-runs the engine over
     * payments that recorded nothing. This one corrects the LEG of money that
     * was recorded successfully but routed by the wrong rule — the 2026-08-19
     * placement_tier override, and the older seat-tier fallback that governed
     * every account beyond the first two.
     *
     * Fixing the engine cannot do this retroactively: a 2,500 written against
     * tier 2 stays on tier 2. Reported 2026-08-20 as the totals still looking
     * wrong after the rule itself was corrected.
     *
     * Read-only until submitted — index() shows the count first, so an admin
     * sees what would move before anything does.
     */
    /**
     * ⚠️ READ-ONLY — writes nothing, safe to open on production.
     *
     * "Could every consultant's SCN number be their SRN number?" The answer
     * depends on live data, and there is no CLI on prod, so it is counted here.
     * See ScnNumberAudit for why the rule is not a straightforward change.
     */
    public function scnNumbers(\App\Services\ScnNumberAudit $audit)
    {
        return view('dashboard.commission-health.scn-numbers', $audit->run());
    }

    /**
     * READ-ONLY: check every recorded rupee against the rules, in one pass.
     *
     * Asked for 2026-08-29 — "check krgnn manual krn nathuw kramyk nadda?" The
     * only way to answer that before was to open one payment at a time. See
     * CommissionRuleAudit for the twelve rules and why the upline test looks for
     * a GAP rather than recomputing the chain.
     *
     * ?limit=N caps it to the newest N payments, so a large network can be spot
     * checked without waiting for a full walk.
     */
    public function ruleAudit(Request $request, \App\Services\CommissionRuleAudit $audit)
    {
        $limit = max(0, (int) $request->integer('limit'));

        return view('dashboard.commission-health.rule-audit', $audit->run($limit) + [
            'limit' => $limit,
            'rules' => \App\Services\CommissionRuleAudit::RULES,
        ]);
    }

    /**
     * Put a stage back to what the account actually paired for.
     *
     * ⚠️ SUPER ADMIN ONLY — this changes what an account has to reach before it
     * can be paid again.
     *
     * The stages this corrects were written by a bug: runAll() advanced an
     * account whenever somebody else flush released its rows, so accounts
     * drained by their main climbed the ladder without ever balancing their own
     * two sides, then needed 7,500 a side and stopped running. The bug is fixed,
     * but nothing in a normal run corrects a stage that is already wrong.
     */
    public function repairStages()
    {
        abort_unless(auth()->user()?->hasRole('super_admin'), 403, 'Only a super admin can change an account stage.');

        // ⚠️⚠️ STAGES ARE NEVER MOVED BACK (2026-09-14) — THIS CHANGES NOTHING NOW.
        //
        // It put every stage higher than the pairings recorded in system_runs
        // back down to that count. But a stage an ADMIN set ("Move to stage"),
        // or one seeded when an account was unlocked, has no pairing on record
        // by definition — so pressing it sent those accounts back to 1-to-1.
        // Reported: "damm stage aye mul stage walt add wela thiyeno ... dana
        // stage eka thama digtam thiyenne ... admin dana stage eke ho auto yana
        // stage eke thama digatam thiyenne". The route stays so an old page
        // cannot 500; the rule-audit page no longer offers the button.
        return back()->with('error', 'Account stages only move forward, so nothing was changed. A stage above its recorded pairings was set by an admin or when the account was unlocked.');
    }

    /**
     * ⚠️ WHERE EACH BOUGHT ACCOUNT SITS, AND A WAY TO CORRECT IT.
     *
     * A bought set's position is derived from the account chosen at checkout.
     * When nothing was chosen, it resolves to the main or to nothing, the climb
     * has no parent to walk to, and the upline 2,500 never reaches the account
     * above — reported for SCN-00122: "5th account eke upline ekedi 2nd account
     * ekat calculate wela na, main account ekat gihilla cross wela."
     *
     * Read-only. The correction is repointPlacement() below.
     */
    public function placements(Request $request)
    {
        abort_unless($request->user()?->hasRole('super_admin'), 403, 'Only a super admin can see account placements.');

        $rows = [];

        Consultant::query()
            ->whereNull('owner_consultant_id')
            ->whereNotNull('scn_code')
            ->with('user')
            ->orderBy('scn_code')
            ->chunkById(100, function ($owners) use (&$rows) {
                foreach ($owners as $owner) {
                    $map = app(\App\Services\AccountPlacementMap::class)->for($owner);
                    $accounts = Consultant::where('owner_consultant_id', $owner->id)
                        ->orderBy('id')->get(['id', 'scn_code', 'placed_under_consultant_id']);

                    foreach ($accounts as $account) {
                        $entry = $map[$account->id] ?? null;

                        if (! $entry || ! in_array($entry['kind'] ?? '', ['bought', 'bought-foreign', 'unknown'], true)) {
                            continue;
                        }

                        // Only the ones a person actually needs to look at: a
                        // set whose climb cannot reach an earning account.
                        $broken = ($entry['kind'] !== 'bought') || ($entry['under_id'] === (int) $owner->id);

                        $rows[] = [
                            'id' => $account->id,
                            'code' => $account->scn_code,
                            'owner' => $owner->display_name,
                            'owner_code' => $owner->scn_code,
                            'kind' => $entry['kind'],
                            'under_code' => $entry['under_code'],
                            'corrected' => (bool) $account->placed_under_consultant_id,
                            'broken' => $broken,
                            'options' => $accounts->where('id', '!=', $account->id)
                                ->pluck('scn_code', 'id')->all(),
                        ];
                    }
                }
            });

        return view('dashboard.commission-health.placements', ['rows' => $rows]);
    }

    /**
     * Re-point one bought account, then replay its income so the 2,500s that
     * never climbed finally do.
     *
     * ⚠️ SUPER ADMIN ONLY, and it writes NOTHING to the payment — see the
     * column's note in FinanceSchemaRepair. Rewriting payments.consultant_id
     * would move who earned the purchase, which is real money.
     */
    public function repointPlacement(Request $request)
    {
        abort_unless($request->user()?->hasRole('super_admin'), 403, 'Only a super admin can move an account placement.');

        $data = $request->validate([
            'consultant_id' => ['required', 'integer', 'exists:consultants,id'],
            'under_id' => ['required', 'integer', 'exists:consultants,id'],
            'tier' => ['required', 'in:1,2'],
        ]);

        $account = Consultant::findOrFail($data['consultant_id']);
        $target = Consultant::findOrFail($data['under_id']);

        if ($account->id === $target->id) {
            return back()->with('error', 'An account cannot sit under itself.');
        }

        // Both must belong to the same person, or the climb would route income
        // out of the owner's own tree.
        if ($account->owningPerson()?->id !== $target->owningPerson()?->id) {
            return back()->with('error', 'Both accounts must belong to the same consultant.');
        }

        $account->forceFill([
            'placed_under_consultant_id' => $target->id,
            'placement_tier' => (int) $data['tier'],
        ])->save();

        return back()->with('success', sprintf(
            '%s now sits under %s (Service Group %d). Run "Replay missing income" to credit the uplines it never reached.',
            $account->scn_code, $target->scn_code, $data['tier'],
        ));
    }

    /**
     * Consultants whose upline was cut by a deletion, and where each belongs.
     *
     * ⚠️ WHY THIS SCREEN EXISTS. `consultants.parent_consultant_id` is
     * ON DELETE SET NULL, so deleting one person used to silently empty the
     * upline of everyone below them — no error, no record, and their whole
     * branch simply stopped appearing on their real sponsor's screens. Found
     * 2026-09-11 after Anishka Prabath's line vanished from SCN-00109.
     *
     * The deletion itself is now healed as it happens (see
     * UserManagementController::destroy), so this page is for the damage that
     * was already done — and for the rare case a branch cannot be healed
     * automatically and a human has to choose.
     */
    /**
     * Hand entries missing a main account's row — listed, never changed here.
     *
     * ⚠️ SUPER ADMIN ONLY, checked in the method: the route group also admits
     * admins, and this page leads straight to a button that writes money.
     */
    public function manualMains(Request $request, \App\Services\ManualEntryMainRepair $repair)
    {
        abort_unless($request->user()?->hasRole('super_admin'), 403, 'Only a super admin can complete hand-entered commissions.');

        return view('dashboard.commission-health.manual-mains', $repair->preview());
    }

    /** Add exactly the rows manualMains() listed. */
    public function repairManualMains(Request $request, \App\Services\ManualEntryMainRepair $repair)
    {
        abort_unless($request->user()?->hasRole('super_admin'), 403, 'Only a super admin can complete hand-entered commissions.');

        $result = $repair->apply($request->user()->id);

        if ($result['created'] === 0) {
            return back()->with('success', 'Every hand entry already reaches its main accounts — nothing was added.');
        }

        return back()->with('success', sprintf(
            'Added %d missing row(s), LKR %s in total. Each one completes a hand entry that stopped short of a main account.',
            $result['created'],
            number_format($result['amount'], 2),
        ));
    }

    public function brokenUplines(Request $request)
    {
        abort_unless($request->user()?->hasRole('super_admin'), 403, 'Only a super admin can repair the network tree.');

        return view('dashboard.commission-health.broken-uplines', [
            'orphans' => \App\Services\UplineContinuity::orphans(),
        ]);
    }

    /**
     * Reattach one orphan to a sponsor.
     *
     * ⚠️ ONE AT A TIME, BY HAND. The suggestion is read out of the audit log
     * and is usually right, but this decides where a whole branch's future
     * income climbs, so nothing is applied in bulk and nothing is applied
     * without somebody pressing the button for that row.
     */
    public function reconnectUpline(Request $request)
    {
        abort_unless($request->user()?->hasRole('super_admin'), 403, 'Only a super admin can repair the network tree.');

        $data = $request->validate([
            'consultant_id' => ['required', 'integer', 'exists:consultants,id'],
            'sponsor_id' => ['required', 'integer', 'exists:consultants,id'],
        ]);

        $orphan = Consultant::findOrFail($data['consultant_id']);
        $sponsor = Consultant::findOrFail($data['sponsor_id']);

        try {
            \App\Services\UplineContinuity::reconnect($orphan, $sponsor);
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) {
            return back()->with('error', $e->getMessage());
        }

        // ⚠️ DO NOT SHORTEN THIS TO "no money was moved" (corrected 2026-09-11).
        //
        // That was the original wording and it was true for about a second.
        // creditUpline() walks parent_consultant_id LIVE, never from history —
        // nothing anywhere records the chain as it stood when a payment was
        // verified. So the moment this link is restored, Commission Health →
        // Repair will mint the upline income this branch never earned while it
        // was cut, auto-approved. That is correct behaviour and it is what the
        // owner usually wants, but it must not be a surprise.
        $paid = \App\Models\Payment::where('status', 'verified')
            ->where('amount', '>', 0)
            ->whereIn('consultant_id', array_merge(
                $orphan->accountIds(),
                \App\Models\Consultant::whereIn('owner_consultant_id', $orphan->downlineIds())->pluck('id')->all(),
                $orphan->downlineIds(),
            ))
            ->count();

        return back()->with('success', sprintf(
            '%s now sits under %s. Their whole branch is back in that network. Income already recorded is unchanged. '
            .'⚠️ This branch holds %d paid registration(s) whose upline income never climbed while it was cut — '
            .'pressing Commission Health → Repair will now credit those uplines for the first time. '
            .'Free-card registrations pay nobody, so they are not affected.',
            $orphan->scn_code ?: $orphan->consultant_code,
            $sponsor->scn_code ?: $sponsor->consultant_code,
            $paid,
        ));
    }

    public function repairLegs(\App\Services\UplineLegAudit $audit)
    {
        $result = $audit->repair();

        if ($result['corrected'] === 0) {
            return back()->with('success', 'Every upline commission is already on the correct Service Group — nothing to move.');
        }

        return back()->with('success', sprintf(
            '%d upline commission(s) moved onto the correct Service Group. %s',
            $result['corrected'],
            implode(' · ', array_slice($result['details'], 0, 10)),
        ));
    }

    /**
     * Apply the current auto-approval rules to income recorded before they
     * existed. See AutoApprovalBackfill for exactly what it will and will not
     * touch. System Run release remains separate from approval.
     */
    public function approveHistoric(\App\Services\AutoApprovalBackfill $backfill)
    {
        $result = $backfill->run();

        if ($result['approved'] === 0) {
            $note = $result['skipped_held'] > 0
                ? sprintf(' %d record(s) were skipped because that income is held.', $result['skipped_held'])
                : '';

            return back()->with('success', 'Nothing historical left to approve — every direct bonus and upline credit is already approved.'.$note);
        }

        $message = sprintf(
            'Approved %d historical record(s) totalling LKR %s — %d direct bonus, %d upline credit. They still need marking as paid.',
            $result['approved'],
            number_format($result['amount'], 2),
            $result['direct'],
            $result['upline'],
        );

        if ($result['skipped_held'] > 0) {
            $message .= sprintf(' %d skipped — that income is held.', $result['skipped_held']);
        }

        return back()->with('success', $message);
    }

    public function repair()

    {
        $settings = CommissionSetting::current();

        if ((float) ($settings->commission_per_student ?? 0) <= 0) {
            return back()->with('error', 'The Service Group Slot amount is 0 — set it in Settings before repairing, or nothing would be recorded.');
        }

        $log = [];

        try {
            $log = array_merge($log, \App\Services\CommissionSchemaRepair::ensure());

            Artisan::call('optimize:clear');
            $log[] = 'Cleared caches.';
        } catch (\Throwable $e) {
            report($e);

            return back()->with('error', 'Could not repair the commission settings: '.$e->getMessage());
        }

        $rate = (float) (CommissionSetting::current()->direct_commission ?? 0);

        if ($rate <= 0) {
            return back()
                ->with('error', 'The Direct Add Bonus is still 0. Set it under Settings → Service Groups & Income, then run Repair again.')
                ->with('repair_output', implode("\n", $log));
        }

        try {
            Artisan::call('commissions:backfill');
            $log[] = Artisan::output();
        } catch (\Throwable $e) {
            report($e);

            return back()
                ->with('error', 'Settings were repaired, but replaying history failed: '.$e->getMessage())
                ->with('repair_output', implode("\n", $log));
        }

        return back()->with('success', 'Repair complete.')->with('repair_output', trim(implode("\n", $log)));
    }

    /**
     * Record migrations whose table already exists but which were never written
     * to the `migrations` ledger — the state that makes `php artisan migrate`
     * abort with "table already exists". Only ever records a migration whose
     * CREATE TABLE target is genuinely already there; never runs any SQL.
     */
    public function reconcileMigrations()
    {
        $recorded = DB::table('migrations')->pluck('migration')->all();
        $batch = (int) DB::table('migrations')->max('batch');
        $fixed = [];

        foreach (glob(database_path('migrations/*.php')) as $path) {
            $name = pathinfo($path, PATHINFO_FILENAME);

            if (in_array($name, $recorded, true)) {
                continue;
            }

            $table = $this->tableCreatedBy($path);

            if ($table && Schema::hasTable($table)) {
                DB::table('migrations')->insert(['migration' => $name, 'batch' => $batch]);
                $fixed[] = $name;
            }
        }

        if (! $fixed) {
            return back()->with('success', 'Nothing to reconcile — no pending migration builds a table that already exists.');
        }

        return back()
            ->with('success', 'Marked '.count($fixed).' already-applied migration(s) as recorded.')
            ->with('repair_output', implode("\n", $fixed));
    }

    /** The table a migration creates, if it is a create-table migration. */
    protected function tableCreatedBy(string $path): ?string
    {
        $source = @file_get_contents($path);

        if ($source && preg_match('/Schema::create\(\s*[\'"]([^\'"]+)[\'"]/', $source, $match)) {
            return $match[1];
        }

        return null;
    }

    /**
     * True when Consultant::downlineIds() seeds the walk from all accounts.
     *
     * ⚠️ ASKS FOR A METHOD, NOT FOR A LINE OF SOURCE (2026-09-07).
     *
     * This used to `str_contains($source, '$frontier = $this->accountIds();')`.
     * That literal was moved on 2026-09-07 — the walk was extracted into
     * downlineIdsBelow() so the Service Groups page could root it at ONE
     * account — and this check immediately began reporting "The uploaded code
     * is out of date" against a perfectly correct build, on the very page an
     * owner is told to press Repair from. A false alarm here is worse than no
     * check: it tells someone to re-upload files that are already right.
     *
     * downlineIdsBelow() EXISTS only because the walk was made to seed from an
     * arbitrary account set, so its presence asserts the fix more truthfully
     * than any substring could — and it cannot rot the next time a line moves.
     * Every other entry in index()'s $code map is a method_exists/class_exists
     * for the same reason; this one was the odd one out.
     */
    protected function downlineFixPresent(): bool
    {
        return method_exists(Consultant::class, 'downlineIdsBelow');
    }

    /** True once the 2026-08-02 fix removed the 1-to-1 pairing requirement for slot income. */
    /**
     * True once CommissionService::placeInto() can settle a slot for a student
     * who was already placed (has a GroupMember) before this rule existed —
     * without this, "Run Repair" can never retroactively pay the 2500 slot
     * income to students registered under an older rule version, even though
     * it can still backfill a missing direct bonus for them.
     */
    protected function retroactiveSlotFixPresent(): bool
    {
        $file = (new \ReflectionClass(CommissionService::class))->getFileName();
        $source = $file ? @file_get_contents($file) : false;

        return $source !== false && str_contains($source, '$alreadySettled');
    }

    /**
     * Migrations with no row in the `migrations` ledger, as name => file path.
     *
     * @return array<string, string>
     */
    protected function pendingMigrations(): array
    {
        $ran = DB::table('migrations')->pluck('migration')->all();

        return collect(glob(database_path('migrations/*.php')))
            ->mapWithKeys(fn ($path) => [pathinfo($path, PATHINFO_FILENAME) => $path])
            ->reject(fn ($path, $name) => in_array($name, $ran, true))
            ->all();
    }

    /**
     * Open the SCN accounts that courses bought on a free card never opened.
     *
     * ⚠️ SUPER ADMIN ONLY — it allocates SCN numbers. Safe to press twice: each
     * purchase opens one set, marked with its payment id. See MissingAccountSets.
     */
    public function openMissingAccountSets(\App\Services\MissingAccountSets $sets)
    {
        abort_unless(auth()->user()?->hasRole('super_admin'), 403, 'Only a super admin can open accounts from here.');

        $opened = $sets->open();

        return back()->with('success', $opened === 0
            ? 'Every course bought on a free card already has its accounts.'
            : "{$opened} course purchase(s) made on a free card now have their set of SCN accounts.");
    }

    /**
     * Put back income hidden by the old weekly-quota hold — "Put Back Held Income".
     *
     * ⚠️ SUPER ADMIN ONLY, and safe to press twice (a second press clears
     * nothing and says so).
     *
     * Until 2026-09-15, missing the weekly student quota stamped held_at on
     * every pending/approved row the person owned. legScopeQuery() skips held
     * rows, so their Service Group legs all printed LKR 0 at the Tuesday
     * boundary and it read as money wiped. The stamp is the ONLY thing hiding
     * it: clearing it restores the balance exactly. Cancelled rows are left
     * alone — that money really did leave.
     */
    public function releaseHeldIncome()
    {
        abort_unless(auth()->user()?->hasRole('super_admin'), 403, 'Only a super admin can put held income back.');

        if (! Schema::hasColumn('commissions', 'held_at')) {
            return back()->with('error', 'This database has no held_at column yet — press Run Repair first.');
        }

        $rows = \App\Models\Commission::whereNotNull('held_at')
            ->where('status', '!=', 'cancelled')
            ->get(['id', 'amount']);

        if ($rows->isEmpty()) {
            return back()->with('success', 'No income is being held — there was nothing to put back.');
        }

        $amount = (float) $rows->sum('amount');

        \App\Models\Commission::whereIn('id', $rows->pluck('id'))->update(['held_at' => null]);

        try {
            activity()->withProperties(['records' => $rows->count(), 'amount' => $amount])
                ->log('Put back held income — '.$rows->count().' record(s), LKR '.number_format($amount, 2));
        } catch (\Throwable $e) {
            report($e);
        }

        return back()->with('success', $rows->count().' record(s) worth LKR '
            .number_format($amount, 2).' are showing on their Service Groups again.');
    }
}
