@php $currentRole = $u->roles->first()?->name; @endphp

<form method="POST" action="{{ route('users.role', $u) }}" x-data>
    @csrf
    @method('PATCH')
    <select
        name="role"
        onchange="this.form.requestSubmit()"
        @disabled($u->hasRole('super_admin') && ! auth()->user()->isSuperAdmin())
        class="input max-w-[11rem] py-1.5 text-xs font-medium disabled:opacity-60"
    >
        @foreach ($assignableRoles as $role)
            <option value="{{ $role }}" @selected($currentRole === $role)>{{ \Illuminate\Support\Str::headline($role) }}</option>
        @endforeach
        {{-- Show current role even if not in the assignable list (e.g. admin viewing a super_admin) --}}
        @if ($currentRole && ! in_array($currentRole, $assignableRoles))
            <option value="{{ $currentRole }}" selected>{{ \Illuminate\Support\Str::headline($currentRole) }}</option>
        @endif
    </select>
</form>
