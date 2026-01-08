<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Notifications\Notifiable;
use App\Traits\GeneratesSequentialNumber;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Support\Facades\Storage;

class Customer extends Model
{
    use HasFactory, GeneratesSequentialNumber, Notifiable;

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
        'email',
        'phone',
        'description',
        'tags',
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
        'portal_access' => 'boolean',
        'auto_accept_quotes' => 'boolean',
        'auto_validate_jobs' => 'boolean',
        'auto_validate_tasks' => 'boolean',
        'auto_validate_invoices' => 'boolean',
        'tags' => 'array',
        'billing_delay_days' => 'integer',
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
        });
    }
    /**
     * Get the user that owns the customer.
     */
    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function portalUser()
    {
        return $this->belongsTo(User::class, 'portal_user_id');
    }

    public function properties()
    {
        return $this->hasMany(Property::class)
            ->orderByDesc('is_default')
            ->orderBy('id');
    }

    public function defaultProperty()
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

    public function invoices()
    {
        return $this->hasMany(Invoice::class);
    }

    public function sales(): HasMany
    {
        return $this->hasMany(Sale::class);
    }

    public function requests(): HasMany
    {
        return $this->hasMany(Request::class)->orderByDesc('created_at');
    }

    public function quotes()
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
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @param int $userId
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeByUser($query, int $userId)
    {
        return $query->where('user_id', $userId);
    }


    /**
     * Scope a query to order products by the most recent.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeMostRecent(Builder $query): Builder
    {
        return $query->orderByDesc('created_at');
    }

    /**
     * Get the total number of works for the customer.
     *
     * @return int
     */
    public function getTotalWorks(): int
    {
        return $this->works()->count();
    }

    public function getLogoUrlAttribute(): ?string
    {
        $path = $this->logo ?: 'customers/customer.png';

        if (str_starts_with($path, 'http://') || str_starts_with($path, 'https://')) {
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

        return Storage::url($path);
    }

    /**
     * Scope a query to filter products based on given criteria.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @param array $filters
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeFilter(Builder $query, array $filters): Builder
    {
        return $query
            ->when(
                $filters['name'] ?? null,
                function (Builder $query, $name) {
                    $query->where(function (Builder $query) use ($name) {
                        $query->where('company_name', 'like', '%' . $name . '%')
                            ->orWhere('first_name', 'like', '%' . $name . '%')
                            ->orWhere('last_name', 'like', '%' . $name . '%')
                            ->orWhere('email', 'like', '%' . $name . '%')
                            ->orWhere('phone', 'like', '%' . $name . '%');
                    });
                }
            )
            ->when(
                $filters['city'] ?? null,
                fn(Builder $query, $city) => $query->whereHas('properties', function (Builder $sub) use ($city) {
                    $sub->where('city', 'like', '%' . $city . '%');
                })
            )
            ->when(
                $filters['country'] ?? null,
                fn(Builder $query, $country) => $query->whereHas('properties', function (Builder $sub) use ($country) {
                    $sub->where('country', 'like', '%' . $country . '%');
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
                $filters['created_from'] ?? null,
                fn(Builder $query, $createdFrom) => $query->whereDate('created_at', '>=', $createdFrom)
            )
            ->when(
                $filters['created_to'] ?? null,
                fn(Builder $query, $createdTo) => $query->whereDate('created_at', '<=', $createdTo)
            );
    }
}
