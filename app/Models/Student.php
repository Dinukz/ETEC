<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Student extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id', 'student_code', 'consultant_id', 'partner_consultant_id', 'group_id',
        'nic', 'nic_owned_by_student', 'nic_owner_name', 'nic_owner_relationship', 'no_objection_letter_path',
        'address', 'city', 'district', 'date_of_birth', 'gender', 'guardian_name', 'guardian_phone',
        'education_level', 'language', 'class_type', 'course_type', 'enrolled_at', 'status',
    ];

    protected function casts(): array
    {
        return [
            'date_of_birth' => 'date',
            'enrolled_at' => 'datetime',
            'nic_owned_by_student' => 'boolean',
        ];
    }

    /**
     * The number to SHOW for this person — their SCN once they are a
     * consultant, otherwise their SRN.
     *
     * ⚠️ Delegates to User::displayCode(); read its note before changing this.
     * The `student_code` column is never rewritten, it just stops being shown.
     *
     * ⚠️ Callers must eager-load `user.consultant`, or this throws:
     * Model::preventLazyLoading() is active outside production. Falling back to
     * `student_code` when the relation is absent would silently show the wrong
     * number on any screen that forgot, which is worse than a loud failure.
     *
     * ⚠️ IT DOES NOT DELEGATE TO User::displayCode(). That method's own fallback
     * reads `$user->student`, which from here is the round trip
     * student → user → student — and it threw a LazyLoadingViolation on the
     * admission report the moment it shipped. This model already holds the SRN;
     * it only needs to ask whether a consultant exists.
     */
    public function displayCode(): ?string
    {
        return $this->user?->consultant?->scn_code
            ?? $this->user?->consultant?->consultant_code
            ?? $this->student_code;
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function consultant(): BelongsTo
    {
        return $this->belongsTo(Consultant::class);
    }

    /** Informational only — the second consultant named on the registration form. */
    public function partnerConsultant(): BelongsTo
    {
        return $this->belongsTo(Consultant::class, 'partner_consultant_id');
    }

    public function group(): BelongsTo
    {
        return $this->belongsTo(Group::class);
    }
}
