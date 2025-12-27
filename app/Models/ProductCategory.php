<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class ProductCategory extends Model
{
    /** @use HasFactory<\Database\Factories\ProductCategoryFactory> */
    use HasFactory;

    protected $fillable = [
        'name',
        'user_id',
        'created_by_user_id',
        'archived_at',
    ];

    protected $casts = [
        'archived_at' => 'datetime',
    ];

    /**
     * Get the products for the category.
     */
    public function products(): HasMany
    {
        return $this->hasMany(Product::class, 'category_id');
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function scopeForAccount(Builder $query, int $accountId): Builder
    {
        return $query->where(function (Builder $query) use ($accountId) {
            $query->where('user_id', $accountId)
                ->orWhereNull('user_id');
        });
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->whereNull('archived_at');
    }

    public function scopeArchived(Builder $query): Builder
    {
        return $query->whereNotNull('archived_at');
    }

    public static function normalizeName(?string $name): string
    {
        $clean = preg_replace('/\s+/', ' ', trim((string) $name));

        return $clean ?: '';
    }

    public static function resolveForAccount(int $accountId, ?int $creatorId, string $name): ?self
    {
        $clean = self::normalizeName($name);
        if ($clean === '') {
            return null;
        }

        $existing = self::query()
            ->whereRaw('LOWER(name) = ?', [strtolower($clean)])
            ->where(function (Builder $query) use ($accountId) {
                $query->where('user_id', $accountId)
                    ->orWhereNull('user_id');
            })
            ->orderByRaw('user_id is null')
            ->orderBy('id')
            ->first();

        if ($existing) {
            return $existing;
        }

        return self::create([
            'name' => $clean,
            'user_id' => $accountId,
            'created_by_user_id' => $creatorId,
        ]);
    }
}
