<?php

namespace App\Models;

use App\Enums\CustomerClientType;
use App\Support\LocalePreference;
use App\Traits\GeneratesSequentialNumber;
use Illuminate\Contracts\Translation\HasLocalePreference as HasLocalePreferenceContract;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\Storage;

class Customer extends Model implements HasLocalePreferenceContract
{
    use GeneratesSequentialNumber, HasFactory, Notifiable;

    public const DEFAULT_LOGO_PATH = '/images/presets/company-1.svg';

    /**
     * The attributes that are mass assignable.
     *
     * @var array<string>
     */
    protected $fillable = [
        'user_id',
        'portal_user_id',
        'portal_access',
        'number',
        'first_name',
        'last_name',
        'company_name',
        'client_type',
        'registration_number',
        'industry',
        'email',
        'phone',
        'description',
        'tags',
        'is_vip',
        'vip_tier_id',
        'vip_tier_code',
        'vip_since_at',
        'logo',
        'header_image',
        'refer_by',
        'salutation',
        'billing_same_as_physical',
        'billing_mode',
        'billing_cycle',
        'billing_grouping',
        'billing_delay_days',
        'billing_date_rule',
        'discount_rate',
        'loyalty_points_balance',
        'is_active',
        'auto_accept_quotes',
        'auto_validate_jobs',
        'auto_validate_tasks',
        'auto_validate_invoices',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var array<string>
     */
    protected $hidden = [
        'user_id', // Optionnel si vous ne souhaitez pas exposer l'ID de l'utilisateur
    ];

    protected $casts = [
        'billing_same_as_physical' => 'boolean',
        'client_type' => 'string',
        'portal_access' => 'boolean',
        'is_active' => 'boolean',
        'is_vip' => 'boolean',
        'auto_accept_quotes' => 'boolean',
        'auto_validate_jobs' => 'boolean',
        'auto_validate_tasks' => 'boolean',
        'auto_validate_invoices' => 'boolean',
        'tags' => 'array',
        'vip_since_at' => 'datetime',
        'billing_delay_days' => 'integer',
        'discount_rate' => 'decimal:2',
        'loyalty_points_balance' => 'integer',
    ];

    protected $appends = [
        'logo_url',
        'header_image_url',
    ];

    protected static function boot()
    {
        parent::boot();

        // Auto-generate the customer number before creating
        static::creating(function ($customer) {
            $customer->number = self::generateNumber($customer->user_id, 'C');
            $customer->client_type = CustomerClientType::infer(
                $customer->client_type,
                $customer->company_name
            )->value;
        });
    }

    /**
     * Get the user that owns the customer.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function portalUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'portal_user_id');
    }

    public function preferredLocale(): string
    {
        return LocalePreference::forCustomer($this);
    }

    public function vipTier(): BelongsTo
    {
        return $this->belongsTo(VipTier::class, 'vip_tier_id');
    }

    public function mailingLists(): BelongsToMany
    {
        return $this->belongsToMany(MailingList::class, 'mailing_list_customers')
            ->withPivot(['added_by_user_id', 'added_at'])
            ->withTimestamps();
    }

    public function properties(): HasMany
    {
        return $this->hasMany(Property::class)
            ->orderByDesc('is_default')
            ->orderBy('id');
    }

    public function defaultProperty(): HasOne
    {
        return $this->hasOne(Property::class)
            ->where('is_default', true);
    }

    public function physicalAddress()
    {
        return $this->properties()->where('type', 'physical')->first();
    }

    public function billingAddress()
    {
        return $this->properties()->where('type', 'billing')->first();
    }

    public function invoices(): HasMany
    {
        return $this->hasMany(Invoice::class);
    }

    public function sales(): HasMany
    {
        return $this->hasMany(Sale::class);
    }

    public function productReviews(): HasMany
    {
        return $this->hasMany(ProductReview::class);
    }

    public function orderReviews(): HasMany
    {
        return $this->hasMany(OrderReview::class);
    }

    public function requests(): HasMany
    {
        return $this->hasMany(Request::class)->orderByDesc('created_at');
    }

    public function reservations(): HasMany
    {
        return $this->hasMany(Reservation::class, 'client_id')->orderBy('starts_at');
    }

    public function loyaltyPointLedgers(): HasMany
    {
        return $this->hasMany(LoyaltyPointLedger::class);
    }

    public function campaignRecipients(): HasMany
    {
        return $this->hasMany(CampaignRecipient::class);
    }

    public function campaignEvents(): HasMany
    {
        return $this->hasMany(CampaignEvent::class);
    }

    public function consents(): HasMany
    {
        return $this->hasMany(CustomerConsent::class);
    }

    public function optOuts(): HasMany
    {
        return $this->hasMany(CustomerOptOut::class);
    }

    public function interestScores(): HasMany
    {
        return $this->hasMany(CustomerInterestScore::class);
    }

    public function behaviorEvents(): HasMany
    {
        return $this->hasMany(CustomerBehaviorEvent::class);
    }

    public function reservationReviews(): HasMany
    {
        return $this->hasMany(ReservationReview::class, 'client_id');
    }

    public function reservationWaitlists(): HasMany
    {
        return $this->hasMany(ReservationWaitlist::class, 'client_id');
    }

    public function quotes(): HasMany
    {
        return $this->hasMany(Quote::class)
            ->whereNull('archived_at')
            ->with(['products', 'property']);
    }

    /**
     * Get the works for the customer.
     */
    public function works(): HasMany
    {
        return $this->hasMany(Work::class, 'customer_id');
    }

    /**
     * Scope a query to only include customers of a given user.
     */
    public function scopeByUser(Builder $query, int $userId): Builder
    {
        return $query->where('user_id', $userId);
    }

    /**
     * Scope a query to order products by the most recent.
     */
    public function scopeMostRecent(Builder $query): Builder
    {
        return $query->orderByDesc('created_at');
    }

    /**
     * Get the total number of works for the customer.
     */
    public function getTotalWorks(): int
    {
        return $this->works()->count();
    }

    public function getLogoUrlAttribute(): ?string
    {
        $path = $this->logo ?: self::DEFAULT_LOGO_PATH;

        if (str_starts_with($path, 'http://') || str_starts_with($path, 'https://')) {
            return $path;
        }

        if (str_starts_with($path, '/')) {
            return $path;
        }

        return Storage::url($path);
    }

    public function getHeaderImageUrlAttribute(): ?string
    {
        $path = $this->header_image ?: 'customers/customer.png';

        if (str_starts_with($path, 'http://') || str_starts_with($path, 'https://')) {
            return $path;
        }

        if (str_starts_with($path, '/')) {
            return $path;
        }

        return Storage::url($path);
    }

    /**
     * Scope a query to filter products based on given criteria.
     */
    public function scopeFilter(Builder $query, array $filters): Builder
    {
        return $query
            ->when(
                $filters['name'] ?? null,
                function (Builder $query, $name) {
                    $query->where(function (Builder $query) use ($name) {
                    $query->where('company_name', 'like', '%'.$name.'%')
                            ->orWhere('registration_number', 'like', '%'.$name.'%')
                            ->orWhere('industry', 'like', '%'.$name.'%')
                            ->orWhere('first_name', 'like', '%'.$name.'%')
                            ->orWhere('last_name', 'like', '%'.$name.'%')
                            ->orWhere('email', 'like', '%'.$name.'%')
                            ->orWhere('phone', 'like', '%'.$name.'%');
                    });
                }
            )
            ->when(
                $filters['city'] ?? null,
                fn (Builder $query, $city) => $query->whereHas('properties', function (Builder $sub) use ($city) {
                    $sub->where('city', 'like', '%'.$city.'%');
                })
            )
            ->when(
                $filters['country'] ?? null,
                fn (Builder $query, $country) => $query->whereHas('properties', function (Builder $sub) use ($country) {
                    $sub->where('country', 'like', '%'.$country.'%');
                })
            )
            ->when(
                array_key_exists('has_quotes', $filters) && $filters['has_quotes'] !== '',
                function (Builder $query) use ($filters) {
                    $hasQuotes = filter_var($filters['has_quotes'], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
                    if ($hasQuotes === null) {
                        return;
                    }
                    $hasQuotes ? $query->whereHas('quotes') : $query->whereDoesntHave('quotes');
                }
            )
            ->when(
                array_key_exists('has_works', $filters) && $filters['has_works'] !== '',
                function (Builder $query) use ($filters) {
                    $hasWorks = filter_var($filters['has_works'], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
                    if ($hasWorks === null) {
                        return;
                    }
                    $hasWorks ? $query->whereHas('works') : $query->whereDoesntHave('works');
                }
            )
            ->when(
                $filters['status'] ?? null,
                function (Builder $query, $status) {
                    if ($status === 'active') {
                        $query->where('is_active', true);
                    } elseif ($status === 'archived') {
                        $query->where('is_active', false);
                    }
                }
            )
            ->when(
                $filters['created_from'] ?? null,
                fn (Builder $query, $createdFrom) => $query->whereDate('created_at', '>=', $createdFrom)
            )
            ->when(
                $filters['created_to'] ?? null,
                fn (Builder $query, $createdTo) => $query->whereDate('created_at', '<=', $createdTo)
            )
            ->when(
                array_key_exists('is_vip', $filters) && $filters['is_vip'] !== '',
                function (Builder $query) use ($filters) {
                    $isVip = filter_var($filters['is_vip'], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
                    if ($isVip === null) {
                        return;
                    }

                    $query->where('is_vip', $isVip);
                }
            )
            ->when(
                $filters['vip_tier_id'] ?? null,
                fn (Builder $query, $vipTierId) => $query->where('vip_tier_id', (int) $vipTierId)
            );
    }
}
