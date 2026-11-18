<?php

namespace App\Models;

use App\Concerns\Auditable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Course extends Model
{
    use Auditable, HasFactory, SoftDeletes;

    protected $fillable = [
        'title', 'slug', 'subtitle', 'description', 'thumbnail', 'promo_video_url',
        'price', 'discount_price', 'currency', 'level', 'duration_text', 'category',
        'default_language', 'default_class_type', 'default_course_type',
        'lecturer_id', 'status', 'is_featured', 'meta_title', 'meta_description', 'published_at',
        // A course's OWN half payment and direct service income (2026-10-22);
        // blank = the standard. Columns added by CourseSchemaRepair, read by
        // App\Support\CourseMoney.
        'half_payment_amount', 'direct_commission',
    ];

    protected function casts(): array
    {
        return [
            'price' => 'decimal:2',
            'discount_price' => 'decimal:2',
            'half_payment_amount' => 'decimal:2',
            'direct_commission' => 'decimal:2',
            'is_featured' => 'boolean',
            'published_at' => 'datetime',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'slug';
    }

    public function scopePublished(Builder $query): Builder
    {
        return $query->where('status', 'published');
    }

    public function lecturer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'lecturer_id');
    }

    public function modules(): HasMany
    {
        return $this->hasMany(CourseModule::class);
    }

    public function lessons(): HasMany
    {
        return $this->hasMany(Lesson::class);
    }

    public function enrollments(): HasMany
    {
        return $this->hasMany(Enrollment::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    public function assignments(): HasMany
    {
        return $this->hasMany(Assignment::class);
    }

    public function exams(): HasMany
    {
        return $this->hasMany(Exam::class);
    }

    public function getEffectivePriceAttribute(): float
    {
        return (float) ($this->discount_price ?? $this->price);
    }

    /**
     * Displayable URL for the thumbnail, or null when there is none.
     *
     * Routed through media.show rather than asset('storage/...') — the
     * public/storage symlink does not exist on production (shared hosting, no
     * CLI), which is why MediaController exists at all. Thumbnails uploaded
     * from Course Management were rendering as broken images on the public
     * site for exactly that reason (reported 2026-08-19).
     *
     * An absolute URL is passed through untouched, so a thumbnail pasted in as
     * a remote link keeps working.
     */
    public function getThumbnailUrlAttribute(): ?string
    {
        if (blank($this->thumbnail)) {
            return null;
        }

        return \Illuminate\Support\Str::startsWith($this->thumbnail, ['http://', 'https://'])
            ? $this->thumbnail
            : route('media.show', $this->thumbnail);
    }
}
