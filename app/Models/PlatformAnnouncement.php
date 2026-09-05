<?php

namespace App\Models;

use App\Support\LocalePreference;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Facades\Storage;

class PlatformAnnouncement extends Model
{
    public const STATUSES = ['draft', 'active'];

    public const AUDIENCES = ['all', 'tenants', 'new_tenants'];

    public const PLACEMENTS = ['internal', 'quick_actions'];

    public const DISPLAY_STYLES = ['standard', 'media_only'];

    public const MEDIA_TYPES = ['none', 'image', 'video'];

    public const CONTENT_LOCALES = ['fr', 'en', 'es'];

    public const CONTENT_FIELDS = ['title', 'body', 'link_label'];

    protected $fillable = [
        'title',
        'body',
        'translations',
        'status',
        'audience',
        'placement',
        'display_style',
        'background_color',
        'new_tenant_days',
        'media_type',
        'media_url',
        'media_path',
        'link_label',
        'link_url',
        'priority',
        'starts_at',
        'ends_at',
        'created_by',
    ];

    protected $casts = [
        'translations' => 'array',
        'priority' => 'integer',
        'new_tenant_days' => 'integer',
        'starts_at' => 'datetime',
        'ends_at' => 'datetime',
    ];

    public function tenants(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'platform_announcement_tenants', 'announcement_id', 'tenant_id');
    }

    public function scopeActive($query)
    {
        return $query->where('status', 'active');
    }

    public function scopeOrderedForDisplay(Builder $query): Builder
    {
        return $query
            ->orderByDesc('priority')
            ->orderByDesc('starts_at')
            ->orderByDesc('created_at')
            ->orderByDesc('id');
    }

    public static function hasUsableMedia(?string $mediaType, ?string $mediaUrl, ?string $mediaPath): bool
    {
        return in_array($mediaType, ['image', 'video'], true)
            && (filled($mediaUrl) || filled($mediaPath));
    }

    /**
     * @return array<string, array<string, string>>
     */
    public static function normalizeTranslations(mixed $translations): array
    {
        if (! is_array($translations)) {
            return [];
        }

        $normalized = [];

        foreach (self::CONTENT_LOCALES as $locale) {
            $content = $translations[$locale] ?? null;
            if (! is_array($content)) {
                continue;
            }

            foreach (self::CONTENT_FIELDS as $field) {
                $value = $content[$field] ?? null;
                if (! is_string($value) || trim($value) === '') {
                    continue;
                }

                $normalized[$locale][$field] = trim($value);
            }
        }

        return $normalized;
    }

    /**
     * @return array{title: ?string, body: ?string, link_label: ?string}
     */
    public function localizedContent(?string $locale = null): array
    {
        $requestedLocale = LocalePreference::isSupported($locale)
            ? LocalePreference::normalize($locale)
            : LocalePreference::normalize(app()->getLocale());
        $fallbackLocales = array_values(array_unique([$requestedLocale, 'fr', 'en']));
        $translations = self::normalizeTranslations($this->translations);
        $resolved = [];

        foreach (self::CONTENT_FIELDS as $field) {
            $resolved[$field] = null;

            foreach ($fallbackLocales as $fallbackLocale) {
                $value = $translations[$fallbackLocale][$field] ?? null;
                if (filled($value)) {
                    $resolved[$field] = $value;
                    break;
                }
            }

            if (! filled($resolved[$field])) {
                $legacyValue = $this->getRawOriginal($field);
                $resolved[$field] = filled($legacyValue) ? (string) $legacyValue : null;
            }
        }

        return $resolved;
    }

    public static function firstTranslatedValue(array $translations, string $field): ?string
    {
        if (! in_array($field, self::CONTENT_FIELDS, true)) {
            return null;
        }

        $normalized = self::normalizeTranslations($translations);

        foreach (self::CONTENT_LOCALES as $locale) {
            $value = $normalized[$locale][$field] ?? null;
            if (filled($value)) {
                return $value;
            }
        }

        return null;
    }

    public function getMediaUrlAttribute(): ?string
    {
        $url = $this->attributes['media_url'] ?? null;
        if ($url) {
            return $url;
        }

        $path = $this->attributes['media_path'] ?? null;
        if (! $path) {
            return null;
        }

        return Storage::disk('public')->url($path);
    }
}
