<?php

namespace App\Concerns;

use App\Models\Commission;
use App\Models\CommissionSetting;
use App\Models\Consultant;

/**
 * The Service Income queue: how it is grouped for display, and every action an
 * accountant can take on it.
 *
 * ⚠️ SHARED BY THE CONTROLLER AND THE LIVEWIRE COMPONENT (2026-08-23).
 *
 * The queue is rendered in two places now — CommissionController for the
 * full-page load, and App\Livewire\ServiceIncomeBoard for the in-place updates
 * that replaced the old form-post-and-reload. Both read this one copy.
 *
 * That matters more here than anywhere else in the app: this codebase has
 * already been bitten three separate times by the same money rule living in
 * more than one place and drifting (the leg mapping, now consolidated into
 * Consultant::serviceGroupLeg(), and the unlock chain, now delegating to
 * ServiceGroupStaging). A second copy of the approval gates would be the same
 * mistake with worse consequences.
 *
 * Every action returns a plain result array rather than a redirect, so the
 * controller can turn it into a flash message and the component can render it
 * without either of them owning the decision.
 */
trait ManagesServiceIncome
{
    /** @return array{ok: bool, message: string, level: string} */
    protected function result(bool $ok, string $message, string $level = 'success'): array
    {
        return ['ok' => $ok, 'message' => $message, 'level' => $level];
    }

    /** Tell the consultant their income was approved, without ever failing the action. */
    protected function notifyApproved(Commission $commission): void
    {
        try {
            $commission->consultant?->user?->notify(new \App\Notifications\ServiceIncomeApproved($commission));
        } catch (\Throwable $e) {
            report($e);
        }
    }

    /**
     * Approve ONE row, forced — a human clicked this specific button and may
     * deliberately be releasing a stage that has not balanced.
     */
    protected function approveOne(Commission $commission): array
    {
        // Route-model binding loads nothing; `payment` backs
        // Commission::sourceConsultant() when this row is reported back.
        $commission->loadMissing('payment');

        if ($commission->status !== 'pending') {
            return $this->result(false, 'That record has already been actioned.');
        }

        $wasStaged = $commission->type !== \App\Services\CommissionService::TYPE_DIRECT
            && ! $this->slotIsReleased($commission);

        // force: a human clicked this specific row.
        if (! $this->creditApproved($commission, force: true)) {
            // The only gate left that can refuse is the hold one.
            return $this->result(false, "Could not approve — the consultant's income is currently held.", 'error');
        }

        $this->notifyApproved($commission);

        return $this->result(true, $wasStaged
            ? 'Service income approved for payout — released manually before its service group balanced a stage.'
            : 'Service income approved for payout.');
    }

    /**
     * Accountant marks an approved commission as paid.
     *
     * Already-approved money is never retroactively un-approved when a
     * consultant is later restricted (see ConsultantFinanceService::hold() —
     * it only ever touches PENDING rows, deliberately, since unwinding
     * total_earned has no recompute path anywhere in this codebase). This is
     * where that money is actually stopped from leaving instead: it stays
     * 'approved' and simply cannot be marked paid while the owner is
     * restricted or suspended.
     */
    protected function payOne(Commission $commission): array
    {
        if ($commission->status === 'approved'
            && ($commission->consultant?->owningPerson()?->isFinanceActive() ?? true)) {
            $commission->update(['status' => 'paid', 'paid_at' => now()]);
            $commission->consultant?->increment('total_paid', $commission->amount);
            $commission->consultant?->user?->notify(new \App\Notifications\ServiceIncomePaid($commission));
        }

        return $this->result(true, 'Service income marked as paid.');
    }

