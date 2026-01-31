<?php

namespace App\Services;

use App\Models\AssistantCreditTransaction;
use App\Models\User;
use Illuminate\Support\Carbon;

class AiImageUsageService
{
    public const CONTEXT_STORE = 'store';
    public const CONTEXT_PRODUCT = 'product';
    public const FREE_DAILY_LIMIT = 1;

    public function contexts(): array
    {
        return [
            self::CONTEXT_STORE,
            self::CONTEXT_PRODUCT,
        ];
    }

    public function resolveOwner(User $user): User
    {
        $ownerId = $user->accountOwnerId();
        if ($ownerId === $user->id) {
            return $user;
        }

        return User::query()->find($ownerId) ?? $user;
    }

    public function sourceForContext(string $context): string
    {
        return 'ai_image_' . $context;
    }

    public function freeUsed(User $user, string $context, ?Carbon $date = null): int
    {
        $owner = $this->resolveOwner($user);
        $date = $date ?: now();

        return AssistantCreditTransaction::query()
            ->where('user_id', $owner->id)
            ->where('type', 'free')
            ->where('source', $this->sourceForContext($context))
            ->whereDate('created_at', $date->toDateString())
            ->count();
    }

    public function remaining(User $user, string $context, int $limit = self::FREE_DAILY_LIMIT): int
    {
        $used = $this->freeUsed($user, $context);

        return max(0, $limit - $used);
    }

    public function recordFree(User $user, string $context, int $credits = 1): void
    {
        $owner = $this->resolveOwner($user);

        AssistantCreditTransaction::create([
            'user_id' => $owner->id,
            'type' => 'free',
            'credits' => max(1, $credits),
            'source' => $this->sourceForContext($context),
            'meta' => [
                'context' => $context,
                'mode' => 'free',
            ],
        ]);
    }

    public function creditBalance(User $user): int
    {
        $owner = $this->resolveOwner($user);

        return (int) ($owner->assistant_credit_balance ?? 0);
    }

    public function consumeCredit(User $user, string $context, int $credits = 1): bool
    {
        return app(AssistantCreditService::class)->consume(
            $user,
            $credits,
            $this->sourceForContext($context),
            [
                'context' => $context,
                'mode' => 'credit',
            ]
        );
    }
}
