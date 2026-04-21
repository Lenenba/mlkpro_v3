<?php

namespace App\Models;

use App\Support\CRM\SalesActivityTaxonomy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model as EloquentModel;

class ActivityLog extends Model
{
    use HasFactory;

    protected $appends = [
        'is_sales_activity',
        'sales_activity',
    ];

    protected $fillable = [
        'user_id',
        'subject_type',
        'subject_id',
        'action',
        'description',
        'properties',
    ];

    protected $casts = [
        'properties' => 'array',
    ];

    public function subject(): MorphTo
    {
        return $this->morphTo();
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function scopeSalesActivity(Builder $query): Builder
    {
        return $query->whereIn('action', SalesActivityTaxonomy::actions());
    }

    public function getIsSalesActivityAttribute(): bool
    {
        return SalesActivityTaxonomy::isSalesActivity($this->action);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getSalesActivityAttribute(): ?array
    {
        return SalesActivityTaxonomy::present(
            $this->action,
            (array) ($this->properties ?? [])
        );
    }

    public static function record(?User $user, EloquentModel $subject, string $action, array $properties = [], ?string $description = null): self
    {
        return self::create([
            'user_id' => $user?->id,
            'subject_type' => $subject->getMorphClass(),
            'subject_id' => $subject->getKey(),
            'action' => $action,
            'description' => $description,
            'properties' => $properties ?: null,
        ]);
    }
}
