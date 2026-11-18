<?php

namespace App\Http\Controllers;

use App\Models\Blog;
use Illuminate\Http\Request;

class BlogController extends Controller
{
    public function index(Request $request)
    {
        $categories = Blog::query()
            ->published()
            ->whereNotNull('category')
            ->distinct()
            ->orderBy('category')
            ->pluck('category');

        $featured = Blog::query()
            ->published()
            ->with('author')
            ->where('is_featured', true)
            ->latest('published_at')
            ->first();

        $posts = Blog::query()
            ->published()
            ->with('author')
            ->when($request->filled('q'), fn ($q) => $q->where('title', 'like', '%'.$request->string('q').'%'))
            ->when($request->filled('category'), fn ($q) => $q->where('category', $request->string('category')))
            ->when($featured, fn ($q) => $q->where('id', '!=', $featured->id))
            ->latest('published_at')
            ->paginate(9)
            ->withQueryString();

        return view('pages.blog.index', compact('posts', 'categories', 'featured'));
    }

    public function show(Blog $blog)
    {
        abort_unless($blog->status === 'published', 404);

        $blog->increment('views');
        $blog->load('author');

        $related = Blog::query()
            ->published()
            ->with('author')
            ->where('id', '!=', $blog->id)
            ->when($blog->category, fn ($q) => $q->where('category', $blog->category))
            ->latest('published_at')
            ->take(3)
            ->get();

        return view('pages.blog.show', compact('blog', 'related'));
    }
}