    /**
     * Every commission row minted by the SAME earning event as $commission —
     * the row itself plus the upline 2,500s that climbed from it.
     *
     * ⚠️ THE ENGINE'S OWN RULE, COPIED — NOT A SECOND ONE. This is the
     * expression CommissionService::placeInto() uses to answer "has this
     * earning event already paid this seat" (its $settledQuery). If the two
     * ever disagree, Remove would miss rows the engine considers part of the
     * same event, and the ledger would be left half-corrected.
     *
     * Matching by payment_id alone is not enough: a half payment mints the
     * 4,000 and its BALANCE mints all the 2,500s, so the two carry different
     * payment ids and are linked only through payments.enrollment_id. Rows
     * written before 2026-08-29 carry the SEAT's payment id rather than the
     * earning one, which the same fallback covers.
     */
    protected function siblingsOfEarningEvent(Commission $commission): \Illuminate\Support\Collection
    {
        $payment = $commission->payment;

        if (! $payment) {
            // Nothing to join on — the payment was deleted. The row stands
            // alone rather than being grouped with strangers.
            return collect([$commission]);
        }

        return Commission::query()
            ->where(function ($q) use ($payment) {
                $q->where('payment_id', $payment->id);

                if ($payment->enrollment_id) {
                    $q->orWhereIn('payment_id', \App\Models\Payment::where('enrollment_id', $payment->enrollment_id)->select('id'));
                } else {
                    $q->orWhereIn('payment_id', \App\Models\Payment::where('user_id', $payment->user_id)
                        ->where('course_id', $payment->course_id)
                        ->whereNull('enrollment_id')
                        ->select('id'));
                }
            })
            // The whole user chain: the confirmation panel names each row's
            // account by display_name, which reads user->name — or the OWNER's
            // name for a hidden placement account. preventLazyLoading() turns a
            // missing one into an exception, not a slow page.
            ->with(['consultant.user', 'consultant.owner.user', 'payment'])
            ->orderBy('id')
            ->get();
    }

    /**
     * What pressing Remove on this row would take off, without touching
     * anything — so the confirmation can name it before the click.
     *
     * @return array{allowed: bool, reason: ?string, rows: \Illuminate\Support\Collection, total: float}
     */
    protected function removalPreviewFor(Commission $commission): array
    {
        return $this->removalVerdict($this->siblingsOfEarningEvent($commission));
    }

    /**
     * May this set of rows be removed, and what would go with it?
     *
     * Split out from removalPreviewFor() so removeOne() can ask the SAME
     * question a second time about the rows it has just locked. The preview is
     * read outside any transaction to build the confirmation dialog, and
     * between that read and the click an accountant on another screen can mark
     * the very same row paid — so the answer given to the dialog is advisory,
     * and only this second reading decides.
     *
     * @param  \Illuminate\Support\Collection<int, Commission>  $rows
     * @return array{allowed: bool, reason: ?string, rows: \Illuminate\Support\Collection, total: float}
     */
    protected function removalVerdict(\Illuminate\Support\Collection $rows): array
    {
        $refuse = fn (string $reason) => [
            'allowed' => false, 'reason' => $reason, 'rows' => $rows, 'total' => 0.0,
        ];

        if ($rows->isEmpty()) {
            return $refuse('That record no longer exists.');
        }

        // ⚠️ PAID MONEY IS THE HARD LINE, and it is checked across the WHOLE
        // set, not just the clicked row. Removing a registration's 2,500 while
        // an upline 2,500 from the same event has already been paid out would
        // leave the ledger describing a payout for income that no longer
        // exists. Same rule ServiceGroupFlush and SystemRunReversal apply.
        if ($rows->contains(fn (Commission $c) => $c->status === 'paid')) {
            return $refuse('Some of this income has already been marked paid, so it cannot be removed. Reverse the payment first.');
        }

        $live = $rows->reject(fn (Commission $c) => $c->status === 'cancelled');

        if ($live->isEmpty()) {
            // Two very different situations, and telling them apart matters:
            // one says "you already did this", the other says "the money went
            // to the company and this button is not how you get it back".
            // Pointing at the wrong remedy is worse than saying nothing.
            return $refuse($rows->contains(fn (Commission $c) => $c->wasRemovedByHand())
                ? 'This income has already been removed — there is nothing left to take off.'
                : 'A System Run has already closed this income off to the company. Reverse the run for this account instead of removing it here.');
        }

        return [
            'allowed' => true,
            'reason' => null,
            'rows' => $live->values(),
            'total' => (float) $live->sum('amount'),
        ];
    }

