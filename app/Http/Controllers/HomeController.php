<?php

namespace App\Http\Controllers;

use App\Models\Blog;
use App\Models\Course;
use App\Models\Faq;
use App\Models\Testimonial;

class HomeController extends Controller
{
    public function index()
    {
        $featuredCourses = Course::query()
            ->published()
            ->with('lecturer')
            ->orderByDesc('is_featured')
            ->latest('published_at')
            ->take(6)
            ->get();

        $testimonials = Testimonial::query()
            ->where('status', 'approved')
            ->orderByDesc('is_featured')
            ->orderBy('sort_order')
            ->take(6)
            ->get();

        $faqs = Faq::query()
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->take(6)
            ->get();

        $latestPosts = Blog::query()
            ->published()
            ->with('author')
            ->latest('published_at')
            ->take(3)
            ->get();

        $stats = [
            'students' => \App\Models\User::query()->whereHas('student')->count(),
            'courses' => Course::query()->published()->count(),
            'consultants' => \App\Models\User::query()->whereHas('consultant')->count(),
            'certificates' => \App\Models\Certificate::query()->where('status', 'issued')->count(),
        ];

        return view('pages.home', compact('featuredCourses', 'testimonials', 'faqs', 'latestPosts', 'stats'));
    }

    public function about()
    {
        $testimonials = Testimonial::query()
            ->where('status', 'approved')
            ->orderByDesc('is_featured')
            ->take(3)
            ->get();

        // CMS-managed copy; the view falls back to its built-in copy when unset.
        $page = \App\Models\Page::where('slug', 'about-us')->where('status', 'published')->first();

        return view('pages.about', compact('testimonials', 'page'));
    }

    /**
     * Services and Careers — added 2026-09-02 to complete the public nav.
     *
     * ⚠️ THE COPY ON THESE TWO IS SAMPLE CONTENT, WRITTEN TO BE REPLACED.
     * The owner asked for the sections to exist with placeholder detail
     * ("sample widiyta dann") so the site reads as finished while the real
     * wording is decided. Both views say so at the top of the file — swap the
     * arrays there; no controller change is needed.
     */
    public function services()
    {
        return view('pages.services');
    }

    public function careers()
    {
        return view('pages.careers');
    }

    public function consultantProgram()
    {
        $settings = \App\Models\CommissionSetting::current();

        return view('pages.consultant-program', compact('settings'));
    }

    public function faq()
    {
        $faqs = Faq::query()
            ->where('is_active', true)
            ->orderBy('category')
            ->orderBy('sort_order')
            ->get()
            ->groupBy('category');

        return view('pages.faq', compact('faqs'));
    }
}
