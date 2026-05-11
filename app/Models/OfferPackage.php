<?php

namespace App\Models;

use App\Enums\CurrencyCode;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class OfferPackage extends Model
{
    use HasFactory;

    public const TYPE_PACK = 'pack';

    public const TYPE_FORFAIT = 'forfait';

    public const STATUS_DRAFT = 'draft';

    public const STATUS_ACTIVE = 'active';

    public const STATUS_ARCHIVED = 'archived';

    public const PRICING_FIXED = 'fixed';

    public const UNIT_SESSION = 'session';

    public const UNIT_HOUR = 'hour';

    public const UNIT_VISIT = 'visit';

    public const UNIT_CREDIT = 'credit';

    public const UNIT_MONTH = 'month';

    public const RECURRENCE_MONTHLY = 'monthly';

    public const RECURRENCE_QUARTERLY = 'quarterly';

    public const RECURRENCE_YEARLY = 'yearly';

    protected $fillable = [
        'user_id',
        'name',
        'slug',
        'type',
        'status',
        'description',
        'image_path',
        'pricing_mode',
        'price',
        'currency_code',
        'validity_days',
        'included_quantity',
        'unit_type',
        'is_public',
        'is_recurring',
        'recurrence_frequency',
        'renewal_notice_days',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'price' => 'decimal:2',
            'validity_days' => 'integer',
            'included_quantity' => 'integer',
            'is_public' => 'boolean',
            'is_recurring' => 'boolean',
            'renewal_notice_days' => 'integer',
            'metadata' => 'array',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $offer): void {
            $offer->pricing_mode = $offer->pricing_mode ?: self::PRICING_FIXED;
            $offer->status = $offer->status ?: self::STATUS_DRAFT;
            $offer->currency_code = CurrencyCode::tryFromMixed($offer->currency_code)?->value
                ?? CurrencyCode::default()->value;

            if (! $offer->slug && $offer->user_id) {
                $offer->slug = self::uniqueSlug((int) $offer->user_id, (string) $offer->name);
            }
        });

        static::updating(function (self $offer): void {
            if ($offer->isDirty('name') && ! $offer->isDirty('slug')) {
                $offer->slug = self::uniqueSlug((int) $offer->user_id, (string) $offer->name, $offer->id);
            }
        });
    }

    public static function types(): array
    {
        return [self::TYPE_PACK, self::TYPE_FORFAIT];
    }

    public static function statuses(): array
    {
        return [self::STATUS_DRAFT, self::STATUS_ACTIVE, self::STATUS_ARCHIVED];
    }

    public static function unitTypes(): array
    {
        return [self::UNIT_SESSION, self::UNIT_HOUR, self::UNIT_VISIT, self::UNIT_CREDIT, self::UNIT_MONTH];
    }

    public static function recurrenceFrequencies(): array
    {
        return [self::RECURRENCE_MONTHLY, self::RECURRENCE_QUARTERLY, self::RECURRENCE_YEARLY];
    }

    public static function uniqueSlug(int $userId, string $name, ?int $ignoreId = null): string
    {
        $base = Str::slug($name) ?: 'offer';
        $slug = $base;
        $counter = 2;

        while (self::query()
            ->where('user_id', $userId)
            ->where('slug', $slug)
            ->when($ignoreId, fn (Builder $query) => $query->whereKeyNot($ignoreId))
            ->exists()) {
            $slug = $base.'-'.$counter;
            $counter++;
        }

        return $slug;
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(OfferPackageItem::class)->orderBy('sort_order');
    }

    public function customerPackages(): HasMany
    {
        return $this->hasMany(CustomerPackage::class);
    }

    public function scopeForAccount(Builder $query, int $accountId): Builder
    {
        return $query->where('user_id', $accountId);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_ACTIVE);
    }

    public function scopePublic(Builder $query): Builder
    {
        return $query->where('is_public', true);
    }

    public function scopeRecurring(Builder $query): Builder
    {
        return $query->where('is_recurring', true);
    }

    public function scopeFilter(Builder $query, array $filters): Builder
    {
        return $query
            ->when($filters['search'] ?? null, function (Builder $query, string $search): void {
                $query->where(function (Builder $query) use ($search): void {
                    $query->where('name', 'like', '%'.$search.'%')
                        ->orWhere('description', 'like', '%'.$search.'%');
                });
            })
            ->when($filters['type'] ?? null, fn (Builder $query, string $type) => $query->where('type', $type))
            ->when($filters['status'] ?? null, fn (Builder $query, string $status) => $query->where('status', $status))
            ->when(array_key_exists('is_public', $filters) && $filters['is_public'] !== '', function (Builder $query) use ($filters): void {
                $isPublic = filter_var($filters['is_public'], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
                if ($isPublic !== null) {
                    $query->where('is_public', $isPublic);
                }
            });
    }
}