    /**
     * Take a wrong commission off — the row itself AND every upline row the
     * same registration produced.
     *
     * Asked for 2026-09-06, going live: "2500 ho 4000 ewa one by one 0 krann
     * button ekak... SCN ekat adala hama thaninm 2500 hari 4000 hari 0 wenn
     * one." A miscalculated commission had no way off the books at all —
     * consultants.total_earned has no recompute path, and the only unwind was
     * a whole-account System Run reversal.
     *
     * ⚠️ CANCELLED, NEVER DELETED — AND THAT IS LOAD-BEARING, NOT TIDINESS.
     * placeInto()'s $alreadySettled and awardDirectBonus()'s $alreadyPaid both
     * ask "does a row exist for this seat / payment" WITHOUT filtering status.
     * A cancelled row therefore still answers yes and blocks re-creation. A
     * DELETED row would answer no, and the next Commission Health -> Repair
     * would mint the removed money straight back.
     *
     * ⚠️ WHAT THIS MUST NOT TOUCH, and why: the GroupMember seat, the group's
     * filled_slots / tier counters, students.consultant_id, company_revenues
     * and consultants.total_paid. The student really is still placed where
     * they were placed — only the money is wrong. Unwinding the placement too
     * would be the "parana logic" change the owner explicitly ruled out.
     */
    protected function removeOne(Commission $commission): array
    {
        $actorId = auth()->id();

        // ⚠️ WITHOUT THE COLUMNS, DO NOT ZERO. removed_amount is the only
        // record of what the figure was; zeroing `amount` before it exists
        // would destroy that with no way back. On a host where Commission
        // Health -> Repair has not been pressed yet, the row is still
        // cancelled and still leaves every leg and total — it just keeps its
        // printed amount until the columns arrive.
        $stamp = \App\Services\FinanceSchemaRepair::removalReady();

        $siblingIds = $this->siblingsOfEarningEvent($commission)->pluck('id')->all();

        return \Illuminate\Support\Facades\DB::transaction(function () use ($siblingIds, $actorId, $stamp) {
            // ⚠️ RE-READ UNDER A ROW LOCK, and judge again on what comes back.
            // The preview that built the confirmation dialog was read outside
            // any transaction, and an accountant on another screen can mark one
            // of these rows paid in between — the paid refusal has to be true at
            // the moment of writing, not at the moment of asking. lockForUpdate()
            // holds the set until this commits; it is a no-op on SQLite, which
            // is single-writer anyway.
            $rows = Commission::whereIn('id', $siblingIds)
                ->lockForUpdate()
                ->orderBy('id')
                ->get();

            $verdict = $this->removalVerdict($rows);

            if (! $verdict['allowed']) {
                return $this->result(false, $verdict['reason'], 'error');
            }

            $live = $verdict['rows'];
            $total = $verdict['total'];

            foreach ($live as $row) {
                // ⚠️ UNWIND WHAT APPROVAL ALREADY CREDITED — the exact mirror
                // of CommissionApproval::approve(), which is the only thing
                // that ever increments these two. Read BEFORE the status
                // changes, so the rows can still be told apart by it.
                //
                // Only an APPROVED row was ever counted in. A pending or held
                // row never reached the totals, so decrementing it would take
                // away money that was never added — the same reasoning
                // ServiceGroupFlush uses for its forfeit.
                if ($row->status === 'approved') {
                    Consultant::whereKey($row->consultant_id)->decrement('total_earned', (float) $row->amount);

                    // The GROUP total is slot income only — a direct-add bonus
                    // was never added to it, so it must not be taken off.
                    if ($row->group_id && $row->type !== \App\Services\CommissionService::TYPE_DIRECT) {
                        \App\Models\Group::whereKey($row->group_id)->decrement('total_commission_earned', (float) $row->amount);
                    }
                }

                $row->update(array_merge([
                    'status' => 'cancelled',
                    // Stamped for the same reason the forfeit stamps it: a
                    // cancelled row must never be picked back up as "still
                    // sitting on its leg" by a later flush.
                    'released_at' => now(),
                ], $stamp ? [
                    'removed_at' => now(),
                    'removed_by' => $actorId,
                    // ⚠️ THE AMOUNT IS ZEROED IN THE DATA, NOT HIDDEN BY A
                    // FILTER — this is what makes "SCN ekat adala hama thaninm
                    // 2500 hari 4000 hari 0 wenn one" hold. There are a dozen
                    // SUM(amount) readers across the dashboards, the reports
                    // and the consultant's own pages; excluding cancelled rows
                    // in each is only true until somebody writes the
                    // thirteenth. A row whose amount IS 0 reads 0 everywhere,
                    // including in code not written yet.
                    //
                    // The original figure is not lost — removed_amount keeps
                    // it, which is what the badge and any audit read. Nothing
                    // recomputes an amount from it, deliberately: putting the
                    // money back is a job for ManualCommissionEntry, by hand,
                    // with a reason.
                    'removed_amount' => (float) $row->amount,
                    'amount' => 0,
                ] : []));

                // The per-student figure printed on the Service Group Details
                // page (consultant/groups/show.blade.php). The System Run's
                // forfeit leaves this stale — a known asymmetry — but here it
                // is exactly the "hama thaninm 0 wenn one" the owner asked
                // for, so it is zeroed with the money.
                if ($row->group_member_id && $row->type !== \App\Services\CommissionService::TYPE_DIRECT) {
                    \App\Models\GroupMember::whereKey($row->group_member_id)->update(['commission_amount' => 0]);
                }
            }

            $count = $live->count();

            return $this->result(true, $count > 1
                ? "Removed LKR {$this->money($total)} across {$count} records, including the upline income from the same registration."
                : "Removed LKR {$this->money($total)}.");
        });
    }

