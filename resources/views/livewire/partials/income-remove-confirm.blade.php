{{--
    What Remove would actually take off, shown before anything is touched.

    Expects: $c — the commission row whose Remove button was pressed.

    ⚠️ IT NAMES THE UPLINE ROWS, and that is the reason this is a panel rather
    than a browser confirm(): "upline yana ekak thiyeno nm alert msg ekak denn
    eyage thiyen upline account tikath ekk remove krannd kiyla". Building that
    text for a confirm() would mean resolving the siblings of every row on the
    board at render time; here it is one query, for the row actually clicked.
    See ServiceIncomeBoard::askRemove().
--}}
@role('super_admin')
    @if ($confirming === $c->id)
        <div class="mt-2 w-full rounded-lg border border-rose-200 bg-rose-50 p-3 dark:border-rose-500/30 dark:bg-rose-500/10">
            <p class="text-xs font-bold text-rose-700 dark:text-rose-300">
                Remove LKR {{ number_format($confirmTotal, 2) }} from {{ count($confirmRows) }} record(s)?
            </p>

            @if (count($confirmRows) > 1)
                <p class="mt-1 text-xs text-rose-700/90 dark:text-rose-300/90">
                    This registration also paid the upline. All of it goes together — leaving an
                    upline 2,500 behind would pay for income that no longer exists.
                </p>
            @endif

            <ul class="mt-2 space-y-1">
                @foreach ($confirmRows as $row)
                    <li class="flex flex-wrap items-center justify-between gap-2 text-xs text-rose-800 dark:text-rose-200">
                        <span class="truncate">
                            {{ $row['code'] }}@if ($row['name']) — {{ $row['name'] }}@endif
                            <span class="text-rose-600/80 dark:text-rose-300/70">· {{ $row['kind'] }}</span>
                            @if ($row['is_this_row'])
                                <span class="font-semibold">(this row)</span>
                            @endif
                        </span>
                        <span class="font-semibold">LKR {{ $row['amount'] }}</span>
                    </li>
                @endforeach
            </ul>

            <p class="mt-2 text-[11px] text-rose-700/80 dark:text-rose-300/70">
                The student stays exactly where they are placed — only the money is taken off.
                Company revenue is not adjusted, so the Finance Report will still include this amount.
                There is no undo.
            </p>

            <div class="mt-3 flex flex-wrap gap-2">
                <button type="button" class="btn bg-rose-600 py-1 text-xs text-white shadow-sm hover:bg-rose-700"
                    wire:click="removeRow({{ $c->id }})"
                    wire:loading.attr="disabled"
                    wire:target="removeRow({{ $c->id }})">
                    <span wire:loading.remove wire:target="removeRow({{ $c->id }})">Yes, remove it</span>
                    <span wire:loading wire:target="removeRow({{ $c->id }})">Removing…</span>
                </button>
                <button type="button" class="btn-secondary py-1 text-xs" wire:click="cancelRemove">Keep it</button>
            </div>
        </div>
    @endif
@endrole
