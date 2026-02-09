<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Models\Product;
use App\Models\Role;
use App\Models\PlatformAdmin;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use Laravel\Paddle\Billable;
use Illuminate\Support\Facades\Storage;
use App\Services\CompanyFeatureService;

class User extends Authenticatable
{
    /** @use HasFactory<\Database\Factories\UserFactory> */
    use HasApiTokens, HasFactory, Notifiable, Billable;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'locale',
        'password',
        'must_change_password',
        'role_id',
        'profile_picture',
        'phone_number',
        'company_name',
        'company_slug',
        'company_logo',
        'company_description',
        'company_country',
        'company_province',
        'company_city',
        'company_timezone',
        'company_type',
        'company_sector',
        'company_team_size',
        'onboarding_completed_at',
        'payment_methods',
        'company_features',
        'company_limits',
        'assistant_credit_balance',
        'company_supplier_preferences',
        'notification_settings',
        'company_fulfillment',
        'company_notification_settings',
        'company_store_settings',
        'company_time_settings',
        'is_suspended',
        'suspended_at',
        'suspension_reason',
        'stripe_customer_id',
        'stripe_connect_account_id',
        'stripe_connect_charges_enabled',
        'stripe_connect_payouts_enabled',
        'stripe_connect_details_submitted',
        'stripe_connect_requirements',
        'stripe_connect_onboarded_at',
        'trial_ends_at',
        'is_demo',
        'demo_type',
        'is_demo_user',
        'demo_role',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
        'two_factor_code',
        'two_factor_secret',
    ];

    protected $appends = [
        'profile_picture_url',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'onboarding_completed_at' => 'datetime',
            'payment_methods' => 'array',
            'trial_ends_at' => 'datetime',
            'must_change_password' => 'boolean',
            'two_factor_enabled' => 'boolean',
            'two_factor_expires_at' => 'datetime',
            'two_factor_last_sent_at' => 'datetime',
            'two_factor_secret' => 'encrypted',
            'two_factor_exempt' => 'boolean',
            'company_features' => 'array',
            'company_limits' => 'array',
            'assistant_credit_balance' => 'integer',
            'company_team_size' => 'integer',
            'company_supplier_preferences' => 'array',
            'notification_settings' => 'array',
            'company_fulfillment' => 'array',
            'company_notification_settings' => 'array',
            'company_store_settings' => 'array',
            'company_time_settings' => 'array',
            'is_suspended' => 'boolean',
            'suspended_at' => 'datetime',
            'is_demo' => 'boolean',
            'is_demo_user' => 'boolean',
            'stripe_connect_charges_enabled' => 'boolean',
            'stripe_connect_payouts_enabled' => 'boolean',
            'stripe_connect_details_submitted' => 'boolean',
            'stripe_connect_requirements' => 'array',
            'stripe_connect_onboarded_at' => 'datetime',
        ];
    }

    public function products()
    {
        return $this->hasMany(Product::class);
    }

    public function role(): BelongsTo
    {
        return $this->belongsTo(Role::class, 'role_id');
    }

    public function customers()
    {
        return $this->hasMany(Customer::class);
    }

    public function customerProfile(): HasOne
    {
        return $this->hasOne(Customer::class, 'portal_user_id');
    }

    public function ownedTeamMembers(): HasMany
    {
        return $this->hasMany(TeamMember::class, 'account_id');
    }

    public function pushTokens(): HasMany
    {
        return $this->hasMany(UserPushToken::class);
    }

    public function platformAdmin(): HasOne
    {
        return $this->hasOne(PlatformAdmin::class);
    }

    public function teamMembership(): HasOne
    {
        return $this->hasOne(TeamMember::class, 'user_id')->where('is_active', true);
    }

    public function hasRole(string $name): bool
    {
        $roleName = $this->relationLoaded('role')
            ? $this->role?->name
            : Role::query()->whereKey($this->role_id)->value('name');

        return $roleName === $name;
    }

    public function isOwner(): bool
    {
        return $this->hasRole('owner');
    }

    public function isEmployee(): bool
    {
        return $this->hasRole('employee');
    }

    public function isClient(): bool
    {
        return $this->hasRole('client');
    }

    public function isSuperadmin(): bool
    {
        return $this->hasRole('superadmin');
    }

    public function isPlatformAdmin(): bool
    {
        return $this->hasRole('admin');
    }

    public function hasPlatformPermission(string $permission): bool
    {
        if ($this->isSuperadmin()) {
            return true;
        }

        if (!$this->isPlatformAdmin()) {
            return false;
        }

        $adminProfile = $this->relationLoaded('platformAdmin')
            ? $this->platformAdmin
            : $this->platformAdmin()->first();

        if (!$adminProfile || !$adminProfile->is_active) {
            return false;
        }

        return $adminProfile->hasPermission($permission);
    }

    public function hasCompanyFeature(string $feature): bool
    {
        return app(CompanyFeatureService::class)->hasFeature($this, $feature);
    }

    public function isSuspended(): bool
    {
        return (bool) $this->is_suspended;
    }

    public function accountOwnerId(): int
    {
        $membership = $this->relationLoaded('teamMembership')
            ? $this->teamMembership
            : $this->teamMembership()->first();

        return $membership?->account_id ?? $this->id;
    }

    public function isAccountOwner(): bool
    {
        if ($this->isSuperadmin()) {
            return true;
        }

        if ($this->isOwner()) {
            return true;
        }

        return false;
    }

    public function requiresTwoFactor(): bool
    {
        if ($this->isSuperadmin()) {
            return false;
        }

        if ($this->two_factor_exempt) {
            return false;
        }

        if ($this->isAccountOwner() && !$this->onboarding_completed_at) {
            return false;
        }

        if ($this->isPlatformAdmin()) {
            $platformAdmin = $this->relationLoaded('platformAdmin')
                ? $this->platformAdmin
                : $this->platformAdmin()->first();

            return (bool) ($platformAdmin?->require_2fa);
        }

        return $this->isAccountOwner();
    }

    public function twoFactorMethod(): string
    {
        $method = $this->two_factor_method ?: 'email';
        return in_array($method, ['email', 'app'], true) ? $method : 'email';
    }

    public function getCompanyLogoUrlAttribute(): ?string
    {
        $path = $this->company_logo ?: 'customers/customer.png';

        if (str_starts_with($path, 'http://') || str_starts_with($path, 'https://')) {
            return $path;
        }

        if (str_starts_with($path, '/')) {
            return $path;
        }

        return Storage::disk('public')->url($path);
    }

    public function getProfilePictureUrlAttribute(): ?string
    {
        $path = $this->profile_picture;
        if (!$path) {
            return null;
        }

        if (str_starts_with($path, 'http://') || str_starts_with($path, 'https://')) {
            return $path;
        }

        if (str_starts_with($path, '/')) {
            return $path;
        }

        return Storage::disk('public')->url($path);
    }
}