    /** Money for a flash message — plain, no currency prefix. */
    protected function money(float $amount): string
    {
        return number_format($amount, 2);
    }

    /**
     * Approve every pending commission at once. Each verified student creates
     * its own rows (a direct bonus, plus slot income per leg as pairs settle),
     * so approving them one at a time does not scale.
     */
    protected function approveEverything(): array
    {
        // whereNull('held_at') so the message below reports what was actually
        // credited, not what was merely selected — creditApproved() would skip
        // a held row anyway, but counting it here would report "Approved LKR X"
        // while some of that X never moved.
        $pending = Commission::where('status', 'pending')->whereNull('held_at')->get();

        foreach ($pending as $commission) {
            // Unforced: "Approve all" must never release a half-finished stage.
            // Only the deliberate single-row button overrides the pair rule.
            if (! $this->creditApproved($commission)) {
                continue;
            }

            $this->notifyApproved($commission);
        }

        // Report what ACTUALLY moved, not what was selected: creditApproved()
        // silently skips slot income whose service group has not balanced its
        // stage yet, so counting the selection would claim to have approved
        // money that is still sitting there.
        $approved = Commission::whereIn('id', $pending->pluck('id'))->where('status', 'approved')->get();
        $skipped = $pending->count() - $approved->count();

        if ($approved->isEmpty()) {
            return $this->result(true, $skipped > 0
                ? "Nothing could be approved yet — {$skipped} record(s) are waiting for their service group to balance."
                : 'There was nothing pending to approve.');
        }

        $message = 'Approved '.$approved->count().' service income record(s), totalling LKR '.number_format((float) $approved->sum('amount'), 2).'.';

        if ($skipped > 0) {
            $message .= " {$skipped} more are still waiting for their service group to balance.";
        }

        return $this->result(true, $message);
    }

