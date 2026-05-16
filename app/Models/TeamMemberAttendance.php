<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TeamMemberAttendance extends Model
{
    use HasFactory;

    public const STATUS_AVAILABLE = 'available';

    public const STATUS_BUSY = 'busy';

    public const STATUS_OFFLINE = 'offline';

    public const STATUS_BREAK = 'break';

    public const CURRENT_STATUSES = [
        self::STATUS_AVAILABLE,
        self::STATUS_BUSY,
        self::STATUS_OFFLINE,
        self::STATUS_BREAK,
    ];

    protected $fillable = [
        'account_id',
        'user_id',
        'team_member_id',
        'clock_in_at',
        'clock_out_at',
        'method',
        'clock_out_method',
        'current_status',
    ];

    protected $casts = [
        'clock_in_at' => 'datetime',
        'clock_out_at' => 'datetime',
    ];

    public function account(): BelongsTo
    {
        return $this->belongsTo(User::class, 'account_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function teamMember(): BelongsTo
    {
        return $this->belongsTo(TeamMember::class, 'team_member_id');
    }
}
