<?php

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use App\Models\Hall;
use Illuminate\Http\Request;

/** Admin-only: manage the halls that can be booked (Manage Halls). */
class HallController extends Controller
{
    public function index()
    {
        $halls = Hall::withCount('bookings')->orderBy('name')->get();

        return view('dashboard.halls.index', compact('halls'));
    }

    public function store(Request $request)
    {
        $data = $this->validated($request);

        Hall::create($data + ['is_active' => true]);

        return back()->with('success', "Hall \"{$data['name']}\" added.");
    }

    public function update(Request $request, Hall $hall)
    {
        $hall->update($this->validated($request));

        return back()->with('success', "Hall \"{$hall->name}\" updated.");
    }

    /** Deactivate rather than delete — existing bookings must keep their hall reference. */
    public function toggle(Hall $hall)
    {
        $hall->update(['is_active' => ! $hall->is_active]);

        return back()->with('success', $hall->is_active ? "\"{$hall->name}\" is active again." : "\"{$hall->name}\" deactivated — it can no longer be booked.");
    }

    protected function validated(Request $request): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'location' => ['nullable', 'string', 'max:190'],
            'capacity' => ['nullable', 'integer', 'min:1', 'max:100000'],
        ]);
    }
}
