<?php

namespace App\Models;

use App\Concerns\Auditable;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use Spatie\Permission\Traits\HasRoles;

class User extends Authenticatable implements MustVerifyEmail
{
    /** @use HasFactory<\Database\Factories\UserFactory> */
    use Auditable, HasApiTokens, HasFactory, HasRoles, Notifiable, SoftDeletes;

    protected $fillable = [
        'name',
        'email',
        'username',
        'phone',
        'avatar',
        'profile_locked_at',
        'theme',
        'status',
        'password_must_change',
        'password',
        'email_verified_at',
        'two_factor_secret',
        'two_factor_recovery_codes',
        'two_factor_confirmed_at',
        'last_login_at',
        'last_login_ip',
    ];

    protected $hidden = [
        'password',
        'remember_token',
        'two_factor_secret',
        'two_factor_recovery_codes',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'profile_locked_at' => 'datetime',
            'password_must_change' => 'boolean',
            'password' => 'hashed',
            'two_factor_confirmed_at' => 'datetime',
            'two_factor_recovery_codes' => 'encrypted',
            'last_login_at' => 'datetime',
        ];
    }

    /**
     * Send the password-reset link via our reliable Mail::html path instead of
     * Laravel's default ResetPassword notification — the notification/markdown
     * mail pipeline does not deliver on this host (see TransactionalMail).
     */
    public function sendPasswordResetNotification($token): void
    {
        $url = route('password.reset', ['token' => $token, 'email' => $this->email]);

        \App\Services\TransactionalMail::passwordReset($this->email, $this->name, $url);
    }

    /**
     * The one number this person is known by on screen.
     *
     * ⚠️ A PROMOTED STUDENT IS SHOWN THEIR SCN, NEVER THEIR SRN (2026-09-01).
     *
     * Owner: "SRN 100 thiyen student consultant kenk una hati eyage SRN number
     * eka change wenwa SCN 100 widiyta." Since NumberPool reserves a block of
     * three per identity, those are the SAME DIGITS — SRN-00104 becomes
     * SCN-00104 — so this reads as a change of prefix rather than a new number.
     *
     * ⚠️ The `students.student_code` column is NOT rewritten. It stays as
     * history so receipts, reports and anything already issued still resolve;
     * it simply stops being displayed. Nothing in the app renames a code, and
     * nothing should start.
     *
     * ⚠️ Identities issued before the shared pool have MISMATCHED numbers (an
     * SRN-00003 may hold SCN-00117). That is deliberate — see NumberPool's
     * "forward only" note. This accessor still shows the SCN for them, which is
     * the number they are actually known by as a consultant.
     */
    public function displayCode(): ?string
    {
        // ⚠️ loadMissing, NOT a bare property read. This is called from the
        // dashboard chrome on every page, where $user is auth()->user() with no
        // relations loaded — and Model::preventLazyLoading() is active outside
        // production, so a bare read throws on every single request.
        //
        // loadMissing is an EXPLICIT load, so it is allowed, and it is a no-op
        // wherever the caller already eager-loaded (the group member list does).
        // The cost is that it will quietly N+1 if someone loops over users
        // without eager-loading; list screens should keep loading
        // `user.consultant` themselves, and Student::displayCode() exists so
        // student lists never come through here at all.
        $this->loadMissing(['consultant', 'student']);

        return $this->consultant?->scn_code
            ?? $this->consultant?->consultant_code
            ?? $this->student?->student_code;
    }

    /**
     * The number for this user's CURRENT ROLE: SCN for a consultant, SRN for a
     * student, nothing for staff (2026-09-17, User Management).
     *
     * ⚠️ NOT displayCode(). That one prefers the SCN whenever a consultants row
     * exists — right for the dashboard chrome, wrong here: a consultant demoted to
     * student keeps their consultants row (nothing deletes it), so the table would
     * go on printing the SCN after the role changed. The owner asked for the
     * number to follow a role update.
     *
     * Callers listing many users should eager-load roles, consultant and student.
     */
    public function roleCode(): ?string
    {
        $this->loadMissing(['roles', 'consultant', 'student']);

        if ($this->hasRole('consultant')) {
            return $this->consultant?->scn_code ?? $this->consultant?->consultant_code;
        }

        if ($this->hasRole('student')) {
            return $this->student?->student_code;
        }

        return null;
    }

    // ----- Relationships -------------------------------------------------

    public function student(): HasOne
    {
        return $this->hasOne(Student::class);
    }

    public function consultant(): HasOne
    {
        return $this->hasOne(Consultant::class);
    }

    public function enrollments(): HasMany
    {
        return $this->hasMany(Enrollment::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    public function certificates(): HasMany
    {
        return $this->hasMany(Certificate::class);
    }

    public function examResults(): HasMany
    {
        return $this->hasMany(ExamResult::class);
    }

    public function assignmentSubmissions(): HasMany
    {
        return $this->hasMany(AssignmentSubmission::class);
    }

    public function taughtCourses(): HasMany
    {
        return $this->hasMany(Course::class, 'lecturer_id');
    }

    public function blogs(): HasMany
    {
        return $this->hasMany(Blog::class, 'author_id');
    }

    // ----- Helpers -------------------------------------------------------

    public function isSuperAdmin(): bool
    {
        return $this->hasRole('super_admin');
    }

    public function isStaff(): bool
    {
        return $this->hasAnyRole(['super_admin', 'admin', 'accountant', 'lecturer']);
    }

    public function isAdmin(): bool
    {
        return $this->hasAnyRole(['super_admin', 'admin']);
    }

    /**
     * May this user VERIFY or REJECT a payment?
     *
     * Deliberately narrower than "may see the Payments tab": a group supporter
     * (2026-09-17) may look at every payment but never action one, because a
     * verification mints commission. Checked by PaymentQueue's Livewire actions
     * as well as the views, since Livewire skips route middleware.
     */
    public function canActionPayments(): bool
    {
        return $this->hasAnyRole(['super_admin', 'admin', 'accountant']);
    }

    /**
     * The primary role used to route this user to their dashboard.
     * Falls back to 'student' if somehow no role is assigned.
     */
    public function primaryRole(): string
    {
        foreach (['super_admin', 'admin', 'accountant', 'lecturer', 'group_supporter', 'consultant', 'student'] as $role) {
            if ($this->hasRole($role)) {
                return $role;
            }
        }

        return 'student';
    }

    /** Route name of this user's role-specific dashboard. */
    public function dashboardRoute(): string
    {
        return match ($this->primaryRole()) {
            'super_admin' => 'super-admin.dashboard',
            'admin' => 'admin.dashboard',
            'accountant' => 'accountant.dashboard',
            'lecturer' => 'lecturer.dashboard',
            // No dashboard of their own — the Payments tab is their home page.
            'group_supporter' => 'payments.index',
            'consultant' => 'consultant.dashboard',
            default => 'student.dashboard',
        };
    }

    public function scopeActive($query)
    {
        return $query->where('status', 'active');
    }
}
