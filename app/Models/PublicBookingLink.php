<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class PublicBookingLink extends Model
{
    use HasFactory;

    protected $fillable = [
        'account_id',
        'name',
        'slug',
        'description',
        'is_active',
        'requires_manual_confirmation',
        'requires_deposit',
        'expires_at',
        'source',
        'campaign',
        'metadata',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'requires_manual_confirmation' => 'boolean',
        'requires_deposit' => 'boolean',
        'expires_at' => 'datetime',
        'metadata' => 'array',
    ];

    public function account(): BelongsTo
    {
        return $this->belongsTo(User::class, 'account_id');
    }

    public function services(): BelongsToMany
    {
        return $this->belongsToMany(Product::class, 'public_booking_link_services', 'public_booking_link_id', 'service_id')
            ->withTimestamps();
    }

    public function reservations(): HasMany
    {
        return $this->hasMany(Reservation::class);
    }

    public function prospects(): HasMany
    {
        return $this->hasMany(Request::class);
    }

    public function scopeForAccount(Builder $query, int $accountId): Builder
    {
        return $query->where('account_id', $accountId);
    }

    public function isAvailable(): bool
    {
        if (! $this->is_active) {
            return false;
        }

        return ! $this->expires_at || $this->expires_at->isFuture();
    }

    public function publicUrl(?User $account = null): string
    {
        $account ??= $this->relationLoaded('account') ? $this->account : null;
        $company = $account?->company_slug ?: (string) $this->account_id;

        return route('public.booking.show', [
            'company' => $company,
            'slug' => $this->slug,
        ]);
    }

    public static function normalizeSlug(string $value, string $fallback = 'booking'): string
    {
        $slug = Str::slug($value);

        return $slug !== '' ? $slug : $fallback;
    }
}