    /**
     * Approve everything pending for ONE consultant — across their own account
     * and every placement account they own, since that is one person's money.
     * The dashboard queue is worked person by person, so this is the action an
     * accountant actually reaches for.
     */
    protected function approveAllOwnedBy(Consultant $consultant): array
    {
        // display_name reads $this->user (or, for a hidden account, ->owner->user)
        // — route-model binding doesn't eager-load either, so load them now
        // rather than lazily, matching the pattern used everywhere else
        // display_name is shown off a bulk/route-bound Consultant.
        $consultant->loadMissing(['user', 'owner.user']);

        // A placement account is not a person; roll up to whoever owns it.
        $owner = $consultant->is_hidden ? $consultant->owner : $consultant;

        if (! $owner) {
            return $this->result(false, 'That account has no owning consultant.', 'error');
        }

        $pending = Commission::whereIn('consultant_id', $owner->accountIds())
            ->where('status', 'pending')
            ->whereNull('held_at')
            ->get();

        foreach ($pending as $commission) {
            // Unforced — see approveEverything().
            if (! $this->creditApproved($commission)) {
                continue;
            }

            $this->notifyApproved($commission);
        }

        // As above: report what actually moved. Slot income whose service group
        // has not balanced its stage is skipped by creditApproved(), so the
        // selection count would overstate it.
        $approved = Commission::whereIn('id', $pending->pluck('id'))->where('status', 'approved')->get();
        $skipped = $pending->count() - $approved->count();

        if ($approved->isEmpty()) {
            $reason = $owner->isFinanceActive() ? '' : ' (their income is currently held)';

            return $this->result(true, $skipped > 0
                ? "Nothing could be approved for {$owner->display_name} yet — {$skipped} record(s) are waiting for a service group to balance."
                : "Nothing was pending for {$owner->display_name}{$reason}.");
        }

        $message = 'Approved '.$approved->count().' record(s) for '
            .$owner->display_name.', totalling LKR '.number_format((float) $approved->sum('amount'), 2).'.';

        if ($skipped > 0) {
            $message .= " {$skipped} more are waiting for a service group to balance.";
        }

        return $this->result(true, $message);
    }

    /** Approve the rows on one account whose stage a flush has already released. */
    protected function approveBalancedStage(Consultant $consultant): array
    {
        $consultant->loadMissing(['user', 'owner.user']);

        $group = \App\Models\Group::where('consultant_id', $consultant->id)->orderBy('id')->first();

        if (! $group) {
            return $this->result(false, 'That account has no service group yet.', 'error');
        }

        // Selected through the SAME rule the approval path applies, so this
        // can never offer to move something creditApproved() would then
        // silently refuse. Upline rows qualify on their own merit (never
        // flushed), which is why whereNotNull('group_member_id') is not used.
        $releasable = Commission::where('consultant_id', $consultant->id)
            ->where('type', '!=', 'referral')
            ->where('status', 'pending')
            ->whereNull('held_at')
            ->with(['groupMember', 'payment'])
            ->get()
            ->filter(fn ($c) => $this->slotIsReleased($c));

        if ($releasable->isEmpty()) {
            return $this->result(true, 'Nothing is released yet on '
                .($consultant->scn_code ?? $consultant->consultant_code)
                .' — the consultant needs to flush their two service groups first.');
        }

        foreach ($releasable as $commission) {
            // Unforced: every row here was already selected BECAUSE its stage
            // balanced, so forcing would only mask a selection bug.
            if (! $this->creditApproved($commission)) {
                continue;
            }

            $this->notifyApproved($commission);
        }

        return $this->result(true, 'Approved the balanced stage on '
            .($consultant->scn_code ?? $consultant->consultant_code).' — LKR '
            .number_format((float) $releasable->sum('amount'), 2).' ('.$releasable->count().' record(s), both service groups together).');
    }

