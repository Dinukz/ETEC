<?php

namespace App\Livewire;

use App\Concerns\ManagesServiceIncome;
use App\Models\Commission;
use App\Models\Consultant;
use Livewire\Component;

/**
 * The Service Income queue, without the page reload.
 *
 * Every button here used to be a form POST that redirected back. With more
 * than a handful of consultants that made the screen unusable: approving one
 * row threw away the whole page, so the accountant had to scroll back down,
 * re-open the person's dropdown and find their place again — for every single
 * row. Reported 2026-08-23: "ekak approve karaha mark as paid dunnaha mulu
 * page ekam refresh wenwa, aye mula idn gihilla drop down select krla... eka
 * godak time eka yana wadk kattiya godk innkot."
 *
 * Only the affected part of the page re-renders now, and $openOwners keeps the
 * expanded dropdowns open across that re-render.
 *
 * ⚠️ THE MONEY RULES ARE NOT REIMPLEMENTED HERE. Every action delegates to
 * ManagesServiceIncome, the same trait CommissionController uses, so the
 * approval gates cannot differ between the two entry points.
 */
class ServiceIncomeBoard extends Component
{
    use ManagesServiceIncome;

    /** Roles allowed to action the queue — mirrors the route group in web.php. */
    private const ALLOWED_ROLES = ['super_admin', 'admin', 'accountant'];

    /** The banner shown after an action, in place of a redirect's flash. */
    public ?string $message = null;

    public string $level = 'success';

    /**
     * Which people's dropdowns are open, by consultant id.
     *
     * Held on the server rather than in Alpine because the list re-renders
     * after every action — Alpine state inside the replaced DOM would reset,
     * which is the exact problem this component exists to solve.
     *
     * @var array<int, bool>
     */
    public array $open = [];

    /**
     * Which row's Remove has been asked about but not yet confirmed, and what
     * would go with it. See askRemove() for why this is a panel rather than a
     * browser confirm() — the short version is that naming the upline rows in a
     * confirm() would cost a preview query for every row on the board.
     */
    public ?int $confirming = null;

    /** @var array<int, array{code: string, name: string, amount: string, kind: string, is_this_row: bool}> */
    public array $confirmRows = [];

    public float $confirmTotal = 0.0;

    /**
     * ⚠️ AUTHORISE INSIDE EVERY ACTION — the route middleware does NOT cover us.
     *
     * These routes sit behind `role:super_admin|admin|accountant` in web.php,
     * but a Livewire action is not dispatched through that route. It arrives on
     * Livewire's own update endpoint, which only carries the framework's
     * middleware, so the role check on /commissions protects the PAGE and
     * nothing else. Without this, any authenticated user — including a
     * consultant — could approve and pay their own commissions by posting to
     * that endpoint directly.
     */
    protected function authorizeAction(): void
    {
        abort_unless(
            auth()->check() && auth()->user()->hasAnyRole(self::ALLOWED_ROLES),
            403,
            'You are not allowed to action service income.',
        );
    }

    public function toggle(int $ownerId): void
    {
        $this->open[$ownerId] = ! ($this->open[$ownerId] ?? false);
    }

    public function approveRow(int $commissionId): void
    {
        $this->authorizeAction();

        $commission = Commission::find($commissionId);

        $this->show($commission
            ? $this->approveOne($commission)
            : $this->result(false, 'That record no longer exists.', 'error'));
    }

    public function payRow(int $commissionId): void
    {
        $this->authorizeAction();

        $commission = Commission::find($commissionId);

        $this->show($commission
            ? $this->payOne($commission)
            : $this->result(false, 'That record no longer exists.', 'error'));
    }

    /**
     * ⚠️ SUPER ADMIN ONLY, checked here and not by the route.
     *
     * The class note above explains why every action must authorise itself;
     * removal narrows further than authorizeAction(). Marking money PAID is
     * routine accountancy and all three roles do it, but removing money is
     * destructive and has no undo button — so it matches
     * SystemRunController::reverseAccount(), the only comparable action in the
     * app, which is super-admin only for the same reason.
     */
    protected function authorizeRemoval(): void
    {
        abort_unless(
            auth()->check() && auth()->user()->hasRole('super_admin'),
            403,
            'Only a super admin can remove service income.',
        );
    }

