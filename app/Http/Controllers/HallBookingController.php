<?php

namespace App\Http\Controllers;

use App\Models\Hall;
use App\Models\HallBooking;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Support\Facades\DB;

class HallBookingController extends Controller implements HasMiddleware
{
    /**
     * ⚠️ NOT FOR LECTURERS (2026-10-24). Owner: "Lectuer gen My booking and All
     * bookings ain krla denn".
     *
     * The route group no longer lists the lecturer role, which shuts out a
     * plain lecturer. This catches the rest: someone whose MAIN role is
     * lecturer but who also holds a role the group allows (a consultant who
     * teaches). They get the lecturer menu, so the pages go with it — decided
     * by primaryRole(), the same rule the sidebar uses.
     */
    public static function middleware(): array
    {
        return [
            new Middleware(function (Request $request, \Closure $next) {
                abort_if($request->user()?->primaryRole() === 'lecturer', 403, 'Hall bookings are not available to lecturers.');

                return $next($request);
            }),
        ];
    }

    /** "My Bookings" — the signed-in user's own hall bookings. */
    public function index(Request $request)
    {
        $halls = Hall::where('is_active', true)->orderBy('name')->get();

        $bookings = HallBooking::query()
            ->with('hall')
            ->where('user_id', auth()->id())
            ->when($request->filled('hall_id'), fn ($q) => $q->where('hall_id', $request->integer('hall_id')))
            ->when($request->filled('date'), fn ($q) => $q->whereDate('booking_date', $request->date('date')))
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')))
            ->orderByDesc('booking_date')
            ->orderByDesc('start_time')
            ->paginate(10)
            ->withQueryString();

        return view('dashboard.bookings.index', compact('bookings', 'halls'));
    }

    /** "All Bookings" — org-wide calendar, filterable by hall + month. */
    public function all(Request $request)
    {
        $halls = Hall::where('is_active', true)->orderBy('name')->get();
        $hallId = $request->integer('hall_id') ?: $halls->first()?->id;
        $month = $request->date('month') ?? now();

        $bookings = HallBooking::query()
            ->with(['hall', 'user'])
            ->where('status', '!=', 'cancelled')
            ->when($hallId, fn ($q) => $q->where('hall_id', $hallId))
            ->whereBetween('booking_date', [$month->copy()->startOfMonth(), $month->copy()->endOfMonth()])
            ->orderBy('booking_date')
            ->orderBy('start_time')
            ->get()
            ->groupBy(fn ($b) => $b->booking_date->format('Y-m-d'));

        return view('dashboard.bookings.all', compact('bookings', 'halls', 'hallId', 'month'));
    }

    public function create()
    {
        $halls = Hall::where('is_active', true)->orderBy('name')->get();

        return view('dashboard.bookings.create', compact('halls'));
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'hall_id' => ['required', 'exists:halls,id'],
            'booking_date' => ['required', 'date', 'after_or_equal:today'],
            'start_time' => ['required', 'date_format:H:i'],
            'end_time' => ['required', 'date_format:H:i', 'after:start_time'],
            'reason' => ['nullable', 'string', 'max:190'],
        ]);

        $booking = DB::transaction(function () use ($data) {
            $overlaps = HallBooking::where('hall_id', $data['hall_id'])
                ->whereDate('booking_date', $data['booking_date'])
                ->where('status', '!=', 'cancelled')
                ->where('start_time', '<', $data['end_time'])
                ->where('end_time', '>', $data['start_time'])
                ->lockForUpdate()
                ->exists();

            if ($overlaps) {
                return null;
            }

            return HallBooking::create([
                ...$data,
                'user_id' => auth()->id(),
                'status' => 'approved',
            ]);
        });

        if (! $booking) {
            return back()->withInput()->with('error', 'This hall is already booked for part of that time range. Please choose a different time or hall.');
        }

        return redirect()->route('bookings.index')->with('success', "Hall booked for {$booking->booking_date->format('M d, Y')}.");
    }

    /** A booking's own owner can always cancel it; cancelling someone else's is admin-only. */
    public function cancel(HallBooking $booking)
    {
        abort_unless($booking->user_id === auth()->id() || auth()->user()->isAdmin(), 403);

        $booking->update(['status' => 'cancelled']);

        return back()->with('success', 'Booking cancelled.');
    }

    /** AJAX: halls with no conflicting booking for the requested date/time range. */
    public function availability(Request $request)
    {
        $data = $request->validate([
            'booking_date' => ['required', 'date'],
            'start_time' => ['required', 'date_format:H:i'],
            'end_time' => ['required', 'date_format:H:i', 'after:start_time'],
        ]);

        $busyHallIds = HallBooking::whereDate('booking_date', $data['booking_date'])
            ->where('status', '!=', 'cancelled')
            ->where('start_time', '<', $data['end_time'])
            ->where('end_time', '>', $data['start_time'])
            ->pluck('hall_id');

        $available = Hall::where('is_active', true)->whereNotIn('id', $busyHallIds)->get(['id', 'name', 'location', 'capacity']);

        return response()->json(['halls' => $available]);
    }
}
