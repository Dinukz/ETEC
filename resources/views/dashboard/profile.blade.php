<x-layouts.app title="My Profile" header="My Profile">
    <div class="mb-8">
        <h2 class="text-2xl font-extrabold text-slate-900 dark:text-white">Profile settings</h2>
        <p class="mt-1 text-slate-500 dark:text-slate-400">Manage your account information and security.</p>
    </div>

    @if ($user->password_must_change)
        <div class="mb-6 rounded-xl bg-amber-50 px-4 py-3 text-sm text-amber-800 ring-1 ring-amber-200 dark:bg-amber-500/10 dark:text-amber-300 dark:ring-amber-500/30">
            <strong>Action required:</strong> You're using a temporary password. Use the "Email me a password reset link" button below to set your own.
        </div>
    @endif

    @php $locked = (bool) $user->profile_locked_at; @endphp
    @if ($locked)
        <div class="mb-6 rounded-xl bg-slate-100 px-4 py-3 text-sm text-slate-600 ring-1 ring-slate-200 dark:bg-slate-800/50 dark:text-slate-300 dark:ring-slate-700">
            <strong>Profile locked:</strong> Your details were saved on {{ $user->profile_locked_at->format('M d, Y') }} and can no longer be edited here. Only your profile photo can still be changed. Contact an Admin if something needs correcting.
        </div>
    @endif

    <div class="grid gap-6 lg:grid-cols-3" x-data="{ lightbox: null }">
        {{-- Lightbox --}}
        <div x-show="lightbox" x-cloak @click="lightbox = null" class="fixed inset-0 z-[60] grid place-items-center bg-slate-900/70 p-4" style="display:none">
            <img :src="lightbox" @click.stop class="max-h-[85vh] max-w-full rounded-xl object-contain">
            <button type="button" @click="lightbox = null" class="absolute right-4 top-4 text-white hover:text-slate-300">
                <svg class="h-7 w-7" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/></svg>
            </button>
        </div>

        {{-- Profile summary --}}

        {{--
            ⚠️ THE AVATAR UPLOAD POSTS WITH _method=PATCH. DO NOT CHANGE IT BACK
            TO fetch(method: 'PATCH') (fixed 2026-09-02).

            PHP only parses a multipart/form-data body on a real POST. On
            PATCH/PUT it populates neither $_FILES nor $_POST, so
            $request->file('avatar') arrived null and validation answered that
            the avatar field is required — underneath a preview of the very
            image it was rejecting. (That preview is drawn client-side by
            FileReader, which is why it looked fine while the server saw
            nothing.) Laravel's method spoofing routes the POST to the existing
            PATCH route, unchanged.

            ⚠️ AND THIS NOTE LIVES OUT HERE, NOT INSIDE x-data. It was written as
            a JS comment inside that attribute, and the double quotes in it
            closed the attribute early — the whole paragraph rendered as visible
            text down the middle of the profile page. An HTML attribute is not a
            place for prose; a Blade comment is stripped at compile time and can
            hold anything.
        --}}
        <div class="lg:col-span-1">
            <div class="card p-6 text-center">
                <div class="relative mx-auto h-24 w-24" x-data="{
                        open: false,
                        saving: false,
                        error: null,
                        preview: null,
                        file: null,
                        currentUrl: @js($user->avatar ? route('media.show', $user->avatar) : null),
                        onFile(e) {
                            this.file = e.target.files[0] ?? null;
                            this.error = null;
                            if (this.file) {
                                const reader = new FileReader();
                                reader.onload = () => { this.preview = reader.result; };
                                reader.readAsDataURL(this.file);
                            }
                        },
                        save() {
                            if (! this.file) { this.error = 'Please upload a picture here'; return; }
                            this.saving = true;
                            const form = new FormData();
                            form.append('avatar', this.file);
                            form.append('_method', 'PATCH');
                            fetch('{{ route('profile.avatar') }}', {
                                method: 'POST',
                                headers: {
                                    'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content,
                                    'Accept': 'application/json',
                                },
                                body: form,
                            })
                                .then(async (res) => {
                                    if (! res.ok) throw await res.json();
                                    return res.json();
                                })
                                .then((data) => {
                                    this.currentUrl = data.avatar_url;
                                    this.open = false;
                                    this.preview = null;
                                    this.file = null;
                                })
                                .catch((err) => {
                                    this.error = err?.errors?.avatar?.[0] ?? 'Could not update photo. Please try again.';
                                })
                                .finally(() => { this.saving = false; });
                        },
                    }">
                    <span class="grid h-24 w-24 place-items-center overflow-hidden rounded-full bg-gradient-to-br from-brand-400 to-brand-600 text-3xl font-bold text-white">
                        <template x-if="currentUrl">
                            <img :src="currentUrl" alt="{{ $user->name }}" @click="lightbox = currentUrl" class="h-24 w-24 cursor-pointer rounded-full object-cover">
                        </template>
                        <template x-if="!currentUrl">
                            <span>{{ strtoupper(substr($user->name, 0, 1)) }}</span>
                        </template>
                    </span>
                    <button type="button" @click="open = true" class="absolute bottom-0 right-0 grid h-8 w-8 place-items-center rounded-full bg-white text-slate-600 shadow ring-1 ring-slate-200 hover:bg-slate-50 dark:bg-slate-800 dark:text-slate-300 dark:ring-slate-700">
                        <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M3 9a2 2 0 012-2h.93a2 2 0 001.664-.89l.812-1.22A2 2 0 0110.07 4h3.86a2 2 0 011.664.89l.812 1.22A2 2 0 0018.07 7H19a2 2 0 012 2v9a2 2 0 01-2 2H5a2 2 0 01-2-2V9z"/><path stroke-linecap="round" stroke-linejoin="round" d="M15 13a3 3 0 11-6 0 3 3 0 016 0z"/></svg>
                    </button>

                    {{-- Update Profile Picture modal --}}
                    <div x-show="open" x-cloak class="fixed inset-0 z-50 grid place-items-center bg-slate-900/50 p-4" style="display:none">
                        <div @click.outside="open = false" class="w-full max-w-sm rounded-2xl bg-white p-6 text-left dark:bg-slate-900">
                            <div class="flex items-center justify-between">
                                <h3 class="text-lg font-bold text-slate-900 dark:text-white">Update Profile Picture</h3>
                                <button type="button" @click="open = false" class="text-slate-400 hover:text-slate-600 dark:hover:text-slate-200">
                                    <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/></svg>
                                </button>
                            </div>

                            <label class="mx-auto mt-5 grid h-28 w-28 cursor-pointer place-items-center overflow-hidden rounded-xl border-2 border-dashed border-slate-300 text-slate-400 hover:border-brand-400 dark:border-slate-700">
                                <template x-if="preview">
                                    <img :src="preview" class="h-full w-full object-cover">
                                </template>
                                <template x-if="!preview">
                                    <span class="text-center text-xs">
                                        <svg class="mx-auto h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4v16m8-8H4"/></svg>
                                        Upload
                                    </span>
                                </template>
                                <input type="file" accept="image/*" class="hidden" @change="onFile($event)">
                            </label>
                            <p x-show="error" x-text="error" class="mt-2 text-center text-xs text-rose-500"></p>

                            <div class="mt-5 flex justify-end gap-2">
                                <button type="button" @click="open = false" class="btn-secondary">Cancel</button>
                                <button type="button" @click="save()" :disabled="saving" class="btn-primary" x-text="saving ? 'Saving...' : 'Save'"></button>
                            </div>
                        </div>
                    </div>
                </div>
                <h3 class="mt-4 text-lg font-bold text-slate-900 dark:text-white">{{ $user->name }}</h3>
                <p class="text-sm text-slate-500 dark:text-slate-400">{{ $user->email }}</p>
                <span class="badge mt-3 bg-brand-50 text-brand-700 dark:bg-brand-500/10 dark:text-brand-400">{{ ucfirst($user->getRoleNames()->first() ?? 'Member') }}</span>
                @if ($consultant)
                    <span class="badge mt-2 {{ $consultant->profile_complete ? 'bg-emerald-50 text-emerald-600 dark:bg-emerald-500/10 dark:text-emerald-400' : 'bg-amber-50 text-amber-600 dark:bg-amber-500/10 dark:text-amber-400' }}">{{ $consultant->profile_complete ? 'Profile Complete' : 'Profile Incomplete' }}</span>
                @endif

                <dl class="mt-6 space-y-3 border-t border-slate-100 pt-6 text-left text-sm dark:border-slate-800">
                    <div class="flex items-center justify-between">
                        <dt class="text-slate-500 dark:text-slate-400">Member since</dt>
                        <dd class="font-medium text-slate-700 dark:text-slate-200">{{ $user->created_at->format('M Y') }}</dd>
                    </div>
                    <div class="flex items-center justify-between">
                        <dt class="text-slate-500 dark:text-slate-400">Email status</dt>
                        <dd>
                            @if ($user->email_verified_at)
                                <span class="badge bg-emerald-50 text-emerald-600 dark:bg-emerald-500/10 dark:text-emerald-400">Verified</span>
                            @else
                                <span class="badge bg-amber-50 text-amber-600 dark:bg-amber-500/10 dark:text-amber-400">Unverified</span>
                            @endif
                        </dd>
                    </div>
                </dl>
            </div>

        </div>

        {{-- Forms --}}
        <div class="space-y-6 lg:col-span-2">
            <form method="POST" action="{{ route('profile.update') }}" enctype="multipart/form-data" class="space-y-6"
                x-data="{
                    saving: false,
                    locked: {{ $locked ? 'true' : 'false' }},
                    flash: null,
                    submit(e) {
                        this.saving = true;
                        this.flash = null;
                        const form = e.target;
                        fetch(form.action, {
                            method: 'POST',
                            headers: {
                                'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content,
                                'Accept': 'application/json',
                            },
                            body: new FormData(form),
                        })
                        .then(async (res) => { const j = await res.json().catch(() => ({})); if (! res.ok) throw j; return j; })
                        .then((j) => {
                            if (j.locked) {
                                this.locked = true;
                                this.flash = { type: 'success', text: 'Profile completed and locked. Contact an admin to make further changes.' };
                            } else {
                                this.flash = { type: 'success', text: j.message || 'Profile updated successfully.' };
                            }
                            window.scrollTo({ top: 0, behavior: 'smooth' });
                        })
                        .catch((err) => {
                            const text = err?.message || (err?.errors ? Object.values(err.errors)[0][0] : 'Could not save. Please try again.');
                            this.flash = { type: 'error', text };
                        })
                        .finally(() => { this.saving = false; });
                    },
                }"
                @submit.prevent="submit($event)"
            >
                @csrf
                @method('PATCH')

                <div x-show="flash" x-cloak x-transition
                    class="rounded-xl px-4 py-3 text-sm font-medium ring-1"
                    :class="flash && flash.type === 'success' ? 'bg-emerald-50 text-emerald-800 ring-emerald-200 dark:bg-emerald-500/10 dark:text-emerald-300 dark:ring-emerald-500/30' : 'bg-rose-50 text-rose-800 ring-rose-200 dark:bg-rose-500/10 dark:text-rose-300 dark:ring-rose-500/30'"
                    x-text="flash ? flash.text : ''"></div>

                {{-- Profile info --}}
                <div class="card p-6 lg:p-7">
                    <h3 class="text-lg font-bold text-slate-900 dark:text-white">Personal information</h3>
                    <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">Update your name, email, and profile photo.</p>

                    <div class="mt-6 grid gap-5 sm:grid-cols-2">
                        <div>
                            <label for="name" class="label">Full name</label>
                            <input id="name" name="name" type="text" value="{{ old('name', $user->name) }}" required @disabled($locked) class="input @error('name') border-rose-400 @enderror">
                            @error('name')<p class="mt-1 text-xs text-rose-500">{{ $message }}</p>@enderror
                        </div>
                        <div>
                            <label for="email" class="label">Email</label>
                            <input id="email" name="email" type="email" value="{{ old('email', $user->email) }}" required @disabled($locked) class="input @error('email') border-rose-400 @enderror">
                            @error('email')<p class="mt-1 text-xs text-rose-500">{{ $message }}</p>@enderror
                        </div>
                        <div>
                            <label for="phone" class="label">Phone</label>
                            <input id="phone" name="phone" type="text" value="{{ old('phone', $user->phone) }}" @disabled($locked) class="input">
                        </div>
                    </div>
                    <p class="mt-1 text-xs text-slate-400">Profile photo is updated from the picture on the left — click the camera icon.</p>
                </div>

                @if ($consultant)
                    {{-- Personal Information (consultant) --}}
                    <div class="card p-6 lg:p-7">
                        <h3 class="flex items-center gap-2 text-lg font-bold text-slate-900 dark:text-white">
                            <svg class="h-5 w-5 text-slate-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"/></svg>
                            Personal Information
                        </h3>
                        <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">Your basic personal details and contact information.</p>

                        <div class="mt-6 grid gap-5 sm:grid-cols-2">
                            <div>
                                <label for="c_nic" class="label">National ID</label>
                                <div class="flex items-center gap-2">
                                    <input id="c_nic" name="nic" type="text" value="{{ old('nic', $consultant->nic) }}" @disabled($locked) class="input flex-1" placeholder="XXXXXXXXXX">
                                    @if ($consultant->nic)
                                        <span class="badge shrink-0 {{ $consultant->nic_verified ? 'bg-emerald-50 text-emerald-600 dark:bg-emerald-500/10 dark:text-emerald-400' : 'bg-amber-50 text-amber-600 dark:bg-amber-500/10 dark:text-amber-400' }}">{{ $consultant->nic_verified ? 'Verified' : 'Pending' }}</span>
                                    @endif
                                </div>
                            </div>
                            <div>
                                <label for="c_dob" class="label">Date of Birth</label>
                                <input id="c_dob" name="date_of_birth" type="date" value="{{ old('date_of_birth', optional($consultant->date_of_birth)->toDateString()) }}" max="{{ today()->toDateString() }}" @disabled($locked) class="input">
                            </div>
                            <div>
                                <label for="c_gender" class="label">Gender</label>
                                <select id="c_gender" name="gender" @disabled($locked) class="input">
                                    <option value="">Select gender</option>
                                    <option value="male" @selected(old('gender', $consultant->gender) === 'male')>Male</option>
                                    <option value="female" @selected(old('gender', $consultant->gender) === 'female')>Female</option>
                                    <option value="other" @selected(old('gender', $consultant->gender) === 'other')>Other</option>
                                </select>
                            </div>
                            <div class="sm:col-span-2">
                                <label for="c_address" class="label">Address</label>
                                <textarea id="c_address" name="address" rows="2" maxlength="300" @disabled($locked) class="input">{{ old('address', $consultant->address) }}</textarea>
                            </div>
                        </div>
                    </div>

                    {{-- Bank Details --}}
                    <div class="card p-6 lg:p-7">
                        <h3 class="flex items-center gap-2 text-lg font-bold text-slate-900 dark:text-white">
                            <svg class="h-5 w-5 text-emerald-500" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M3 10h18M7 15h1m4 0h1m-7 4h12a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"/></svg>
                            Bank Details
                        </h3>
                        <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">Your banking information for financial transactions.</p>

                        <div class="mt-6 grid gap-5 sm:grid-cols-2">
                            <div>
                                <label for="bank_name" class="label">Bank Name</label>
                                <input id="bank_name" name="bank_name" type="text" value="{{ old('bank_name', $consultant->bank_name) }}" @disabled($locked) class="input">
                            </div>
                            <div>
                                <label for="bank_account_name" class="label">Account Holder Name</label>
                                <input id="bank_account_name" name="bank_account_name" type="text" value="{{ old('bank_account_name', $consultant->bank_account_name) }}" @disabled($locked) class="input">
                            </div>
                            <div>
                                <label for="bank_account_number" class="label">Account Number</label>
                                <input id="bank_account_number" name="bank_account_number" type="text" value="{{ old('bank_account_number', $consultant->bank_account_number) }}" @disabled($locked) class="input">
                            </div>
                            <div>
                                <label for="bank_branch" class="label">Branch</label>
                                <input id="bank_branch" name="bank_branch" type="text" value="{{ old('bank_branch', $consultant->bank_branch) }}" @disabled($locked) class="input">
                            </div>
                            <div>
                                <label for="swift_code" class="label">SWIFT Code</label>
                                <input id="swift_code" name="swift_code" type="text" value="{{ old('swift_code', $consultant->swift_code) }}" @disabled($locked) class="input">
                            </div>
                            <div x-data="{
                                    preview: null,
                                    isImage: false,
                                    onFile(e) {
                                        const f = e.target.files[0] ?? null;
                                        this.preview = null;
                                        if (! f) return;
                                        this.isImage = f.type.startsWith('image/');
                                        if (this.isImage) {
                                            const reader = new FileReader();
                                            reader.onload = () => { this.preview = reader.result; };
                                            reader.readAsDataURL(f);
                                        } else {
                                            this.preview = 'file';
                                        }
                                    },
                                }">
                                <label for="account_proof" class="label">Account Proof</label>
                                <input id="account_proof" name="account_proof" type="file" accept=".pdf,.png,.jpg,.jpeg" @disabled($locked) @change="onFile($event)" class="input">
                                <template x-if="preview === 'file'">
                                    <span class="mt-1 inline-block text-xs text-slate-500 dark:text-slate-400">File selected</span>
                                </template>
                                <template x-if="preview && preview !== 'file'">
                                    <button type="button" @click="lightbox = preview" class="mt-2 block">
                                        <img :src="preview" class="h-14 w-14 rounded-lg border border-slate-200 object-cover dark:border-slate-700">
                                    </button>
                                </template>
                                @if ($consultant->account_proof_path)
                                    <a href="{{ route('media.show', $consultant->account_proof_path) }}" target="_blank" class="mt-1 inline-block text-xs font-medium text-brand-600 hover:underline dark:text-brand-400">View current file</a>
                                @endif
                            </div>
                        </div>
                    </div>

                    {{-- Beneficiary Details --}}
                    <div class="card p-6 lg:p-7">
                        <h3 class="flex items-center gap-2 text-lg font-bold text-slate-900 dark:text-white">
                            <svg class="h-5 w-5 text-violet-500" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M17 20h5v-2a4 4 0 00-3-3.87M9 20H4v-2a4 4 0 013-3.87m6-1.13a4 4 0 100-8 4 4 0 000 8z"/></svg>
                            Beneficiary Details
                        </h3>
                        <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">Emergency contact and beneficiary information.</p>

                        <div class="mt-6 grid gap-5 sm:grid-cols-2">
                            <div>
                                <label for="beneficiary_name" class="label">Full Name</label>
                                <input id="beneficiary_name" name="beneficiary_name" type="text" value="{{ old('beneficiary_name', $consultant->beneficiary_name) }}" @disabled($locked) class="input">
                            </div>
                            <div>
                                <label for="beneficiary_relationship" class="label">Relationship</label>
                                <input id="beneficiary_relationship" name="beneficiary_relationship" type="text" value="{{ old('beneficiary_relationship', $consultant->beneficiary_relationship) }}" @disabled($locked) class="input" placeholder="e.g. Mother, Spouse">
                            </div>
                            <div>
                                <label for="beneficiary_nic" class="label">NIC</label>
                                <input id="beneficiary_nic" name="beneficiary_nic" type="text" value="{{ old('beneficiary_nic', $consultant->beneficiary_nic) }}" @disabled($locked) class="input">
                            </div>
                            <div>
                                <label for="beneficiary_date_of_birth" class="label">Date of Birth</label>
                                <input id="beneficiary_date_of_birth" name="beneficiary_date_of_birth" type="date" value="{{ old('beneficiary_date_of_birth', optional($consultant->beneficiary_date_of_birth)->toDateString()) }}" max="{{ today()->toDateString() }}" @disabled($locked) class="input">
                            </div>
                            <div>
                                <label for="beneficiary_phone" class="label">Phone Number</label>
                                <input id="beneficiary_phone" name="beneficiary_phone" type="text" value="{{ old('beneficiary_phone', $consultant->beneficiary_phone) }}" @disabled($locked) class="input">
                            </div>
                        </div>
                    </div>
                @endif

                @unless ($locked)
                    <div class="flex justify-end" x-show="!locked" x-cloak>
                        <button type="submit" :disabled="saving" class="btn-primary">
                            <span x-text="saving ? 'Saving…' : 'Save changes'">Save changes</span>
                        </button>
                    </div>
                @endunless
            </form>

            {{-- Password --}}
            <div class="card p-6 lg:p-7">
                <h3 class="text-lg font-bold text-slate-900 dark:text-white">Password</h3>
                <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">For security, passwords are changed via a reset link sent to your email rather than typed in here.</p>

                <form method="POST" action="{{ route('profile.password.email') }}" class="mt-5">
                    @csrf
                    <button type="submit" class="btn-primary">Email me a password reset link</button>
                </form>
            </div>
        </div>
    </div>
</x-layouts.app>
