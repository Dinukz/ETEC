{{--
    The Remove BUTTON — it sits in the row's action group, beside Mark paid.
    Its confirmation panel is a separate include (income-remove-confirm) placed
    at row level, so the list of upline rows gets a full line of its own instead
    of being squeezed into the buttons.

    Expects: $c — the commission row.

    ⚠️ SUPER ADMIN ONLY. The @role gate here only hides the button; the real
    check is in ServiceIncomeBoard::authorizeRemoval(), because a Livewire
    action is posted straight to Livewire's own endpoint and never passes
    through this page's route middleware.

    Offered on any row that is not already paid or cancelled — an accountant
    correcting a wrong 2,500 should not first have to approve it. `paid` is the
    hard line and removalPreviewFor() refuses it across the whole set anyway.
--}}
@role('super_admin')
    @if (! in_array($c->status, ['paid', 'cancelled'], true))
        <button type="button" class="btn bg-rose-600 py-1 text-xs text-white shadow-sm hover:bg-rose-700"
            wire:click="askRemove({{ $c->id }})"
            wire:loading.attr="disabled"
            wire:target="askRemove({{ $c->id }})">
            <span wire:loading.remove wire:target="askRemove({{ $c->id }})">Remove</span>
            <span wire:loading wire:target="askRemove({{ $c->id }})">…</span>
        </button>
    @endif
@endrole
