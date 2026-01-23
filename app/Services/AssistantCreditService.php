<?php

namespace App\Services;

use App\Models\AssistantCreditTransaction;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class AssistantCreditService
{
    public function balance(User $user): int
    {
        $owner = $this->resolveOwner($user);

        return (int) ($owner->assistant_credit_balance ?? 0);
    }

    public function consume(User $user, int $credits = 1): bool
    {
        $credits = max(1, $credits);
        $owner = $this->resolveOwner($user);

        return DB::transaction(function () use ($owner, $credits) {
            $updated = User::query()
                ->whereKey($owner->id)
                ->where('assistant_credit_balance', '>=', $credits)
                ->decrement('assistant_credit_balance', $credits);

            if (!$updated) {
                return false;
            }

            AssistantCreditTransaction::create([
                'user_id' => $owner->id,
                'type' => 'consume',
                'credits' => $credits,
                'source' => 'assistant',
            ]);

            return true;
        });
    }

    public function refund(User $user, int $credits, array $meta = []): void
    {
        $this->grant($user, $credits, 'refund', $meta);
    }

    public function grant(User $user, int $credits, string $type = 'purchase', array $meta = []): void
    {
        $credits = max(1, $credits);
        $owner = $this->resolveOwner($user);

        DB::transaction(function () use ($owner, $credits, $type, $meta) {
            User::query()
                ->whereKey($owner->id)
                ->increment('assistant_credit_balance', $credits);

            AssistantCreditTransaction::create([
                'user_id' => $owner->id,
                'type' => $type,
                'credits' => $credits,
                'source' => $meta['source'] ?? null,
                'stripe_session_id' => $meta['stripe_session_id'] ?? null,
                'stripe_payment_intent_id' => $meta['stripe_payment_intent_id'] ?? null,
                'meta' => $meta['meta'] ?? null,
            ]);
        });
    }

    public function grantFromStripeSession(array $session, int $packSize): bool
    {
        $metadata = $session['metadata'] ?? [];
        if (($metadata['purpose'] ?? null) !== 'assistant_credits') {
            return false;
        }

        $sessionId = $session['id'] ?? null;
        if (!$sessionId) {
            return false;
        }

        $user = $this->resolveUserFromSession($session, $metadata);
        if (!$user) {
            return false;
        }
        $owner = $this->resolveOwner($user);

        $packSizeFromMeta = (int) ($metadata['pack_size'] ?? 0);
        if ($packSizeFromMeta > 0) {
            $packSize = $packSizeFromMeta;
        }

        $packCount = (int) ($metadata['pack_count'] ?? 1);
        $packCount = $packCount > 0 ? $packCount : 1;
        $credits = max(1, $packSize) * $packCount;

        DB::transaction(function () use ($owner, $credits, $sessionId, $session, $packSize, $packCount) {
            $transaction = AssistantCreditTransaction::firstOrCreate(
                ['stripe_session_id' => $sessionId],
                [
                    'user_id' => $owner->id,
                    'type' => 'purchase',
                    'credits' => $credits,
                    'source' => 'stripe',
                    'stripe_payment_intent_id' => $session['payment_intent'] ?? null,
                    'meta' => [
                        'pack_size' => $packSize,
                        'pack_count' => $packCount,
                    ],
                ]
            );

            if ($transaction->wasRecentlyCreated) {
                User::query()->whereKey($owner->id)->increment('assistant_credit_balance', $credits);
            }
        });

        return true;
    }

    private function resolveUserFromSession(array $session, array $metadata): ?User
    {
        $userId = $metadata['user_id'] ?? $session['client_reference_id'] ?? null;
        if ($userId) {
            $user = User::query()->find($userId);
            if ($user) {
                return $user;
            }
        }

        $customerId = $session['customer'] ?? null;
        if ($customerId) {
            return User::query()->where('stripe_customer_id', $customerId)->first();
        }

        return null;
    }

    private function resolveOwner(User $user): User
    {
        $ownerId = $user->accountOwnerId();
        if ($ownerId === $user->id) {
            return $user;
        }

        return User::query()->find($ownerId) ?? $user;
    }
}
