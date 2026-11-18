<?php

namespace App\Services;

use App\Models\Commission;
use App\Models\GroupMember;
use Illuminate\Support\Facades\DB;

/**
 * The single chokepoint where recorded income actually becomes the
 * consultant's money.
 *
 * This used to live as CommissionController::creditApproved(). It was lifted
 * into a service on 2026-08-12 so that every path which moves money runs the
 * identical gates — duplicating that logic is how a payout leak gets written.
 *
 * ----- The three gates, in order ------------------------------------------
 * 1. ALREADY ACTIONED — re-read under a row lock so a double-click, or two
 *    accountants racing, cannot credit the same row twice.
 * 2. HELD / FINANCE STATUS — a restricted or suspended consultant's income
 *    never moves. `held_at` is only a display marker everywhere else; here it
 *    is authoritative, and the owner's LIVE finance status is re-checked on
 *    top so a missed hold-write can never leak a payout. **$force does NOT
 *    bypass this** — nothing does.
 * 3. RELEASE — slot income is recorded per student straight away so the
 *    consultant can see it, but is only payable once a System Run has flushed
 *    the account's two legs (see ServiceGroupFlush). The direct-add bonus is
 *    never staged. **$force bypasses ONLY this gate**, and only ever because a
 *    human explicitly clicked one specific row.
 */
class CommissionApproval
{
    /**
     * Approve one pending commission and credit it.
     *
     * @param  bool  $force  Override the staged-pair gate only (gate 3). Never
     *                       overrides the hold / finance-status gate.
     * @param  int|null  $actorId  Who approved it. Taken AS GIVEN — this class
     *                             deliberately never reads auth() itself, so an
     *                             automatic approval records NULL rather than
     *                             stamping whichever accountant happened to be
     *                             verifying the payment at the time. That would
     *                             read in the audit trail as a human decision
     *                             nobody made. `commissions.approved_by` is
     *                             nullable, so NULL is legal.
     * @return bool True only when the row actually moved to 'approved'. Callers
     *              must not report success without checking this — silently
     *              flashing "approved" while nothing moved is a bug this
     *              codebase has already shipped once.
     */
    public function approve(Commission $commission, bool $force = false, ?int $actorId = null): bool
    {
        return (bool) DB::transaction(function () use ($commission, $force, $actorId) {
            // 'consultant.owner' resolves a hidden placement account up to
            // the real person for the finance-status check below.
            $fresh = Commission::whereKey($commission->id)
                ->with(['consultant.owner', 'payment'])
                ->lockForUpdate()
                ->first();

            if (! $fresh || $fresh->status !== 'pending') {
                return false;
            }

            // Gate 2 — never overridable.
            if ($fresh->held_at || ! ($fresh->consultant?->owningPerson()?->isFinanceActive() ?? true)) {
                return false;
            }

            // Gate 3 — the only one $force may skip.
            if (! $force && $fresh->type !== CommissionService::TYPE_DIRECT && ! $this->isReleased($fresh)) {
                return false;
            }

            $fresh->update([
                'status' => 'approved',
                'approved_by' => $actorId,
                'approved_at' => now(),
            ]);

            // The consultant's own total is all money they earned, both kinds.
            $fresh->consultant?->increment('total_earned', (float) $fresh->amount);

            // The GROUP total is slot income only. It is displayed against the
            // service-group cap, and a direct-add bonus is not service-group
            // income — counting it here filled the group's progress bar with
            // money the group never earned.
            if ($fresh->type !== CommissionService::TYPE_DIRECT) {
                $fresh->group?->increment('total_commission_earned', (float) $fresh->amount);
            }

            // Keep the caller's instance in step so a follow-up read (a flash
            // message, a notification) does not describe stale state.
            $commission->setRawAttributes($fresh->getAttributes(), true);

            return true;
        });
    }

    /**
     * Has this slot commission been released for payout yet?
     *
     * Release is not automatic: a System Run flushes an account's two legs once
     * both hold the stage threshold, and only the matched rows are stamped
     * `released_at` (see ServiceGroupFlush). Rows still sitting on a leg are
     * unreleased — an accountant can still push one through deliberately with
     * the per-row override, so nothing is ever permanently stuck.
     *
     * ⚠️ UPLINE INCOME IS NO LONGER EXEMPT (owner-confirmed 2026-08-14).
     *
     * Between 08-12 and 08-14 a downline-sourced 2,500 short-circuited straight
     * to "released" and was auto-approved at verification, because at the time
     * an ancestor's legs could not realistically balance. The owner has since
     * described the intended model in full: downline money accumulates on the
     * ancestor's legs exactly like their own students and releases at 7,500 /
     * 7,500 in a System Run. Approval and release are separate axes now:
     * normal slot rows auto-approve at verification, then wait on the leg for
     * System Run release.
     *
     * This is only workable because an ancestor is credited on the leg the
     * downline BRANCH sits on (CommissionService::sideUnderParent()) — a
     * consultant with downline on both legs accumulates on both and balances.
     * A consultant with downline on ONE leg only does not, and that money waits
     * (and is forfeited when the other leg eventually reaches the threshold).
     * The owner was shown that consequence with worked numbers and chose it.
     *
     * Falls back to the previous rank-vs-`released_depth` rule while the
     * `released_at` column does not exist, so a half-applied schema behaves
     * like the old build rather than freezing everyone's income.
     */
    public function isReleased(Commission $commission): bool
    {
        if (! $commission->group_member_id) {
            return true;
        }

        if (! FinanceSchemaRepair::flushReady()) {
            $member = GroupMember::find($commission->group_member_id);

            return $member ? app(ServiceGroupStaging::class)->isReleased($member) : true;
        }

        return $commission->released_at !== null;
    }
}
