<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

class Request extends Model
{
    use HasFactory;
    use SoftDeletes;

    public const CUSTOMER_CONVERSION_META_KEY = 'customer_conversion';

    public const LOSS_META_KEY = 'loss';

    public const STATUS_NEW = 'REQ_NEW';

    public const STATUS_CALL_REQUESTED = 'REQ_CALL_REQUESTED';

    public const STATUS_CONTACTED = 'REQ_CONTACTED';

    public const STATUS_QUALIFIED = 'REQ_QUALIFIED';

    public const STATUS_QUOTE_SENT = 'REQ_QUOTE_SENT';

    public const STATUS_WON = 'REQ_WON';

    public const STATUS_LOST = 'REQ_LOST';

    public const STATUS_CONVERTED = 'REQ_CONVERTED';

    public const LOST_REASON_OPTIONS = [
        'budget' => 'requests.loss.reasons.budget',
        'timing' => 'requests.loss.reasons.timing',
        'no_fit' => 'requests.loss.reasons.no_fit',
        'competitor' => 'requests.loss.reasons.competitor',
        'no_response' => 'requests.loss.reasons.no_response',
        'duplicate' => 'requests.loss.reasons.duplicate',
        'internal_decision' => 'requests.loss.reasons.internal_decision',
        'other' => 'requests.loss.reasons.other',
    ];

    public const QUOTE_STATUS_TO_REQUEST_STATUS = [
        'sent' => self::STATUS_QUOTE_SENT,
        'accepted' => self::STATUS_WON,
        'declined' => self::STATUS_LOST,
    ];

    public const STATUSES = [
        self::STATUS_NEW,
        self::STATUS_CALL_REQUESTED,
        self::STATUS_CONTACTED,
        self::STATUS_QUALIFIED,
        self::STATUS_QUOTE_SENT,
        self::STATUS_WON,
        self::STATUS_LOST,
        self::STATUS_CONVERTED,
    ];

    protected $fillable = [
        'user_id',
        'customer_id',
        'assigned_team_member_id',
        'external_customer_id',
        'channel',
        'status',
        'service_type',
        'urgency',
        'title',
        'description',
        'contact_name',
        'contact_email',
        'contact_phone',
        'country',
        'state',
        'city',
        'street1',
        'street2',
        'postal_code',
        'lat',
        'lng',
        'is_serviceable',
        'converted_at',
        'first_response_at',
        'last_activity_at',
        'sla_due_at',
        'triage_priority',
        'risk_level',
        'stale_since_at',
        'archived_at',
        'archived_by_user_id',
        'archive_reason',
        'deleted_by_user_id',
        'duplicate_of_prospect_id',
        'merged_into_prospect_id',
        'status_updated_at',
        'next_follow_up_at',
        'lost_reason',
        'meta',
    ];

    protected $casts = [
        'lat' => 'decimal:7',
        'lng' => 'decimal:7',
        'is_serviceable' => 'boolean',
        'converted_at' => 'datetime',
        'first_response_at' => 'datetime',
        'last_activity_at' => 'datetime',
        'sla_due_at' => 'datetime',
        'triage_priority' => 'integer',
        'stale_since_at' => 'datetime',
        'archived_at' => 'datetime',
        'deleted_at' => 'datetime',
        'status_updated_at' => 'datetime',
        'next_follow_up_at' => 'datetime',
        'meta' => 'array',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(TeamMember::class, 'assigned_team_member_id');
    }

