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

    public const PAST_RECONCILIATION_MODE_SIGNAL_ONLY = 'signal_only';

    public const ACCOUNT_DEFAULT_MARKER = 1;

    protected $fillable = [
        'account_id',
        'team_member_id',
        'account_default_marker',
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
        'kiosk_image_path',
        'deposit_required',
        'deposit_amount',
        'currency_code',
        'no_show_fee_enabled',
        'no_show_fee_amount',
        'past_reservation_reconciliation_enabled',
        'past_reservation_reconciliation_mode',
        'past_reservation_grace_minutes',
        'past_reservation_max_catchup_days',
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
        'kiosk_image_path' => 'string',
        'deposit_required' => 'boolean',
        'deposit_amount' => 'decimal:2',
        'currency_code' => 'string',
        'no_show_fee_enabled' => 'boolean',
        'no_show_fee_amount' => 'decimal:2',
        'past_reservation_reconciliation_enabled' => 'boolean',
        'past_reservation_reconciliation_mode' => 'string',
        'past_reservation_grace_minutes' => 'integer',
        'past_reservation_max_catchup_days' => 'integer',
        'account_default_marker' => 'integer',
    ];

    protected static function booted(): void
    {
        static::saving(function (self $setting): void {
            $setting->account_default_marker = $setting->team_member_id === null
                ? self::ACCOUNT_DEFAULT_MARKER
                : null;
        });

        static::creating(function (self $setting): void {
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

    public function scopeAccountDefault(Builder $query): Builder
    {
        return $query
            ->whereNull('team_member_id')
            ->where('account_default_marker', self::ACCOUNT_DEFAULT_MARKER);
    }
}
