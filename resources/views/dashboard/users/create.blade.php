<x-layouts.app title="Create User" header="Create User">
    <div class="mb-6">
        <a href="{{ route('users.index') }}" class="inline-flex items-center gap-1.5 text-sm font-medium text-slate-500 hover:text-brand-600 dark:text-slate-400 dark:hover:text-brand-400">
            <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M15 19l-7-7 7-7"/></svg>
            Back to users
        </a>
    </div>

    <div class="mx-auto max-w-2xl">
        <div class="card p-6 lg:p-8">
            <h2 class="text-xl font-bold text-slate-900 dark:text-white">New user account</h2>
            <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">Create an account and assign a role. The account will be pre-verified.</p>

            @if ($errors->any())
                <div class="mt-6 rounded-xl bg-rose-50 px-4 py-3 text-sm text-rose-700 ring-1 ring-rose-200 dark:bg-rose-500/10 dark:text-rose-300 dark:ring-rose-500/30">
                    <ul class="list-inside list-disc space-y-1">@foreach ($errors->all() as $e)<li>{{ $e }}</li>@endforeach</ul>
                </div>
            @endif

            <form method="POST" action="{{ route('users.store') }}" class="mt-6 space-y-5" x-data="{ role: @js(old('role', 'student')) }">
                @csrf
                <div class="grid gap-5 sm:grid-cols-2">
                    <div>
                        <label for="name" class="label">Full name</label>
                        <input id="name" name="name" type="text" value="{{ old('name') }}" required class="input" placeholder="Jane Doe">
                    </div>
                    <div>
                        <label for="email" class="label">Email</label>
                        <input id="email" name="email" type="email" value="{{ old('email') }}" required class="input" placeholder="jane@etec.lk">
                    </div>
                    <div>
                        <label for="phone" class="label">Phone <span class="font-normal text-slate-400">(optional)</span></label>
                        <input id="phone" name="phone" type="text" value="{{ old('phone') }}" class="input" placeholder="+94 ...">
                    </div>
                    <div>
                        <label for="role" class="label">Role</label>
                        <select id="role" name="role" required class="input" x-model="role">
                            @foreach ($assignableRoles as $role)
                                <option value="{{ $role }}" @selected(old('role', 'student') === $role)>{{ \Illuminate\Support\Str::headline($role) }}</option>
                            @endforeach
                        </select>
                    </div>
                    <x-password-input name="password" label="Password" placeholder="Min. 8 characters" :required="true" />
                    <x-password-input name="password_confirmation" label="Confirm password" :required="true" />
                    {{-- A LECTURER'S COURSES (2026-10-25). On these the lecturer sees
                         every exam and assignment, whoever wrote it. Shown only
                         while Lecturer is the chosen role; can be changed later on
                         the user's edit page. See App\Services\LecturerCourses. --}}
                    <div class="sm:col-span-2" x-show="role === 'lecturer'" x-cloak>
                        <span class="label">Courses this lecturer teaches <span class="font-normal text-slate-400">(optional)</span></span>
                        <p class="text-xs text-slate-500 dark:text-slate-400">They will see the exams and assignments of these courses, whoever added them.</p>
                        <div class="mt-2 max-h-60 space-y-2 overflow-y-auto rounded-xl border border-slate-200 p-3 dark:border-slate-700">
                            @forelse ($courses as $course)
                                <label class="flex items-center gap-3 text-sm text-slate-700 dark:text-slate-300">
                                    <input type="checkbox" name="course_ids[]" value="{{ $course->id }}" class="h-4 w-4 rounded border-slate-300"
                                        @checked(in_array((string) $course->id, array_map('strval', (array) old('course_ids', [])), true))>
                                    {{ $course->title }}
                                </label>
                            @empty
                                <p class="text-sm text-slate-400">No courses yet.</p>
                            @endforelse
                        </div>
                    </div>
                    <div class="sm:col-span-2">
                        <label for="status" class="label">Status</label>
                        <select id="status" name="status" required class="input">
                            @foreach (['active', 'inactive', 'suspended'] as $s)
                                <option value="{{ $s }}" @selected(old('status', 'active') === $s)>{{ ucfirst($s) }}</option>
                            @endforeach
                        </select>
                    </div>
                </div>
                <div class="flex justify-end gap-3 border-t border-slate-100 pt-5 dark:border-slate-800">
                    <a href="{{ route('users.index') }}" class="btn-secondary">Cancel</a>
                    <button type="submit" class="btn-primary">Create user</button>
                </div>
            </form>
        </div>
    </div>
</x-layouts.app>
