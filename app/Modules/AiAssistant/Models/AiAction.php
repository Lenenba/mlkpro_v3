<?php

namespace App\Modules\AiAssistant\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AiAction extends Model
{
    use HasFactory;

    public const TYPE_CREATE_PROSPECT = 'create_prospect';

    public const TYPE_CREATE_CLIENT = 'create_client';

    public const TYPE_CREATE_RESERVATION = 'create_reservation';

    public const TYPE_RESCHEDULE_RESERVATION = 'reschedule_reservation';

    public const TYPE_CREATE_TASK = 'create_task';

    public const TYPE_SEND_MESSAGE = 'send_message';

    public const TYPE_REQUEST_HUMAN_REVIEW = 'request_human_review';

    public const STATUS_PENDING = 'pending';

    public const STATUS_APPROVED = 'approved';

    public const STATUS_EXECUTED = 'executed';

    public const STATUS_FAILED = 'failed';

    public const STATUS_REJECTED = 'rejected';

    protected $fillable = [
        'tenant_id',
        'conversation_id',
        'action_type',
        'status',
        'input_payload',
        'output_payload',
        'error_message',
        'executed_at',
    ];

    protected $casts = [
        'input_payload' => 'array',
        'output_payload' => 'array',
        'executed_at' => 'datetime',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(User::class, 'tenant_id');
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(AiConversation::class, 'conversation_id');
    }

    public function scopeForTenant(Builder $query, int $tenantId): Builder
    {
        return $query->where('tenant_id', $tenantId);
    }

    public function isPending(): bool
    {
        return $this->status === self::STATUS_PENDING;
    }

    /**
     * @return array<int, string>
     */
    public static function actionTypes(): array
    {
        return [
            self::TYPE_CREATE_PROSPECT,
            self::TYPE_CREATE_CLIENT,
            self::TYPE_CREATE_RESERVATION,
            self::TYPE_RESCHEDULE_RESERVATION,
            self::TYPE_CREATE_TASK,
            self::TYPE_SEND_MESSAGE,
            self::TYPE_REQUEST_HUMAN_REVIEW,
        ];
    }

    /**
     * @return array<int, string>
     */
    public static function statuses(): array
    {
        return [
            self::STATUS_PENDING,
            self::STATUS_APPROVED,
            self::STATUS_EXECUTED,
            self::STATUS_FAILED,
            self::STATUS_REJECTED,
        ];
    }
}
