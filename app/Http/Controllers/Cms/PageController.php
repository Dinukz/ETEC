<?php

namespace App\Http\Controllers\Cms;

use App\Http\Controllers\Controller;
use App\Models\Page;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class PageController extends Controller
{
    public function index()
    {
        $pages = Page::orderBy('title')->paginate(10);

        return view('cms.pages.index', compact('pages'));
    }

    public function create()
    {
        return view('cms.pages.create');
    }

    public function store(Request $request)
    {
        $data = $this->validated($request);
        $data['slug'] = Str::slug($data['slug'] ?: $data['title']);

        if (Page::where('slug', $data['slug'])->exists()) {
            return back()->withInput()->withErrors(['slug' => "A page with the slug \"{$data['slug']}\" already exists."]);
        }

        Page::create($data);

        return redirect()->route('cms.pages.index')->with('success', 'Page created.');
    }

    public function edit(Page $page)
    {
        return view('cms.pages.edit', compact('page'));
    }

    public function update(Request $request, Page $page)
    {
        $data = $this->validated($request);
        unset($data['slug']); // slug is the public URL — immutable after creation

        $page->update($data);

        return redirect()->route('cms.pages.index')->with('success', 'Page updated.');
    }

    public function destroy(Page $page)
    {
        $page->delete();

        return back()->with('success', 'Page deleted.');
    }

    protected function validated(Request $request): array
    {
        return $request->validate([
            'title' => ['required', 'string', 'max:180'],
            'slug' => ['nullable', 'string', 'max:180'],
            'content' => ['required', 'string'],
            'meta_title' => ['nullable', 'string', 'max:180'],
            'meta_description' => ['nullable', 'string', 'max:300'],
            'status' => ['required', 'in:draft,published'],
        ]);
    }
}
