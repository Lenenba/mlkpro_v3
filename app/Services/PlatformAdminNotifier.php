<?php

namespace App\Services;

use App\Models\PlatformNotification;
use App\Models\PlatformNotificationSetting;
use App\Models\Role;
use App\Models\User;
use App\Models\Billing\PaddleSubscription;
use App\Notifications\ActionEmailNotification;
use App\Notifications\PlatformAdminDigestNotification;
use App\Support\NotificationDispatcher;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Laravel\Paddle\Subscription;
use Laravel\Paddle\Transaction;

class PlatformAdminNotifier
{
    private const DEFAULT_CHANNELS = ['email'];
    private const DEFAULT_CATEGORIES = [
        'new_account',
        'onboarding_completed',
        'subscription_started',
        'plan_changed',
        'subscription_paused',
        'subscription_resumed',
        'subscription_canceled',
        'payment_succeeded',
        'payment_failed',
        'churn_risk',
    ];

    public function notify(string $category, string $title, array $payload = []): void
    {
        $recipients = $this->resolveRecipients();
        foreach ($recipients as $recipient) {
            $this->notifyUser($recipient, $category, $title, $payload);
        }
    }

    public function sendDigest(string $frequency): int
    {
        $frequency = strtolower(trim($frequency));
        if (!in_array($frequency, ['daily', 'weekly'], true)) {
            $frequency = 'daily';
        }

        $recipients = $this->resolveRecipients();
        if ($recipients->isEmpty()) {
            return 0;
        }

        $sent = 0;
        foreach ($recipients as $recipient) {
            $settings = $this->resolveSettings($recipient);
            if (!$this->isChannelEnabled($settings, 'email')) {
                continue;
            }
            if (($settings->digest_frequency ?? 'daily') !== $frequency) {
                continue;
            }

            $items = PlatformNotification::query()
                ->where('user_id', $recipient->id)
                ->whereNull('sent_at')
                ->where('digest_frequency', $frequency)
                ->orderBy('created_at')
                ->get();

            if ($items->isEmpty()) {
                continue;
            }

            $dispatchOk = NotificationDispatcher::send($recipient, new PlatformAdminDigestNotification(
                $frequency,
                $items->map(fn(PlatformNotification $item) => [
                    'title' => $item->title,
                    'category' => $item->category,
                    'intro' => $item->intro,
                    'created_at' => $item->created_at,
                ])->all()
            ), [
                'user_id' => $recipient->id,
            ]);

            if (!$dispatchOk) {
                continue;
            }

            PlatformNotification::query()
                ->whereIn('id', $items->pluck('id'))
                ->update(['sent_at' => now()]);

            $sent += $items->count();
        }

        return $sent;
    }

    public function scanTrialEnding(): int
    {
        $recipients = $this->resolveRecipients();
        if ($recipients->isEmpty()) {
            return 0;
        }

        $total = 0;
        foreach ($recipients as $recipient) {
            $settings = $this->resolveSettings($recipient);
            $thresholdDays = (int) data_get($settings->rules, 'churn_risk', 5);
            if ($thresholdDays <= 0) {
                continue;
            }

            $windowEnd = now()->addDays($thresholdDays)->endOfDay();
            $subscriptions = PaddleSubscription::query()
                ->where('status', Subscription::STATUS_TRIALING)
                ->whereNotNull('trial_ends_at')
                ->whereBetween('trial_ends_at', [now(), $windowEnd])
                ->get();

            foreach ($subscriptions as $subscription) {
                $billable = $subscription->billable;
                if (!$billable instanceof User) {
                    continue;
                }

                $trialEndsAt = $subscription->trial_ends_at;
                if (!$trialEndsAt instanceof Carbon) {
                    continue;
                }

                $daysLeft = now()->diffInDays($trialEndsAt, false);
                if ($daysLeft < 0 || $daysLeft > $thresholdDays) {
                    continue;
                }

                $reference = 'trial:' . $subscription->paddle_id . ':' . $trialEndsAt->toDateString();

                $this->notifyUser($recipient, 'churn_risk', 'Trial ending soon', [
                    'intro' => ($billable->company_name ?: $billable->email) . ' trial ends soon.',
                    'details' => [
                        ['label' => 'Company', 'value' => $billable->company_name ?: 'Unknown'],
                        ['label' => 'Owner', 'value' => $billable->email],
                        ['label' => 'Trial ends', 'value' => $trialEndsAt->toDateString()],
                        ['label' => 'Days left', 'value' => $daysLeft],
                    ],
                    'actionUrl' => route('superadmin.tenants.show', $billable->id),
                    'actionLabel' => 'View tenant',
                    'reference' => $reference,
                    'severity' => 'warning',
                ]);
                $total += 1;
            }
        }

        return $total;
    }

