<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Models\Product;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable
{
    /** @use HasFactory<\Database\Factories\UserFactory> */
    use HasFactory, Notifiable;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
        'role_id',
        'profile_picture',
        'phone_number',
        'company_name',
        'company_logo',
        'company_description',
        'company_country',
        'company_province',
        'company_city',
        'company_type',
        'onboarding_completed_at',
        'payment_methods',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
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
        ];
    }

    public function products()
    {
        return $this->hasMany(Product::class);
    }

    public function customers()
    {
        return $this->hasMany(Customer::class);
    }

    public function ownedTeamMembers(): HasMany
    {
        return $this->hasMany(TeamMember::class, 'account_id');
    }

    public function teamMembership(): HasOne
    {
        return $this->hasOne(TeamMember::class, 'user_id')->where('is_active', true);
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
        return $this->accountOwnerId() === $this->id;
    }
}
