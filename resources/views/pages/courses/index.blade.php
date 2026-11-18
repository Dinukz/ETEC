<x-layouts.public title="Courses">
    <x-page-header
        title="Explore Courses"
        subtitle="Browse our catalog of professional courses designed to advance your career."
        :breadcrumbs="['Courses' => null]"
    />

    <section class="container-app py-12">
        {{-- Filters --}}
        <form method="GET" action="{{ route('courses.index') }}" class="card mb-8 flex flex-col gap-4 p-5 lg:flex-row lg:items-center">
            <div class="relative flex-1">
                <svg class="pointer-events-none absolute left-3 top-1/2 h-5 w-5 -translate-y-1/2 text-slate-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/></svg>
                <input type="search" name="q" value="{{ request('q') }}" placeholder="Search courses..." class="input pl-11">
            </div>
            <div class="flex flex-wrap gap-3">
                <select name="category" class="input lg:w-44" onchange="this.form.submit()">
                    <option value="">All categories</option>
                    @foreach ($categories as $cat)
                        <option value="{{ $cat }}" @selected(request('category') === $cat)>{{ $cat }}</option>
                    @endforeach
                </select>
                <select name="level" class="input lg:w-40" onchange="this.form.submit()">
                    <option value="">All levels</option>
                    @foreach (['beginner', 'intermediate', 'advanced'] as $lvl)
                        <option value="{{ $lvl }}" @selected(request('level') === $lvl)>{{ ucfirst($lvl) }}</option>
                    @endforeach
                </select>
                <select name="sort" class="input lg:w-44" onchange="this.form.submit()">
                    <option value="latest" @selected(request('sort') === 'latest')>Newest</option>
                    <option value="price_low" @selected(request('sort') === 'price_low')>Price: Low to High</option>
                    <option value="price_high" @selected(request('sort') === 'price_high')>Price: High to Low</option>
                </select>
                <button type="submit" class="btn-primary">Apply</button>
            </div>
        </form>

        {{-- Results meta --}}
        <div class="mb-6 flex items-center justify-between">
            <p class="text-sm text-slate-500 dark:text-slate-400">
                Showing <span class="font-semibold text-slate-700 dark:text-slate-200">{{ $courses->total() }}</span> course{{ $courses->total() === 1 ? '' : 's' }}
                @if (request('q'))for "<span class="font-semibold">{{ request('q') }}</span>"@endif
            </p>
            @if (request()->hasAny(['q', 'category', 'level', 'sort']))
                <a href="{{ route('courses.index') }}" class="text-sm font-medium text-brand-600 hover:underline dark:text-brand-400">Clear filters</a>
            @endif
        </div>

        @if ($courses->isEmpty())
            <div class="card p-16 text-center">
                <span class="mx-auto grid h-16 w-16 place-items-center rounded-2xl bg-slate-100 text-slate-400 dark:bg-slate-800">
                    <svg class="h-8 w-8" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5"><path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/></svg>
                </span>
                <h3 class="mt-5 text-lg font-bold text-slate-900 dark:text-white">No courses found</h3>
                <p class="mt-2 text-sm text-slate-500 dark:text-slate-400">Try adjusting your search or filters.</p>
                <a href="{{ route('courses.index') }}" class="btn-secondary mt-6">Reset filters</a>
            </div>
        @else
            <div class="grid gap-6 sm:grid-cols-2 lg:grid-cols-3">
                @foreach ($courses as $course)
                    <x-course-card :course="$course" />
                @endforeach
            </div>
            <div class="mt-10">{{ $courses->links() }}</div>
        @endif
    </section>
</x-layouts.public>