    public function archivedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'archived_by_user_id');
    }

    public function deletedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'deleted_by_user_id');
    }

    public function duplicateOf(): BelongsTo
    {
        return $this->belongsTo(self::class, 'duplicate_of_prospect_id');
    }

    public function mergedInto(): BelongsTo
    {
        return $this->belongsTo(self::class, 'merged_into_prospect_id');
    }

    public function quote(): HasOne
    {
        return $this->hasOne(Quote::class);
    }

    public function notes(): HasMany
    {
        return $this->hasMany(LeadNote::class, 'request_id')->latest('created_at');
    }

    public function media(): HasMany
    {
        return $this->hasMany(LeadMedia::class, 'request_id')->latest('created_at');
    }

    public function tasks(): HasMany
    {
        return $this->hasMany(Task::class, 'request_id')->latest('created_at');
    }

    public function statusHistories(): HasMany
    {
        return $this->hasMany(ProspectStatusHistory::class, 'request_id')->latest('created_at');
    }

    public function prospectInteractions(): HasMany
    {
        return $this->hasMany(ProspectInteraction::class, 'request_id')->latest('created_at');
    }

    public function scopeByUser(Builder $query, int $userId): Builder
    {
        return $query->where('user_id', $userId);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->whereNull('archived_at');
    }

    public function scopeArchived(Builder $query): Builder
    {
        return $query->whereNotNull('archived_at');
    }

    public function isArchived(): bool
    {
        return $this->archived_at !== null;
    }

    public function isDeleted(): bool
    {
        return $this->deleted_at !== null;
    }

    public function isAnonymized(): bool
    {
        return filled(data_get($this->meta, 'privacy.anonymized_at'));
    }

    public function isConvertedToCustomer(): bool
    {
        return $this->status === self::STATUS_CONVERTED && $this->customer_id !== null;
    }

    /**
     * @return array<string, mixed>
     */
    public function customerConversionMeta(): array
    {
        return (array) data_get($this->meta, self::CUSTOMER_CONVERSION_META_KEY, []);
    }

    /**
     * @return array<string, mixed>
     */
    public function lossMeta(): array
    {
        return (array) data_get($this->meta, self::LOSS_META_KEY, []);
    }

    public function convertedByUserId(): ?int
    {
        $value = $this->customerConversionMeta()['converted_by_user_id'] ?? null;

        return is_numeric($value) ? (int) $value : null;
    }

    public function lostReasonComment(): ?string
    {
        $value = trim((string) ($this->lossMeta()['comment'] ?? ''));

        return $value !== '' ? $value : null;
    }

    public function lostReasonLabelKey(): ?string
    {
        $reason = trim((string) ($this->lost_reason ?? ''));

        return $reason !== '' ? (self::LOST_REASON_OPTIONS[$reason] ?? null) : null;
    }

    public function companyName(): ?string
    {
        $value = trim((string) (data_get($this->meta, 'company_name') ?? ''));

        return $value !== '' ? $value : null;
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    public function mergeCustomerConversionMeta(array $attributes): array
    {
        $meta = (array) ($this->meta ?? []);
        data_set(
            $meta,
            self::CUSTOMER_CONVERSION_META_KEY,
            array_merge($this->customerConversionMeta(), $attributes)
        );

        return $meta;
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    public function mergeLossMeta(array $attributes): array
    {
        $meta = (array) ($this->meta ?? []);
        data_set(
            $meta,
            self::LOSS_META_KEY,
            array_merge($this->lossMeta(), $attributes)
        );

        return $meta;
    }

    /**
     * @return array<string, mixed>
     */
    public function clearLossMeta(): array
    {
        $meta = (array) ($this->meta ?? []);
        data_forget($meta, self::LOSS_META_KEY);

        return $meta;
    }

    /**
     * @return array<int, array{id:string,label_key:string}>
     */
    public static function lostReasonOptions(): array
    {
        return collect(self::LOST_REASON_OPTIONS)
            ->map(fn (string $labelKey, string $id): array => [
                'id' => $id,
                'label_key' => $labelKey,
            ])
            ->values()
            ->all();
    }

    public static function statusForQuoteStatus(?string $quoteStatus): ?string
    {
        if (! is_string($quoteStatus) || $quoteStatus === '') {
            return null;
        }

        return self::QUOTE_STATUS_TO_REQUEST_STATUS[$quoteStatus] ?? null;
    }
}
