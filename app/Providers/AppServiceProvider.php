<?php

namespace App\Providers;

use App\Models\Billing\PaddleCustomer;
use App\Models\Billing\PaddleSubscription as PaddleSubscriptionModel;
use App\Models\Billing\PaddleSubscriptionItem;
use App\Models\Billing\PaddleTransaction as PaddleTransactionModel;
use App\Models\User;
use App\Listeners\SendDatabasePushNotifications;
use App\Listeners\SendEmailMirrorNotifications;
use App\Services\PlatformAdminNotifier;
use Illuminate\Auth\Events\Registered;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Notifications\Events\NotificationFailed;
use Illuminate\Notifications\Events\NotificationSent;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Vite;
use Illuminate\Support\ServiceProvider;
use Laravel\Paddle\Cashier;
use Laravel\Paddle\Events\SubscriptionCanceled;
use Laravel\Paddle\Events\SubscriptionCreated;
use Laravel\Paddle\Events\SubscriptionPaused;
use Laravel\Paddle\Events\SubscriptionUpdated;
use Laravel\Paddle\Events\TransactionCompleted;
use Laravel\Paddle\Events\TransactionUpdated;
use Laravel\Paddle\Subscription as PaddleSubscription;
use Laravel\Paddle\Transaction as PaddleTransaction;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        RateLimiter::for('api', function (Request $request) {
            $user = $request->user();
            $key = $user ? 'user:' . $user->id : 'ip:' . $request->ip();
            $limit = (int) config('services.rate_limits.api_per_user', 120);

            return Limit::perMinute(max(1, $limit))->by($key);
        });

        RateLimiter::for('public-signed', function (Request $request) {
            $limit = (int) config('services.rate_limits.public_signed_per_minute', 30);
            $key = 'public-signed:' . $request->ip();

            return Limit::perMinute(max(1, $limit))->by($key);
        });

        RateLimiter::for('register', function (Request $request) {
            $limit = (int) config('services.rate_limits.register_per_minute', 10);
            $email = strtolower((string) $request->input('email'));
            $key = $email !== '' ? 'register:email:' . sha1($email) : 'register:ip:' . $request->ip();

            return Limit::perMinute(max(1, $limit))->by($key);
        });

        Cashier::useCustomerModel(PaddleCustomer::class);
        Cashier::useSubscriptionModel(PaddleSubscriptionModel::class);
        Cashier::useSubscriptionItemModel(PaddleSubscriptionItem::class);
        Cashier::useTransactionModel(PaddleTransactionModel::class);

        Vite::prefetch(concurrency: 3);

        Event::listen(NotificationSent::class, SendEmailMirrorNotifications::class);
        Event::listen(NotificationSent::class, SendDatabasePushNotifications::class);
        Event::listen(NotificationFailed::class, SendEmailMirrorNotifications::class);

        Event::listen(Registered::class, function (Registered $event): void {
            $user = $event->user;
            if (!$user instanceof User) {
                return;
            }

            $notifier = app(PlatformAdminNotifier::class);
            $companyLabel = $user->company_name ?: $user->email;

            $notifier->notify('new_account', 'New account registered', [
                'intro' => ($companyLabel ?: 'A new account') . ' signed up.',
                'details' => [
                    ['label' => 'Name', 'value' => $user->name ?: 'Unknown'],
                    ['label' => 'Email', 'value' => $user->email ?: 'Unknown'],
                    ['label' => 'Company', 'value' => $user->company_name ?: 'Not set'],
                    ['label' => 'Registered', 'value' => optional($user->created_at)->toDateTimeString() ?: now()->toDateTimeString()],
                ],
                'actionUrl' => route('superadmin.tenants.show', $user->id),
                'actionLabel' => 'View tenant',
                'reference' => 'registration:' . $user->id,
                'severity' => 'info',
            ]);
        });

        Event::listen(SubscriptionCreated::class, function (SubscriptionCreated $event): void {
            $billable = $event->billable;
            if (!$billable instanceof User) {
                return;
            }

            $subscription = $event->subscription;
            $notifier = app(PlatformAdminNotifier::class);
            $priceId = $subscription->items->first()?->price_id
                ?? data_get($event->payload, 'data.items.0.price.id');
            $planName = $notifier->resolvePlanName($priceId);
            $trialEnds = $subscription->trial_ends_at?->toDateString();

            $notifier->notify('subscription_started', 'Subscription started', [
                'intro' => ($billable->company_name ?: $billable->email) . ' started a subscription.',
                'details' => [
                    ['label' => 'Company', 'value' => $billable->company_name ?: 'Not set'],
                    ['label' => 'Owner', 'value' => $billable->email ?: 'Unknown'],
                    ['label' => 'Plan', 'value' => $planName],
                    ['label' => 'Status', 'value' => $notifier->resolveSubscriptionStatusLabel($subscription->status)],
                    ['label' => 'Trial ends', 'value' => $trialEnds ?: 'None'],
                ],
                'actionUrl' => route('superadmin.tenants.show', $billable->id),
                'actionLabel' => 'View tenant',
                'reference' => 'subscription:' . $subscription->paddle_id . ':started',
                'severity' => 'info',
            ]);
        });

        Event::listen(SubscriptionPaused::class, function (SubscriptionPaused $event): void {
            $subscription = $event->subscription;
            $billable = $subscription->billable;
            if (!$billable instanceof User) {
                return;
            }

            $notifier = app(PlatformAdminNotifier::class);
            $pausedAt = $subscription->paused_at?->toDateString();

            $notifier->notify('subscription_paused', 'Subscription paused', [
                'intro' => ($billable->company_name ?: $billable->email) . ' paused their subscription.',
                'details' => [
                    ['label' => 'Company', 'value' => $billable->company_name ?: 'Not set'],
                    ['label' => 'Owner', 'value' => $billable->email ?: 'Unknown'],
                    ['label' => 'Status', 'value' => $notifier->resolveSubscriptionStatusLabel($subscription->status)],
                    ['label' => 'Paused at', 'value' => $pausedAt ?: 'Unknown'],
                ],
                'actionUrl' => route('superadmin.tenants.show', $billable->id),
                'actionLabel' => 'View tenant',
                'reference' => 'subscription:' . $subscription->paddle_id . ':paused',
                'severity' => 'warning',
            ]);
        });

        Event::listen(SubscriptionCanceled::class, function (SubscriptionCanceled $event): void {
            $subscription = $event->subscription;
            $billable = $subscription->billable;
            if (!$billable instanceof User) {
                return;
            }

            $notifier = app(PlatformAdminNotifier::class);
            $endsAt = $subscription->ends_at?->toDateString();

            $notifier->notify('subscription_canceled', 'Subscription canceled', [
                'intro' => ($billable->company_name ?: $billable->email) . ' canceled their subscription.',
                'details' => [
                    ['label' => 'Company', 'value' => $billable->company_name ?: 'Not set'],
                    ['label' => 'Owner', 'value' => $billable->email ?: 'Unknown'],
                    ['label' => 'Status', 'value' => $notifier->resolveSubscriptionStatusLabel($subscription->status)],
                    ['label' => 'Ends at', 'value' => $endsAt ?: 'Unknown'],
                ],
                'actionUrl' => route('superadmin.tenants.show', $billable->id),
                'actionLabel' => 'View tenant',
                'reference' => 'subscription:' . $subscription->paddle_id . ':canceled',
                'severity' => 'warning',
            ]);
        });

        Event::listen(SubscriptionUpdated::class, function (SubscriptionUpdated $event): void {
            $subscription = $event->subscription;
            $billable = $subscription->billable;
            if (!$billable instanceof User) {
                return;
            }

            $currentStatus = data_get($event->payload, 'data.status');
            $previousStatus = data_get($event->payload, 'data.previous_status')
                ?? data_get($event->payload, 'data.previous_attributes.status')
                ?? data_get($event->payload, 'data.status_previous');

            if (!$previousStatus || $currentStatus !== PaddleSubscription::STATUS_ACTIVE) {
                return;
            }

            if (!in_array($previousStatus, [
                PaddleSubscription::STATUS_PAUSED,
                PaddleSubscription::STATUS_PAST_DUE,
                PaddleSubscription::STATUS_CANCELED,
            ], true)) {
                return;
            }

            $notifier = app(PlatformAdminNotifier::class);

            $notifier->notify('subscription_resumed', 'Subscription resumed', [
                'intro' => ($billable->company_name ?: $billable->email) . ' resumed their subscription.',
                'details' => [
                    ['label' => 'Company', 'value' => $billable->company_name ?: 'Not set'],
                    ['label' => 'Owner', 'value' => $billable->email ?: 'Unknown'],
                    ['label' => 'Status', 'value' => $notifier->resolveSubscriptionStatusLabel($subscription->status)],
                ],
                'actionUrl' => route('superadmin.tenants.show', $billable->id),
                'actionLabel' => 'View tenant',
                'reference' => 'subscription:' . $subscription->paddle_id . ':resumed',
                'severity' => 'success',
            ]);
        });

        Event::listen(TransactionCompleted::class, function (TransactionCompleted $event): void {
            $billable = $event->billable;
            if (!$billable instanceof User) {
                return;
            }

            $transaction = $event->transaction;
            $notifier = app(PlatformAdminNotifier::class);
            $amount = $notifier->formatMoney($transaction->total, $transaction->currency);

            $notifier->notify('payment_succeeded', 'Payment received', [
                'intro' => ($billable->company_name ?: $billable->email) . ' completed a payment.',
                'details' => [
                    ['label' => 'Company', 'value' => $billable->company_name ?: 'Not set'],
                    ['label' => 'Owner', 'value' => $billable->email ?: 'Unknown'],
                    ['label' => 'Amount', 'value' => $amount],
                    ['label' => 'Status', 'value' => $notifier->resolveTransactionStatusLabel($transaction->status)],
                    ['label' => 'Invoice', 'value' => $transaction->invoice_number ?: 'Unknown'],
                ],
                'actionUrl' => route('superadmin.tenants.show', $billable->id),
                'actionLabel' => 'View tenant',
                'reference' => 'payment:' . $transaction->paddle_id,
                'severity' => 'success',
            ]);
        });

        Event::listen(TransactionUpdated::class, function (TransactionUpdated $event): void {
            $billable = $event->billable;
            if (!$billable instanceof User) {
                return;
            }

            $transaction = $event->transaction;
            if (!in_array($transaction->status, [
                PaddleTransaction::STATUS_PAST_DUE,
                PaddleTransaction::STATUS_CANCELED,
            ], true)) {
                return;
            }

            $notifier = app(PlatformAdminNotifier::class);
            $amount = $notifier->formatMoney($transaction->total, $transaction->currency);

            $notifier->notify('payment_failed', 'Payment failed', [
                'intro' => ($billable->company_name ?: $billable->email) . ' has a failed payment.',
                'details' => [
                    ['label' => 'Company', 'value' => $billable->company_name ?: 'Not set'],
                    ['label' => 'Owner', 'value' => $billable->email ?: 'Unknown'],
                    ['label' => 'Amount', 'value' => $amount],
                    ['label' => 'Status', 'value' => $notifier->resolveTransactionStatusLabel($transaction->status)],
                    ['label' => 'Invoice', 'value' => $transaction->invoice_number ?: 'Unknown'],
                ],
                'actionUrl' => route('superadmin.tenants.show', $billable->id),
                'actionLabel' => 'View tenant',
                'reference' => 'payment:' . $transaction->paddle_id . ':' . $transaction->status,
                'severity' => 'warning',
            ]);
        });
    }
}
