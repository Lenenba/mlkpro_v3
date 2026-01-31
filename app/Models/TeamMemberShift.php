<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class TeamMemberShift extends Model
{
    use HasFactory;

    protected $fillable = [
        'account_id',
        'team_member_id',
        'created_by_user_id',
        'title',
        'notes',
        'shift_date',
        'start_time',
        'end_time',
        'recurrence_group_id',
    ];

    protected $casts = [
        'shift_date' => 'date',
    ];

    public function account(): BelongsTo
    {
        return $this->belongsTo(User::class, 'account_id');
    }

    public function teamMember(): BelongsTo
    {
        return $this->belongsTo(TeamMember::class, 'team_member_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }
}