    /**
     * The dashboard queue, grouped by PERSON rather than as a flat stream of
     * rows. One student produces two separate records (slot income and the
     * direct-add bonus), and one consultant holds several accounts, so an
     * ungrouped list repeated the same name many times over and made it
     * impossible to see what a given consultant was actually owed.
     *
     * @return \Illuminate\Support\Collection<int, array<string, mixed>>
     */
    protected function recentIncomeByConsultant(int $people = 8)
    {
        $settings = CommissionSetting::current();

        // Pick the people to show by most recent activity. Income lands on an
        // ACCOUNT, so resolve each account up to whoever owns it first.
        // Cancelled rows excluded here too, or a consultant whose only recent
        // activity was a removal would still be listed — with an empty block,
        // since every query below now skips those rows.
        $recentAccountIds = Commission::where('status', '!=', 'cancelled')
            ->latest('id')->take(150)->pluck('consultant_id')->unique()->filter();

        $seenAccounts = \App\Models\Consultant::whereIn('id', $recentAccountIds)->get()->keyBy('id');

        $ownerIds = $recentAccountIds
            ->map(fn ($id) => $seenAccounts[$id] ?? null)
            ->filter()
            ->map(fn ($a) => $a->is_hidden ? $a->owner_consultant_id : $a->id)
            ->filter()
            ->unique()
            ->take($people)
            ->values();

        $owners = \App\Models\Consultant::whereIn('id', $ownerIds)
            ->with(['user', 'owner.user'])
            ->get()
            ->keyBy('id');

        // Every account each of those people owns, so nothing is missed.
        $accountIdsByOwner = $owners->mapWithKeys(fn ($o) => [$o->id => $o->accountIds()]);
        $allAccountIds = $accountIdsByOwner->flatten()->unique()->values()->all();

        // 'owner' eager-loaded: stagingProgress() below calls owningPerson()
        // on each of these (via ServiceGroupStaging::previousAccountId()),
        // which throws under Model::preventLazyLoading() if not preloaded.
        $accounts = \App\Models\Consultant::whereIn('id', $allAccountIds)->with('owner')->get()->keyBy('id');

        // 'payment' plus the consultant chain behind it back
        // 'payment.consultant.user' / '.owner.user' for
        // Commission::sourceConsultant() — names WHICH downline consultant's
        // registration produced an upline-credited row, shown in the per-leg
        // member list below.
        // ⚠️ CANCELLED ROWS ARE OFF THIS BOARD (2026-09-06). This one query
        // feeds EVERY row list on the page — the two service-group legs and
        // the direct-bonus block — so the filter belongs here and nowhere
        // else. Without it a removed 2,500 kept rendering with its full
        // amount, which is the whole of "hama thaninm 0 wenn one".
        //
        // Covers a System Run forfeit too, which was equally already gone from
        // the leg but still listed here.
        $rowsByAccount = Commission::whereIn('consultant_id', $allAccountIds)
            ->where('status', '!=', 'cancelled')
            ->with(['student', 'groupMember', 'payment', 'payment.consultant.user', 'payment.consultant.owner.user', 'consultant.owner'])
            ->latest('id')
            ->get()
            ->groupBy('consultant_id');

        // Leg completeness comes from a real aggregate, never from the rows on
        // screen — a leg is only releasable as a whole group once it is full.
        // "pending" here means genuinely approvable — a held row must not
        // inflate the "Approve group — LKR X" button below with money that
        // button cannot actually move.
        // Same filter as $rowsByAccount above, for the same reason: COUNT(*)
        // here drives the "N student(s)" figure beside each leg, and a removed
        // row kept being counted as a student on that leg.
        $legStats = Commission::whereIn('consultant_id', $allAccountIds)
            ->where('status', '!=', 'cancelled')
            ->where('type', '!=', 'referral')
            ->whereIn('tier', [1, 2])
            ->selectRaw("consultant_id, tier, COUNT(*) as filled,
                         SUM(CASE WHEN status = 'pending' AND held_at IS NULL THEN amount ELSE 0 END) as pending_total,
                         SUM(CASE WHEN status = 'pending' AND held_at IS NULL THEN 1 ELSE 0 END) as pending_count")
            ->groupBy('consultant_id', 'tier')
            ->get()
            ->groupBy('consultant_id');

        $rate = (float) $settings->commission_per_student;
        $capacityFor = fn (int $tier) => (int) ($tier === 1 ? $settings->first_tier_students : $settings->second_tier_students);

        return $ownerIds->map(function ($ownerId) use ($owners, $accountIdsByOwner, $accounts, $rowsByAccount, $legStats, $rate, $capacityFor) {
            $owner = $owners[$ownerId] ?? null;

            if (! $owner) {
                return null;
            }

            $ids = $accountIdsByOwner[$ownerId] ?? [$ownerId];
            $ownerRows = collect($ids)->flatMap(fn ($id) => $rowsByAccount[$id] ?? collect());

            // Where each of this person's accounts hangs, so the screen can
            // tell a GROUP HEAD (their main, or a bought set's own main) from
            // the two legs beneath it. Requested 2026-08-23 — nine similar
            // blocks in one list gave no clue which belonged to which:
            // "main account ekai sub account ekai color dekin dala denn".
            $placements = app(\App\Services\AccountPlacementMap::class)->for($owner);

            // Account → its two service groups → the records inside each.
            $accountBlocks = collect($ids)->map(function ($id) use ($accounts, $rowsByAccount, $legStats, $rate, $capacityFor, $placements) {
                $rows = $rowsByAccount[$id] ?? collect();
                $stats = ($legStats[$id] ?? collect())->keyBy('tier');

                // The account's flush state, so the queue can tell an accountant
                // WHY a row is not approvable and what the consultant has to do
                // about it (flush their two legs). Every individual row is also
                // marked whether IT SPECIFICALLY is released yet — without that
                // the per-row Approve button used to silently no-op with a false
                // "approved" message; see approve()'s docblock.
                $ctx = $this->stagingContext($accounts[$id] ?? null);

                $legs = collect([1, 2])->map(function ($tier) use ($rows, $stats, $rate, $capacityFor, $id) {
                    $capacity = $capacityFor($tier);
                    $stat = $stats[$tier] ?? null;

                    $legRows = $rows->where('type', '!=', 'referral')->where('tier', $tier)->values()
                        ->map(function ($row) {
                            // Delegated rather than re-derived: this is the same
                            // rule the approval path itself applies, so the
                            // button shown can never disagree with what
                            // clicking it actually does.
                            $row->is_released = $this->slotIsReleased($row);

                            return $row;
                        });

                    return [
                        'tier' => $tier,
                        'consultant_id' => $id,
                        'rows' => $legRows,
                        'filled' => (int) ($stat->filled ?? 0),
                        'capacity' => $capacity,
                        'leg_value' => $capacity * $rate,
                        'pending_total' => (float) ($stat->pending_total ?? 0),
                        'pending_count' => (int) ($stat->pending_count ?? 0),
                        'is_complete' => (int) ($stat->filled ?? 0) >= $capacity,
                    ];
                });

                $place = $placements[$id] ?? null;

                return [
                    'account' => $accounts[$id] ?? null,
                    'legs' => $legs,
                    // A GROUP HEAD is an account other accounts hang beneath:
                    // the person's own main, or a bought set's set-main. The
                    // opening pair and each set's two legs are not. Drives the
                    // dark/light colouring of the blocks.
                    'is_head' => in_array($place['kind'] ?? '', ['main', 'bought', 'bought-foreign', 'unknown'], true),
                    'placement' => $place,
                    'flush' => $ctx['flush'],
                    'releasable_amount' => $ctx['releasable_amount'],
                    // The direct bonus belongs to no service group — it is the
                    // registrant's own money and is listed apart from the legs.
                    'direct_rows' => $rows->where('type', 'referral')->values(),
                    'has_any' => $rows->isNotEmpty(),
                ];
            })->filter(fn ($b) => $b['account'] !== null && $b['has_any'])->values();

            // Excludes held rows — this drives the "Approve all" button, which
            // cannot actually move held money (see creditApproved()).
            $pending = $ownerRows->where('status', 'pending')->whereNull('held_at');
            $held = $ownerRows->whereNotNull('held_at');

            // Of that pending total, what "Approve all" can ACTUALLY move right
            // now: the direct bonus always (never staged), plus slot rows whose
            // account already balanced their stage (is_released, set above while
            // building $accountBlocks — same object instances, so it's visible
            // here). Reported separately from $pending so the collapsed header
            // never implies more is approvable than really is — the exact
            // "20,000 / 8 pending" figure that read as one lump sum, reported
            // 2026-08-11, is what this splits into "ready now" vs "waiting".
            $releasable = $pending->filter(fn ($r) => $r->type === 'referral' || ($r->is_released ?? false));

            return [
                'owner' => $owner,
                'accounts' => $accountBlocks,
                'row_count' => $ownerRows->count(),
                'slot_total' => (float) $ownerRows->where('type', '!=', 'referral')->sum('amount'),
                'direct_total' => (float) $ownerRows->where('type', 'referral')->sum('amount'),
                'pending_total' => (float) $pending->sum('amount'),
                'pending_count' => $pending->count(),
                'releasable_total' => (float) $releasable->sum('amount'),
                'releasable_count' => $releasable->count(),
                'waiting_total' => (float) $pending->sum('amount') - (float) $releasable->sum('amount'),
                'waiting_count' => $pending->count() - $releasable->count(),
                'held_total' => (float) $held->sum('amount'),
                'held_count' => $held->count(),
            ];
        })->filter()->values();
    }

    /**
     * Approve a whole balanced stage (1-to-1 / 2-to-2 / 3-to-3) on one
     * account, atomically — BOTH legs' members together, in one click.
     *
     * This replaces the old per-tier "approve a finished leg" action, which
     * no longer matches how release works: a release spans both Service Group
     * 1 AND 2 together, not one leg reaching a count. Using the old per-leg
     * action against a matched pair could approve just ONE side while its
     * partner sat pending — recorded income "leaking" out half a release at a
     * time with no error shown, which is exactly what this prevents.
     *
     * As of 2026-08-13 the CONSULTANT decides when a release happens (they
     * flush their two legs by hand — see ServiceGroupFlush); this action is
     * purely the accountant's side of it, approving whatever a flush has
     * already released.
     */

    /**
     * Mark one pending commission approved and add its amount to the
     * consultant's running total and their service group's total.
     *
     * The logic itself lives in CommissionApproval — the commission engine now
     * auto-approves upline income through the same service, and the two must
     * never drift apart. See that class for the three gates.
     *
     * @param  bool  $force  Override the staged-pair gate ONLY. Passed by the
     *                       single-row Approve button when an accountant has
     *                       explicitly chosen to release an unbalanced row.
     */
    protected function creditApproved(Commission $commission, bool $force = false): bool
    {
        // The actor is supplied HERE, not read inside the service: everything
        // approved through a controller was approved by a person, and
        // everything approved automatically at verification records NULL.
        return app(\App\Services\CommissionApproval::class)->approve($commission, $force, auth()->id());
    }

    /**
     * Staged-release progress for one account, or null when the account has no
     * service group yet (nothing to report) or the schema predates staging.
     *
     * @return array{progress: array|null, ranks: array<int, array<int, int>>}
     */
    protected function stagingContext(?\App\Models\Consultant $account): array
    {
        $empty = ['flush' => null, 'releasable_amount' => 0.0];

        if (! $account || ! \App\Services\FinanceSchemaRepair::stagingReady()) {
            return $empty;
        }

        $group = \App\Models\Group::where('consultant_id', $account->id)->orderBy('id')->first();

        if (! $group) {
            return $empty;
        }

        return [
            // What each leg is holding and what the consultant's next flush
            // would pay — the accountant cannot flush, but they need to be
            // able to tell someone WHY their money is not approvable yet.
            'flush' => app(\App\Services\ServiceGroupFlush::class)->state($account, $group),
            // Released by System Run but still pending review on this queue.
            'releasable_amount' => $this->releasableAmountFor($account),
        ];
    }

    /**
     * Slot income on one account that System Run has already released but that
     * is still pending review — i.e. exactly what "Approve all balanced" would
     * move.
     *
     * Held rows are excluded: that button cannot move them, so counting them
     * would put money on it that it cannot actually pay.
     */
    protected function releasableAmountFor(\App\Models\Consultant $account): float
    {
        if (! \App\Services\FinanceSchemaRepair::flushReady()) {
            return 0.0;
        }

        return (float) Commission::where('consultant_id', $account->id)
            ->where('type', \App\Services\CommissionService::TYPE_SLOT)
            ->where('status', 'pending')
            ->whereNull('held_at')
            ->whereNotNull('released_at')
            ->sum('amount');
    }

    /**
     * Has this slot commission's stage balanced yet? Thin delegate — the rule
     * lives in CommissionApproval so the approval path and the screens that
     * decide which button to show can never disagree about it.
     */
    protected function slotIsReleased(Commission $commission): bool
    {
        return app(\App\Services\CommissionApproval::class)->isReleased($commission);
    }
}
