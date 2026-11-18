<x-layouts.app title="Manage Halls" header="Manage Halls">
    <div class="mb-6">
        <h2 class="text-xl font-extrabold text-slate-900 dark:text-white sm:text-2xl">Manage Halls</h2>
        <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">Add the halls staff can book. A deactivated hall stays in past bookings but can no longer be selected for new ones.</p>
    </div>

    {{-- Add hall --}}
    <div class="card mb-6 p-5 sm:p-6">
        <h3 class="text-sm font-bold text-slate-900 dark:text-white">Add a hall</h3>
        <form method="POST" action="{{ route('halls.store') }}" class="mt-4 grid gap-4 sm:grid-cols-3">
            @csrf
            <div>
                <label for="name" class="label">Name</label>
                <input id="name" name="name" type="text" value="{{ old('name') }}" required maxlength="120" class="input @error('name') border-rose-400 @enderror" placeholder="e.g. Lecture Hall 04">
                @error('name')<p class="mt-1 text-xs text-rose-500">{{ $message }}</p>@enderror
            </div>
            <div>
                <label for="location" class="label">Location <span class="font-normal text-slate-400">(optional)</span></label>
                <input id="location" name="location" type="text" value="{{ old('location') }}" maxlength="190" class="input" placeholder="e.g. Main Building - Floor 2">
            </div>
            <div>
                <label for="capacity" class="label">Capacity <span class="font-normal text-slate-400">(optional)</span></label>
                <input id="capacity" name="capacity" type="number" min="1" value="{{ old('capacity') }}" class="input" placeholder="e.g. 60">
            </div>
            <div class="sm:col-span-3">
                <button type="submit" class="btn-primary">Add Hall</button>
            </div>
        </form>
    </div>

    {{-- Hall list --}}
    <div class="card overflow-hidden">
        <div class="border-b border-slate-100 px-5 py-4 dark:border-slate-800 sm:px-6">
            <h3 class="text-sm font-bold uppercase tracking-wide text-slate-400">Halls</h3>
        </div>

        @if ($halls->isEmpty())
            <p class="px-5 py-10 text-center text-sm text-slate-400 sm:px-6">No halls yet — add one above.</p>
        @else
            {{-- Column header (desktop only) --}}
            <div class="hidden border-b border-slate-100 bg-slate-50 px-6 py-3 text-xs font-semibold uppercase tracking-wide text-slate-500 dark:border-slate-800 dark:bg-slate-800/50 dark:text-slate-400 lg:grid lg:grid-cols-12 lg:gap-4">
                <span class="col-span-3">Name</span>
                <span class="col-span-3">Location</span>
                <span class="col-span-2">Capacity</span>
                <span class="col-span-1">Bookings</span>
                <span class="col-span-1">Status</span>
                <span class="col-span-2">Action</span>
            </div>

            <ul class="divide-y divide-slate-100 dark:divide-slate-800" x-data="{ editing: null }">
                @foreach ($halls as $hall)
                    <li class="p-5 lg:px-6 lg:py-4">
                        {{-- View mode --}}
                        <div x-show="editing !== {{ $hall->id }}" class="lg:grid lg:grid-cols-12 lg:items-center lg:gap-4">
                            <div class="flex items-start justify-between gap-3 lg:col-span-3 lg:block">
                                <span class="font-semibold text-slate-900 dark:text-white">{{ $hall->name }}</span>
                                <span class="badge shrink-0 lg:hidden {{ $hall->is_active ? 'bg-emerald-50 text-emerald-600 dark:bg-emerald-500/10 dark:text-emerald-400' : 'bg-slate-100 text-slate-500 dark:bg-slate-800 dark:text-slate-400' }}">{{ $hall->is_active ? 'Active' : 'Inactive' }}</span>
                            </div>
                            <div class="mt-1 text-sm text-slate-500 dark:text-slate-400 lg:col-span-3 lg:mt-0">{{ $hall->location ?: '—' }}</div>
                            <div class="mt-1 text-sm text-slate-500 dark:text-slate-400 lg:col-span-2 lg:mt-0">{{ $hall->capacity ?: '—' }} <span class="lg:hidden">seats</span></div>
                            <div class="mt-1 text-sm text-slate-500 dark:text-slate-400 lg:col-span-1 lg:mt-0">{{ $hall->bookings_count }} <span class="lg:hidden">bookings</span></div>
                            <div class="mt-2 hidden lg:col-span-1 lg:mt-0 lg:block">
                                <span class="badge {{ $hall->is_active ? 'bg-emerald-50 text-emerald-600 dark:bg-emerald-500/10 dark:text-emerald-400' : 'bg-slate-100 text-slate-500 dark:bg-slate-800 dark:text-slate-400' }}">{{ $hall->is_active ? 'Active' : 'Inactive' }}</span>
                            </div>
                            <div class="mt-3 flex gap-4 border-t border-slate-100 pt-3 dark:border-slate-800 lg:col-span-2 lg:mt-0 lg:border-0 lg:pt-0">
                                <button type="button" @click="editing = {{ $hall->id }}" class="text-xs font-semibold text-brand-600 hover:underline dark:text-brand-400">Edit</button>
                                <form method="POST" action="{{ route('halls.toggle', $hall) }}" onsubmit="return confirm('{{ $hall->is_active ? 'Deactivate' : 'Reactivate' }} {{ $hall->name }}?')">
                                    @csrf @method('PATCH')
                                    <button type="submit" class="text-xs font-semibold hover:underline {{ $hall->is_active ? 'text-rose-600 dark:text-rose-400' : 'text-emerald-600 dark:text-emerald-400' }}">{{ $hall->is_active ? 'Deactivate' : 'Reactivate' }}</button>
                                </form>
                            </div>
                        </div>

                        {{-- Edit mode --}}
                        <form x-show="editing === {{ $hall->id }}" x-cloak method="POST" action="{{ route('halls.update', $hall) }}" class="grid gap-3 sm:grid-cols-2 lg:grid-cols-12 lg:items-end lg:gap-4">
                            @csrf @method('PATCH')
                            <div class="lg:col-span-3">
                                <label class="label">Name</label>
                                <input name="name" type="text" value="{{ $hall->name }}" required maxlength="120" class="input">
                            </div>
                            <div class="lg:col-span-3">
                                <label class="label">Location</label>
                                <input name="location" type="text" value="{{ $hall->location }}" maxlength="190" class="input">
                            </div>
                            <div class="lg:col-span-2">
                                <label class="label">Capacity</label>
                                <input name="capacity" type="number" min="1" value="{{ $hall->capacity }}" class="input">
                            </div>
                            <div class="flex gap-2 sm:col-span-2 lg:col-span-4">
                                <button type="submit" class="btn-primary flex-1 justify-center lg:flex-none">Save</button>
                                <button type="button" @click="editing = null" class="btn-secondary flex-1 justify-center lg:flex-none">Cancel</button>
                            </div>
                        </form>
                    </li>
                @endforeach
            </ul>
        @endif
    </div>
</x-layouts.app>
