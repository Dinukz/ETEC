<?php

namespace App\Models;

use App\Concerns\Auditable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Commission extends Model
{
    use Auditable, HasFactory;

    protected $fillable = [
        'consultant_id', 'group_id', 'group_member_id', 'user_id', 'payment_id',
        'amount', 'slots', 'tier', 'type', 'status', 'held_at', 'released_at', 'approved_by', 'approved_at', 'paid_at',
        // The day the money arrived, not the day this row appeared (2026-09-10).
        // See incomeDate() below.
        'earned_at',
        // Hand removal (2026-09-06) — see ManagesServiceIncome::removeOne().
        'removed_at', 'removed_by', 'removed_amount',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'held_at' => 'datetime',
            // When a manual flush-out unlocked this slot row for payout.
            // NULL = still sitting on its service-group leg, counting towards
            // that leg's flush balance. See ServiceGroupFlush.
            'released_at' => 'datetime',
            'approved_at' => 'datetime',
            'paid_at' => 'datetime',
            'removed_at' => 'datetime',
            'removed_amount' => 'decimal:2',
            'earned_at' => 'datetime',
        ];
    }

    /**
     * The date this income is FOR — what every screen should show.
     *
     * ⚠️ NOT created_at. A commission row is only inserted when an accountant
     * presses Verify, so on a payslip attached on the 1st and verified on the
     * 8th, created_at says the 8th. Reported by the owner 2026-09-10: "payslip
     * eka attach kran date eka thama gann one."
     *
     * created_at is deliberately left alone as the row's insert time — it is
     * the audit trail, and it is what tells you an old payment was verified
     * late. This reads the payment's own date instead, falling back when there
     * is none: hand-entered income has no payment, and the day it was typed in
     * is the right answer for it.
     *
     * Returns null only if the column is absent AND the row somehow has no
     * created_at, which cannot happen for a saved row.
     */
    public function incomeDate(): ?\Illuminate\Support\Carbon
    {
        return $this->earned_at ?? $this->created_at;
    }

    /**
     * The status a person sees, without ever renaming the stored value. A held
     * row is still `status = 'pending'` underneath — see the held_at migration
     * for why — so every existing status-based query keeps working unchanged.
     *
     * ⚠️ 'removed' JOINED 'held' AS A DISPLAY-ONLY VALUE (2026-09-06), for the
     * same reason and with the same guarantee: nothing is stored as 'removed',
     * so no status query changes. A row an admin struck out and a row a System
     * Run forfeited to the company are both `cancelled`, and showing both as
     * "Cancelled" would tell a consultant the company took money that an admin
     * simply corrected. `removed_at` is what tells them apart.
     */
    public function getDisplayStatusAttribute(): string
    {
        if ($this->status === 'cancelled' && $this->removed_at !== null) {
            return 'removed';
        }

        return $this->held_at ? 'held' : $this->status;
    }

    /**
     * Was this row earned by climbing the sponsor chain (someone in this
     * consultant's DOWNLINE registered the student), rather than by this
     * account's own placement?
     *
     * Upline income is NOT pair-staged — it pays 2,500 per student
     * immediately, confirmed by the owner 2026-08-12. Only a consultant's own
     * placements build toward 1-to-1 / 2-to-2 / 3-to-3. This matters because
     * an ancestor is always credited on the leg the downline BRANCH sits on
     * (CommissionService::sideUnderParent()), so a busy branch piles every
     * student onto one leg where they could never find a partner to balance
     * against — money stuck permanently, which is what prompted the change.
     *
     * Derived rather than stored: `payments.consultant_id` records which
     * account actually registered the student, so a commission credited to a
     * DIFFERENT account can only have come from the upline walk. No schema
     * change needed, and it stays correct for rows created before this rule.
     *
     * Requires `payment` to be eager-loaded (Model::preventLazyLoading() is
     * on outside production). When the payment is missing/unloadable this
     * returns FALSE — the conservative direction, since false keeps the row
     * staged rather than releasing money early.
     */
    public function isUplineCredit(): bool
    {
        $registrantAccountId = $this->payment?->consultant_id;

        return $registrantAccountId !== null
            && (int) $registrantAccountId !== (int) $this->consultant_id;
    }

    /**
     * For an upline-credited row, the account that actually registered the
     * student — i.e. who this income came FROM. Null for a row that is not an
     * upline credit, since there is no separate "source" to name: the
     * consultant on the row already IS who earned it by their own placement.
     *
     * This is the downline consultant (often several levels down the sponsor
     * chain, not necessarily the immediate child) whose OWN registration
     * climbed up to credit the ancestor shown on `consultant`. Both may be
     * hidden placement accounts — `display_name` already resolves a hidden
     * account to "<owner's name> (service group account)", so callers can use
     * it directly without checking is_hidden themselves.
     *
     * Requires `payment.consultant.user` and `payment.consultant.owner.user`
     * eager-loaded (Model::preventLazyLoading() is on outside production).
     */
    public function sourceConsultant(): ?Consultant
    {
        return $this->isUplineCredit() ? $this->payment?->consultant : null;
    }

    /**
     * Did this row come from the consultant BUYING A COURSE THEMSELVES, rather
     * than from registering somebody else?
     *
     * Asked for 2026-09-06: a consultant buying a new course through one of
     * their own SCN accounts mints a 2,500, and the row looked no different
     * from any other — "course ekak buy kra kiyla methan penwann, e buy krla
     * thama methant 2500 add une kiyla danagann."
     *
     * ⚠️ SAME TEST AS THE ENGINE, NOT A SECOND ONE. This is exactly
     * CommissionService::isSelfPurchase() — the buyer's user id equals the
     * user id of the person who owns the credited account. That method is
     * `protected`, so a view cannot call it; this is the public reading of the
     * same rule, and the two must not drift. Resolved through owningPerson()
     * so it still catches a purchase routed through a HIDDEN placement
     * account, which has no user_id of its own.
     *
     * Only SLOT rows can ever answer true: a self-purchase deliberately
     * withholds the 4,000 direct bonus (CommissionService, 2026-08-19), so no
     * referral row exists to ask.
     *
     * Requires `payment` and `consultant.owner` eager-loaded
     * (Model::preventLazyLoading() is on outside production). Missing payment
     * reads FALSE — the conservative direction, since it only ever withholds a
     * label, never money.
     */
    public function isCoursePurchase(): bool
    {
        $buyerUserId = $this->payment?->user_id;
        $ownerUserId = $this->consultant?->owningPerson()?->user_id;

        return $buyerUserId !== null
            && $ownerUserId !== null
            && (int) $buyerUserId === (int) $ownerUserId;
    }

    /**
     * Was this row taken off by hand, rather than forfeited by a System Run?
     *
     * Both end up `status = 'cancelled'` and both mean the money has left the
     * consultant — no money query needs to tell them apart. This exists so the
     * REASON is not lost: without it an accountant's correction is
     * indistinguishable from company overflow.
     *
     * Degrades to false when the column has not been created yet, which is
     * exactly true on a database where nothing has ever been removed.
     */
    public function wasRemovedByHand(): bool
    {
        return \App\Services\FinanceSchemaRepair::removalReady()
            && $this->removed_at !== null;
    }

    /**
     * Was this slot income released by a HUMAN rather than by a System Run?
     *
     * A flush stamps `released_at`; the per-row "Approve" button deliberately
     * does not (it passes force: true, skipping the release gate entirely).
     *
     * Worth surfacing because the alternative is unexplainable: an account can
     * show "Waiting for 2-to-2" above a list of rows that are already Paid, and
     * nothing on screen says why. That was reported on 2026-08-15 for SCN-00110
     * and could not be answered from any screen.
     *
     * ⚠️ `released_at IS NULL` alone stopped being sufficient on 2026-08-17.
     * A manually-approved row now stays on its leg until a System Run sweeps
     * it (see ServiceGroupFlush::legScopeQuery()), and that sweep stamps
     * `released_at` — which would have erased the badge from every historic
     * manual approval the moment a run touched the account. The order of the
     * two timestamps is what actually answers the question: approved BEFORE it
     * was released means a human went first.
     *
     * The direct-add bonus is never flushed, so it is never "manual" — it is
     * excluded rather than flagged on every single row.
     */
    public function wasManuallyReleased(): bool
    {
        if ($this->type === \App\Services\CommissionService::TYPE_DIRECT) {
            return false;
        }

        if (! in_array($this->status, ['approved', 'paid'], true)) {
            return false;
        }

        // Approved, and no run has swept it off its leg yet.
        if ($this->released_at === null) {
            return true;
        }

        // Swept since — still a manual release if the approval came first.
        // `approved_at` is null for rows approved before it was recorded, and
        // for those the run is the only release anyone can point to.
        return $this->approved_at !== null && $this->approved_at->lt($this->released_at);
    }

    public function consultant(): BelongsTo
    {
        return $this->belongsTo(Consultant::class);
    }

    public function group(): BelongsTo
    {
        return $this->belongsTo(Group::class);
    }

    public function groupMember(): BelongsTo
    {
        return $this->belongsTo(GroupMember::class);
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }

    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }
}
