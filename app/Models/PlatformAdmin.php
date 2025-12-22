<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PlatformAdmin extends Model
{
    /** @use HasFactory<\Database\Factories\PlatformAdminFactory> */
    use HasFactory;

    protected $fillable = [
        'user_id',
        'role',
        'permissions',
        'is_active',
        'require_2fa',
    ];

    protected $casts = [
        'permissions' => 'array',
        'is_active' => 'boolean',
        'require_2fa' => 'boolean',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function hasPermission(string $permission): bool
    {
        $permissions = $this->permissions ?? [];
        if (!is_array($permissions)) {
            return false;
        }

        return in_array($permission, $permissions, true);
    }
}
