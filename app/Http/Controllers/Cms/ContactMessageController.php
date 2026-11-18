<?php

namespace App\Http\Controllers\Cms;

use App\Http\Controllers\Controller;
use App\Models\ContactMessage;
use Illuminate\Http\Request;

class ContactMessageController extends Controller
{
    public function index(Request $request)
    {
        $messages = ContactMessage::query()
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')))
            ->when($request->filled('q'), function ($q) use ($request) {
                $term = $request->string('q');
                $q->where(fn ($w) => $w->where('name', 'like', "%{$term}%")->orWhere('email', 'like', "%{$term}%")->orWhere('subject', 'like', "%{$term}%"));
            })
            ->latest()
            ->paginate(10)
            ->withQueryString();

        $counts = [
            'new' => ContactMessage::where('status', 'new')->count(),
            'total' => ContactMessage::count(),
        ];

        return view('cms.messages.index', compact('messages', 'counts'));
    }

    public function show(ContactMessage $message)
    {
        if ($message->status === 'new') {
            $message->update(['status' => 'read']);
        }

        return view('cms.messages.show', compact('message'));
    }

    public function updateStatus(Request $request, ContactMessage $message)
    {
        $data = $request->validate(['status' => ['required', 'in:new,read,replied']]);

        $message->update([
            'status' => $data['status'],
            'replied_at' => $data['status'] === 'replied' ? now() : $message->replied_at,
        ]);

        return back()->with('success', 'Message status updated.');
    }

    public function destroy(ContactMessage $message)
    {
        $message->delete();

        return redirect()->route('cms.messages.index')->with('success', 'Message deleted.');
    }
}
