<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

class Setting extends Model
{
    protected $fillable = ['key', 'value', 'type', 'group', 'is_public'];

    protected function casts(): array
    {
        return ['is_public' => 'boolean'];
    }

    /** Fetch a setting value, cast to its declared type, with a fallback. */
    public static function get(string $key, mixed $default = null): mixed
    {
        $setting = Cache::rememberForever("setting.$key", fn () => static::query()->where('key', $key)->first());

        if (! $setting) {
            return $default;
        }

        return static::castValue($setting->value, $setting->type);
    }

    /** Create or update a setting and refresh its cache entry. */
    public static function set(string $key, mixed $value, string $type = 'string', string $group = 'general'): self
    {
        $stored = is_array($value) ? json_encode($value) : (is_bool($value) ? ($value ? '1' : '0') : (string) $value);

        $setting = static::query()->updateOrCreate(
            ['key' => $key],
            ['value' => $stored, 'type' => $type, 'group' => $group],
        );

        Cache::forget("setting.$key");

        return $setting;
    }

    protected static function castValue(?string $value, string $type): mixed
    {
        return match ($type) {
            'integer' => (int) $value,
            'float' => (float) $value,
            'boolean' => filter_var($value, FILTER_VALIDATE_BOOLEAN),
            'json' => json_decode((string) $value, true),
            default => $value,
        };
    }

    protected static function booted(): void
    {
        static::saved(fn (Setting $s) => Cache::forget("setting.$s->key"));
        static::deleted(fn (Setting $s) => Cache::forget("setting.$s->key"));
    }
}