    public function formatMoney(?string $amount, ?string $currency): string
    {
        $numeric = is_numeric($amount) ? (float) $amount : 0.0;
        $formatted = number_format($numeric, 2);
        $code = strtoupper($currency ?: config('cashier.currency', 'USD'));

        return $formatted . ' ' . $code;
    }

    public function resolvePlanName(?string $priceId): string
    {
        if (!$priceId) {
            return 'Unknown';
        }

        foreach (config('billing.plans', []) as $plan) {
            if (!empty($plan['price_id']) && $plan['price_id'] === $priceId) {
                return $plan['name'] ?? $priceId;
            }
        }

        return $priceId;
    }

    public function resolveTransactionStatusLabel(string $status): string
    {
        return match ($status) {
            Transaction::STATUS_PAST_DUE => 'past_due',
            Transaction::STATUS_CANCELED => 'canceled',
            Transaction::STATUS_PAID => 'paid',
            Transaction::STATUS_COMPLETED => 'completed',
            default => $status,
        };
    }

    public function resolveSubscriptionStatusLabel(string $status): string
    {
        return match ($status) {
            Subscription::STATUS_PAST_DUE => 'past_due',
            Subscription::STATUS_TRIALING => 'trialing',
            Subscription::STATUS_PAUSED => 'paused',
            Subscription::STATUS_CANCELED => 'canceled',
            default => $status,
        };
    }

    private function notifyUser(User $recipient, string $category, string $title, array $payload = []): void
    {
        $settings = $this->resolveSettings($recipient);
        if (!$this->isChannelEnabled($settings, 'email')) {
            return;
        }

        $categories = $settings->categories ?? [];
        if ($categories && !in_array($category, $categories, true)) {
            return;
        }

        $reference = $payload['reference'] ?? null;
        if ($reference && $this->alreadyNotified($recipient->id, $reference)) {
            return;
        }

        $digestFrequency = $settings->digest_frequency ?: 'daily';

        $notification = PlatformNotification::query()->create([
            'user_id' => $recipient->id,
            'category' => $category,
            'title' => $title,
            'intro' => $payload['intro'] ?? null,
            'details' => $payload['details'] ?? [],
            'action_url' => $payload['actionUrl'] ?? null,
            'action_label' => $payload['actionLabel'] ?? null,
            'severity' => $payload['severity'] ?? 'info',
            'digest_frequency' => $digestFrequency,
            'reference' => $reference,
        ]);

        if ($digestFrequency === 'immediate') {
            $dispatchOk = NotificationDispatcher::send($recipient, new ActionEmailNotification(
                $title,
                $payload['intro'] ?? null,
                $payload['details'] ?? [],
                $payload['actionUrl'] ?? null,
                $payload['actionLabel'] ?? null,
                $payload['subject'] ?? null,
                $payload['note'] ?? null
            ), [
                'user_id' => $recipient->id,
            ]);

            if ($dispatchOk) {
                $notification->update(['sent_at' => now()]);
            }
        }
    }

    private function resolveRecipients(): Collection
    {
        $roleId = Role::query()->where('name', 'superadmin')->value('id');
        if (!$roleId) {
            return collect();
        }

        return User::query()
            ->where('role_id', $roleId)
            ->get();
    }

    private function resolveSettings(User $user): PlatformNotificationSetting
    {
        return PlatformNotificationSetting::query()
            ->firstOrCreate(
                ['user_id' => $user->id],
                [
                    'channels' => self::DEFAULT_CHANNELS,
                    'categories' => self::DEFAULT_CATEGORIES,
                    'rules' => [
                        'error_spike' => 10,
                        'payment_failed' => 3,
                        'churn_risk' => 5,
                    ],
                    'digest_frequency' => 'daily',
                ]
            );
    }

    private function isChannelEnabled(PlatformNotificationSetting $settings, string $channel): bool
    {
        $channels = $settings->channels ?? [];
        return in_array($channel, $channels, true);
    }

    private function alreadyNotified(int $userId, string $reference): bool
    {
        return PlatformNotification::query()
            ->where('user_id', $userId)
            ->where('reference', $reference)
            ->where('created_at', '>=', now()->subDays(7))
            ->exists();
    }
}
