<?php

namespace App\Models;

use App\Concerns\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class HallBooking extends Model
{
    use Auditable;

    protected $fillable = ['hall_id', 'user_id', 'booking_date', 'start_time', 'end_time', 'reason', 'status'];

    protected function casts(): array
    {
        return ['booking_date' => 'date'];
    }

    public function hall(): BelongsTo
    {
        return $this->belongsTo(Hall::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
