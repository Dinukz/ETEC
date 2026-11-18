@if ($errors->any())
    <div class="mb-6 rounded-xl bg-rose-50 px-4 py-3 text-sm text-rose-700 ring-1 ring-rose-200 dark:bg-rose-500/10 dark:text-rose-300 dark:ring-rose-500/30">
        <ul class="list-inside list-disc space-y-1">@foreach ($errors->all() as $e)<li>{{ $e }}</li>@endforeach</ul>
    </div>
@endif

@php $course = $course ?? null; @endphp

<div class="grid gap-5 sm:grid-cols-2">
    <div class="sm:col-span-2">
        <label for="title" class="label">Title</label>
        <input id="title" name="title" type="text" value="{{ old('title', $course->title ?? '') }}" required class="input">
    </div>
    <div class="sm:col-span-2">
        <label for="subtitle" class="label">Subtitle <span class="font-normal text-slate-400">(optional)</span></label>
        <input id="subtitle" name="subtitle" type="text" value="{{ old('subtitle', $course->subtitle ?? '') }}" class="input">
    </div>
    <div class="sm:col-span-2">
        <label for="description" class="label">Description</label>
        <textarea id="description" name="description" rows="6" class="input">{{ old('description', $course->description ?? '') }}</textarea>
    </div>
    <div>
        <label for="category" class="label">Category</label>
        <input id="category" name="category" type="text" value="{{ old('category', $course->category ?? '') }}" class="input" placeholder="e.g. Digital Marketing">
    </div>
    <div>
        <label for="level" class="label">Level</label>
        {{-- No blank placeholder: "All levels" is a FILTER option, not a
             valid level for one course, and submitting it explicitly nulled
             the NOT NULL courses.level column (2026-08-19). Defaults to
             Beginner, same fallback CourseController now applies server-side. --}}
        <select id="level" name="level" class="input">
            @foreach (['beginner', 'intermediate', 'advanced'] as $lvl)
                <option value="{{ $lvl }}" @selected(old('level', $course->level ?? 'beginner') === $lvl)>{{ ucfirst($lvl) }}</option>
            @endforeach
        </select>
    </div>
    <div>
        <label for="price" class="label">Price (LKR)</label>
        <input id="price" name="price" type="number" step="0.01" min="0" value="{{ old('price', $course->price ?? '') }}" required class="input">
    </div>
    <div>
        <label for="discount_price" class="label">Discount price <span class="font-normal text-slate-400">(optional)</span></label>
        <input id="discount_price" name="discount_price" type="number" step="0.01" min="0" value="{{ old('discount_price', $course->discount_price ?? '') }}" class="input">
    </div>
    {{-- ⚠️ A COURSE'S OWN MONEY (2026-10-22) — admin / super admin only; a
         lecturer never sees these. Blank = the standard from Settings, which
         is what every course had before. See App\Support\CourseMoney. --}}
    @if ($canSetPaymentPlan ?? false)
        @php
            $standardHalf = \App\Support\CourseMoney::standardHalf();
            $standardDirect = (float) (\App\Models\CommissionSetting::current()->direct_commission ?? 0);
        @endphp
        <div x-data="{ balance: null }"
             x-init="
                var read = function (id) { var el = document.getElementById(id); return el ? (parseFloat(el.value) || 0) : 0; };
                var calc = function () {
                    var billed = read('discount_price') || read('price');
                    var half = read('half_payment_amount') || {{ (float) $standardHalf }};
                    balance = billed ? Math.max(0, billed - Math.min(half, billed)) : null;
                };
                calc();
                ['price', 'discount_price', 'half_payment_amount'].forEach(function (id) { var el = document.getElementById(id); if (el) { el.addEventListener('input', calc); } });
             ">
            <label for="half_payment_amount" class="label">Half payment — first instalment (LKR) <span class="font-normal text-slate-400">(optional)</span></label>
            <input id="half_payment_amount" name="half_payment_amount" type="number" step="0.01" min="1"
                value="{{ old('half_payment_amount', $course?->half_payment_amount ?? '') }}"
                class="input" placeholder="Standard: {{ number_format($standardHalf) }}">
            <p class="mt-1 text-xs text-slate-400">
                Leave blank for the standard.
                <span x-show="balance !== null" style="display:none">Balance paid later: LKR <span x-text="balance === null ? '' : Number(balance).toLocaleString()"></span></span>
            </p>
        </div>
        <div>
            <label for="direct_commission" class="label">Direct service income (LKR) <span class="font-normal text-slate-400">(optional)</span></label>
            <input id="direct_commission" name="direct_commission" type="number" step="0.01" min="1"
                value="{{ old('direct_commission', $course?->direct_commission ?? '') }}"
                class="input" placeholder="Standard: {{ number_format($standardDirect) }}">
            <p class="mt-1 text-xs text-slate-400">Paid to the consultant who registers a student on this course. Leave blank for the standard. The 2,500 service income is the same for every course.</p>
        </div>
    @endif
    <div>
        <label for="duration_text" class="label">Duration text</label>
        <input id="duration_text" name="duration_text" type="text" value="{{ old('duration_text', $course->duration_text ?? '') }}" class="input" placeholder="e.g. 6 weeks">
    </div>
    <div>
        <label for="default_language" class="label">Default language <span class="font-normal text-slate-400">(auto-fills on student registration)</span></label>
        <select id="default_language" name="default_language" class="input">
            <option value="">None</option>
            @foreach (['Sinhala', 'English', 'Tamil'] as $lang)
                <option value="{{ $lang }}" @selected(old('default_language', $course->default_language ?? '') === $lang)>{{ $lang }}</option>
            @endforeach
        </select>
    </div>
    <div>
        <label for="default_class_type" class="label">Default class type</label>
        <select id="default_class_type" name="default_class_type" class="input">
            <option value="">None</option>
            @foreach (['Physical', 'Online', 'Hybrid'] as $type)
                <option value="{{ $type }}" @selected(old('default_class_type', $course->default_class_type ?? '') === $type)>{{ $type }}</option>
            @endforeach
        </select>
    </div>
    <div>
        <label for="default_course_type" class="label">Default course type</label>
        <select id="default_course_type" name="default_course_type" class="input">
            <option value="">None</option>
            @foreach (['Individual', 'Group'] as $type)
                <option value="{{ $type }}" @selected(old('default_course_type', $course->default_course_type ?? '') === $type)>{{ $type }}</option>
            @endforeach
        </select>
    </div>
    <div>
        <label for="promo_video_url" class="label">Promo video URL <span class="font-normal text-slate-400">(optional)</span></label>
        <input id="promo_video_url" name="promo_video_url" type="url" value="{{ old('promo_video_url', $course->promo_video_url ?? '') }}" class="input">
    </div>
    @if (! $lecturers->isEmpty())
        <div>
            <label for="lecturer_id" class="label">Lecturer</label>
            <select id="lecturer_id" name="lecturer_id" class="input">
                <option value="">Assign to me</option>
                @foreach ($lecturers as $lecturer)
                    <option value="{{ $lecturer->id }}" @selected(old('lecturer_id', $course->lecturer_id ?? '') == $lecturer->id)>{{ $lecturer->name }}</option>
                @endforeach
            </select>
        </div>
    @endif
    <div>
        <label for="status" class="label">Status</label>
        <select id="status" name="status" required class="input">
            @foreach (['draft', 'published', 'archived'] as $s)
                <option value="{{ $s }}" @selected(old('status', $course->status ?? 'draft') === $s)>{{ ucfirst($s) }}</option>
            @endforeach
        </select>
    </div>
    <div>
        <label for="thumbnail" class="label">Thumbnail <span class="font-normal text-slate-400">(image, max 4MB)</span></label>
        <input id="thumbnail" name="thumbnail" type="file" accept="image/*" class="input">
        @if ($course?->thumbnail)
            <p class="mt-1 text-xs text-slate-400">Current: {{ basename($course->thumbnail) }}</p>
        @endif
    </div>
    <div class="flex items-center gap-2 pt-6">
        <input id="is_featured" name="is_featured" type="checkbox" value="1" @checked(old('is_featured', $course->is_featured ?? false)) class="h-4 w-4 rounded border-slate-300 text-brand-600">
        <label for="is_featured" class="text-sm font-medium text-slate-700 dark:text-slate-200">Feature on homepage</label>
    </div>
</div>
