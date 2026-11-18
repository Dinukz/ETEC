<?php

namespace App\Models;

use App\Concerns\Auditable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Payment extends Model
{
    use Auditable, HasFactory;

    protected $fillable = [
        'payment_code', 'user_id', 'course_id', 'enrollment_id', 'consultant_id', 'requested_tier',
        'group_id', 'amount', 'currency', 'method', 'payment_type', 'reference_number', 'receipt_path',
        'status', 'notes', 'paid_at', 'verified_at',
        // ⚠️ NOT requested_by_consultant_id (2026-11-03): written only with
        // forceFill() — see App\Services\RegistrationPayer.
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'paid_at' => 'datetime',
            'verified_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class);
    }

    public function enrollment(): BelongsTo
    {
        return $this->belongsTo(Enrollment::class);
    }

    public function consultant(): BelongsTo
    {
        return $this->belongsTo(Consultant::class);
    }

    public function group(): BelongsTo
    {
        return $this->belongsTo(Group::class);
    }

    /** The consultant paying for this registration when it is not its holder — see RegistrationPayer. */
    public function requestedBy(): BelongsTo
    {
        return $this->belongsTo(Consultant::class, 'requested_by_consultant_id');
    }

    /**
     * The Payment Verification search — ONE definition for the Livewire queue and
     * the controller's CSV export, so the two cannot find different payments.
     *
     * Matches the payment code, the student's name / email, and since 2026-09-17
     * ("Payment Verification search bar ekata SRN and SCN number eka dann one"):
     *   - the buyer's SRN, or their SCN when the buyer is a consultant;
     *   - the SCN of the account the payment was credited to, or of that
     *     account's owner — so a consultant's main SCN finds payments placed on
     *     any of their accounts.
     *
     * ⚠️ Wrapped in ONE where() group. The controller's old search was not, so its
     * orWhereHas() escaped the status filter beside it.
     */
    public function scopeSearch($query, string $term)
    {
        $term = trim($term);

        if ($term === '') {
            return $query;
        }

        $like = "%{$term}%";

        return $query->where(fn ($q) => $q
            ->where('payment_code', 'like', $like)
            ->orWhereHas('user', fn ($u) => $u
                ->where('name', 'like', $like)
                ->orWhere('email', 'like', $like)
                ->orWhereHas('student', fn ($s) => $s->where('student_code', 'like', $like))
                ->orWhereHas('consultant', fn ($c) => $c->where('scn_code', 'like', $like)))
            ->orWhereHas('consultant', fn ($c) => $c
                ->where('scn_code', 'like', $like)
                ->orWhereHas('owner', fn ($o) => $o->where('scn_code', 'like', $like))));
    }

    public function verifications(): HasMany
    {
        return $this->hasMany(PaymentVerification::class);
    }

    public function commissions(): HasMany
    {
        return $this->hasMany(Commission::class);
    }

    public function isVerified(): bool
    {
        return $this->status === 'verified';
    }
}
