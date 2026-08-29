<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SocialBufferConnection extends Model
{
    /** @use HasFactory<\Database\Factories\SocialBufferConnectionFactory> */
    use HasFactory;

    protected $fillable = [
        'user_id',
        'buffer_account_id',
        'buffer_account_name',
        'access_token',
        'refresh_token',
        'token_type',
        'scopes',
        'token_expires_at',
        'connected_at',
        'last_refreshed_at',
        'oauth_state',
        'oauth_code_verifier',
        'oauth_state_expires_at',
        'last_error',
    ];

    protected $hidden = [
        'access_token',
        'refresh_token',
        'oauth_state',
        'oauth_code_verifier',
    ];

    protected function casts(): array
    {
        return [
            'access_token' => 'encrypted',
            'refresh_token' => 'encrypted',
            'scopes' => 'array',
            'oauth_code_verifier' => 'encrypted',
            'token_expires_at' => 'datetime',
            'connected_at' => 'datetime',
            'last_refreshed_at' => 'datetime',
            'oauth_state_expires_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isConnected(): bool
    {
        return $this->connected_at !== null
            && trim((string) $this->access_token) !== '';
    }
}