    /**
     * Step one of two: work out what pressing Remove would actually take off,
     * and show it before anything is touched.
     *
     * ⚠️ WHY A PANEL AND NOT `confirm()`. The dialog has to name every upline
     * row that would go with this one — the owner asked for exactly that
     * ("upline yana ekak thiyeno nm alert msg ekak denn"). Building that text
     * for a plain JS confirm() means resolving the siblings of EVERY row on
     * screen at render time: 120 rows on a quiet day, and the board already
     * carries the whole queue. Resolving them only for the row that was
     * actually clicked is one query when somebody asks, and none otherwise.
     */
    public function askRemove(int $commissionId): void
    {
        $this->authorizeRemoval();

        $commission = Commission::with(['consultant.user', 'consultant.owner.user', 'payment'])->find($commissionId);

        if (! $commission) {
            $this->show($this->result(false, 'That record no longer exists.', 'error'));

            return;
        }

        $preview = $this->removalPreviewFor($commission);

        if (! $preview['allowed']) {
            $this->show($this->result(false, $preview['reason'], 'error'));

            return;
        }

        $this->confirming = $commissionId;
        $this->confirmTotal = $preview['total'];

        // Flattened to plain strings on purpose: a Livewire public property is
        // serialised into the page between requests, and models are not.
        $this->confirmRows = $preview['rows']->map(fn (Commission $row) => [
            'code' => $row->consultant?->scn_code ?? $row->consultant?->consultant_code ?? 'this account',
            'name' => $row->consultant?->display_name ?? '',
            'amount' => number_format((float) $row->amount, 2),
            'kind' => $row->type === \App\Services\CommissionService::TYPE_DIRECT ? 'Direct add bonus' : 'Service group income',
            'is_this_row' => $row->id === $commissionId,
        ])->values()->all();
    }

    /** Step one, undone. Nothing was touched, so there is nothing to unwind. */
    public function cancelRemove(): void
    {
        $this->confirming = null;
        $this->confirmRows = [];
        $this->confirmTotal = 0.0;
    }

    /**
     * Step two: take this row and the upline income from the same registration
     * off the books.
     *
     * The gates are NOT here — removeOne() re-reads the whole set under a row
     * lock and judges it again, so what the panel showed is advisory and this
     * cannot act on a stale preview.
     */
    public function removeRow(int $commissionId): void
    {
        $this->authorizeRemoval();

        $commission = Commission::with(['consultant.user', 'consultant.owner.user', 'payment'])->find($commissionId);

        $result = $commission
            ? $this->removeOne($commission)
            : $this->result(false, 'That record no longer exists.', 'error');

        // A refusal changes nothing, so stay exactly where we are and say why.
        if (! $result['ok']) {
            $this->show($result);

            return;
        }

        // ⚠️ A SUCCESSFUL REMOVAL RELOADS THE WHOLE PAGE, DELIBERATELY (2026-09-10).
        //
        // Reported: "remove krata Service Income eke amount eka penwanawa" — the
        // amount is still shown after removing. It was: the five stat cards, the
        // pending banner and "Top earners" are plain Blade rendered once by
        // CommissionController::index(), and only this component re-renders after
        // a Livewire action. show() dispatches 'service-income-updated' for them
        // — and NOTHING HAS EVER LISTENED FOR IT (grep it). So the row vanished
        // from the board while "Total Service Income" kept printing the old
        // money until somebody reloaded by hand.
        //
        // ⚠️ AND WHY THIS DOES NOT CONTRADICT THE CLASS DOCBLOCK ABOVE. That
        // explains why approving and paying must NOT reload: an accountant does
        // those dozens of times in a sitting and losing their place each time is
        // what made the screen unusable. Removing is rare, destructive, super
        // admin only, and the accountant's next act is to check the totals — so
        // here a fresh page is the point, not the cost.
        session()->flash('success', $result['message']);

        $this->redirect(route('commissions.index'));

        $this->cancelRemove();
    }

    public function approveOwner(int $consultantId): void
    {
        $this->authorizeAction();

        $consultant = Consultant::find($consultantId);

        $this->show($consultant
            ? $this->approveAllOwnedBy($consultant)
            : $this->result(false, 'That consultant no longer exists.', 'error'));
    }

    public function approveStage(int $consultantId): void
    {
        $this->authorizeAction();

        $consultant = Consultant::find($consultantId);

        $this->show($consultant
            ? $this->approveBalancedStage($consultant)
            : $this->result(false, 'That account no longer exists.', 'error'));
    }

    public function approveAll(): void
    {
        $this->authorizeAction();

        $this->show($this->approveEverything());
    }

    /** @param  array{ok: bool, message: string, level: string}  $result */
    protected function show(array $result): void
    {
        $this->message = $result['message'];
        $this->level = $result['level'];

        // The stats cards and the "Top earners" panel live outside this
        // component and are now stale. They are read-only summaries, so rather
        // than reload the page the parent listens for this and refreshes them.
        $this->dispatch('service-income-updated');
    }

    public function render()
    {
        return view('livewire.service-income-board', [
            'incomeGroups' => $this->recentIncomeByConsultant(),
        ]);
    }
}
