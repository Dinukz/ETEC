<?php

namespace App\Http\Controllers;

use App\Models\Course;
use Illuminate\Http\Request;

class CourseController extends Controller
{
    public function index(Request $request)
    {
        $categories = Course::query()
            ->published()
            ->whereNotNull('category')
            ->distinct()
            ->orderBy('category')
            ->pluck('category');

        $courses = Course::query()
            ->published()
            ->with('lecturer')
            ->when($request->filled('q'), function ($query) use ($request) {
                $term = $request->string('q');
                $query->where(fn ($q) => $q
                    ->where('title', 'like', "%{$term}%")
                    ->orWhere('subtitle', 'like', "%{$term}%")
                    ->orWhere('description', 'like', "%{$term}%"));
            })
            ->when($request->filled('category'), fn ($q) => $q->where('category', $request->string('category')))
            ->when($request->filled('level'), fn ($q) => $q->where('level', $request->string('level')))
            ->when($request->input('sort') === 'price_low', fn ($q) => $q->orderByRaw('COALESCE(discount_price, price) asc'))
            ->when($request->input('sort') === 'price_high', fn ($q) => $q->orderByRaw('COALESCE(discount_price, price) desc'))
            ->when(! $request->filled('sort') || $request->input('sort') === 'latest', fn ($q) => $q->latest('published_at'))
            ->paginate(9)
            ->withQueryString();

        return view('pages.courses.index', compact('courses', 'categories'));
    }

    public function show(Course $course)
    {
        abort_unless($course->status === 'published', 404);

        $course->load([
            'lecturer',
            'modules' => fn ($q) => $q->orderBy('sort_order'),
            'modules.lessons' => fn ($q) => $q->orderBy('sort_order'),
            'lessons' => fn ($q) => $q->orderBy('sort_order'),
        ]);

        $totalLessons = $course->lessons->count();
        $previewLessons = $course->lessons->where('is_preview', true);
        $totalDuration = $course->lessons->sum('duration_minutes');

        $isEnrolled = false;
        if (auth()->check()) {
            $isEnrolled = $course->enrollments()
                ->where('user_id', auth()->id())
                ->whereIn('status', ['active', 'completed'])
                ->exists();
        }

        $related = Course::query()
            ->published()
            ->with('lecturer')
            ->where('id', '!=', $course->id)
            ->when($course->category, fn ($q) => $q->where('category', $course->category))
            ->take(3)
            ->get();

        return view('pages.courses.show', compact(
            'course', 'totalLessons', 'previewLessons', 'totalDuration', 'isEnrolled', 'related'
        ));
    }
}
