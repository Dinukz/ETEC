<?php

namespace App\Http\Controllers\Cms;

use App\Http\Controllers\Controller;
use App\Models\Faq;
use Illuminate\Http\Request;

class FaqController extends Controller
{
    public function index(Request $request)
    {
        $faqs = Faq::query()
            ->when($request->filled('q'), fn ($q) => $q->where('question', 'like', '%'.$request->string('q').'%'))
            ->orderBy('sort_order')
            ->paginate(10)
            ->withQueryString();

        return view('cms.faqs.index', compact('faqs'));
    }

    public function create()
    {
        return view('cms.faqs.create');
    }

    public function store(Request $request)
    {
        Faq::create($this->validated($request));

        return redirect()->route('cms.faqs.index')->with('success', 'FAQ added.');
    }

    public function edit(Faq $faq)
    {
        return view('cms.faqs.edit', compact('faq'));
    }

    public function update(Request $request, Faq $faq)
    {
        $faq->update($this->validated($request));

        return redirect()->route('cms.faqs.index')->with('success', 'FAQ updated.');
    }

    public function destroy(Faq $faq)
    {
        $faq->delete();

        return back()->with('success', 'FAQ deleted.');
    }

    protected function validated(Request $request): array
    {
        return $request->validate([
            'question' => ['required', 'string', 'max:255'],
            'answer' => ['required', 'string', 'max:5000'],
            'category' => ['nullable', 'string', 'max:80'],
            'sort_order' => ['nullable', 'integer', 'min:0'],
            'is_active' => ['nullable', 'boolean'],
        ]);
    }
}
