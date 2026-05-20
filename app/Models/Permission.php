<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Permission extends Model
{
    use HasFactory;

    protected $fillable = [
        'group',
        'name',
        'slug',
        'description',
    ];

    public function companyRoles(): BelongsToMany
    {
        return $this->belongsToMany(CompanyRole::class, 'company_role_permission')
            ->withTimestamps();
    }
}
