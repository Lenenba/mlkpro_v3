<?php

namespace App\Models;

use App\Enums\CurrencyCode;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ReservationSetting extends Model
{
    use HasFactory;

    protected $fillable = [
        'account_id',
        'team_member_id',
        'business_preset',
        'buffer_minutes',
        'slot_interval_minutes',
        'min_notice_minutes',
        'max_advance_days',
        'cancellation_cutoff_hours',
        'allow_client_cancel',
        'allow_client_reschedule',
        'late_release_minutes',
        'waitlist_enabled',
        'queue_mode_enabled',
        'queue_assignment_mode',
        'queue_dispatch_mode',
        'queue_grace_minutes',
        'queue_pre_call_threshold',
        'queue_no_show_on_grace_expiry',
        'deposit_required',
        'deposit_amount',
        'currency_code',
        'no_show_fee_enabled',
        'no_show_fee_amount',
    ];

    protected $casts = [
        'business_preset' => 'string',
        'buffer_minutes' => 'integer',
        'slot_interval_minutes' => 'integer',
        'min_notice_minutes' => 'integer',
        'max_advance_days' => 'integer',
        'cancellation_cutoff_hours' => 'integer',
        'allow_client_cancel' => 'boolean',
        'allow_client_reschedule' => 'boolean',
        'late_release_minutes' => 'integer',
        'waitlist_enabled' => 'boolean',
        'queue_mode_enabled' => 'boolean',
        'queue_assignment_mode' => 'string',
        'queue_dispatch_mode' => 'string',
        'queue_grace_minutes' => 'integer',
        'queue_pre_call_threshold' => 'integer',
        'queue_no_show_on_grace_expiry' => 'boolean',
        'deposit_required' => 'boolean',
        'deposit_amount' => 'decimal:2',
        'currency_code' => 'string',
        'no_show_fee_enabled' => 'boolean',
        'no_show_fee_amount' => 'decimal:2',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $setting) {
            if ($setting->currency_code) {
                return;
            }

            $setting->currency_code = $setting->account_id
                ? (User::query()->whereKey($setting->account_id)->value('currency_code') ?: CurrencyCode::default()->value)
                : CurrencyCode::default()->value;
        });
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(User::class, 'account_id');
    }

    public function teamMember(): BelongsTo
    {
        return $this->belongsTo(TeamMember::class);
    }

    public function scopeForAccount(Builder $query, int $accountId): Builder
    {
        return $query->where('account_id', $accountId);
    }
}
