<?php

namespace App\Modules\AiAssistant\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AiMessage extends Model
{
    use HasFactory;

    public const SENDER_USER = 'user';

    public const SENDER_ASSISTANT = 'assistant';

    public const SENDER_SYSTEM = 'system';

    public const SENDER_HUMAN = 'human';

    protected $fillable = [
        'conversation_id',
        'sender_type',
        'content',
        'payload',
    ];

    protected $casts = [
        'payload' => 'array',
    ];

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(AiConversation::class, 'conversation_id');
    }

    public function scopeForConversation(Builder $query, int $conversationId): Builder
    {
        return $query->where('conversation_id', $conversationId);
    }

    public function scopeForTenant(Builder $query, int $tenantId): Builder
    {
        return $query->whereHas('conversation', function (Builder $conversationQuery) use ($tenantId): void {
            $conversationQuery->where('tenant_id', $tenantId);
        });
    }

    /**
     * @return array<int, string>
     */
    public static function senderTypes(): array
    {
        return [
            self::SENDER_USER,
            self::SENDER_ASSISTANT,
            self::SENDER_SYSTEM,
            self::SENDER_HUMAN,
        ];
    }
}
