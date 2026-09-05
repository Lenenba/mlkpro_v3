<?php

namespace App\Services\Reservations;

use App\Models\PublicBookingLink;
use App\Models\User;
use App\Services\BillingPlanService;
use App\Services\BillingSubscriptionService;
use App\Services\CompanyFeatureService;
use App\Services\ReservationAvailabilityService;
use App\Support\ReservationPresetResolver;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\URL;

class PublicReservationNavigationService
{
    public function __construct(
        private readonly CompanyFeatureService $featureService,
        private readonly ReservationAvailabilityService $availabilityService,
        private readonly BillingSubscriptionService $billingSubscriptionService,
        private readonly BillingPlanService $billingPlanService,
    ) {}

    public function publicBookingUrl(User $account): ?string
    {
        if (! $this->reservationsAreAvailable($account) || ! Route::has('public.booking.show')) {
            return null;
        }

        $link = PublicBookingLink::query()
            ->forAccount((int) $account->id)
            ->where('is_active', true)
            ->where(function (Builder $query): void {
                $query
                    ->whereNull('expires_at')
                    ->orWhere('expires_at', '>', now());
            })
            ->latest()
            ->first();

        return $link?->publicUrl($account);
    }

    /**
     * @param  array<string, mixed>|null  $settings
     */
    public function publicKioskUrl(User $account, ?array $settings = null): ?string
    {
        if (! $this->reservationsAreAvailable($account) || ! Route::has('public.kiosk.reservations.show')) {
            return null;
        }

        $planKey = $this->billingSubscriptionService->resolvePlanKey(
            $account,
            config('billing.plans', [])
        );
        if ($planKey && $this->billingPlanService->isOwnerOnlyPlan($planKey)) {
            return null;
        }

        $settings ??= $this->availabilityService->resolveSettings((int) $account->id, null);
        if (! ReservationPresetResolver::queueFeaturesEnabled((string) ($settings['business_preset'] ?? null))) {
            return null;
        }

        if (! ($settings['queue_mode_enabled'] ?? false)) {
            return null;
        }

        if (! (bool) data_get($account->company_notification_settings, 'reservations.kiosk_enabled', true)) {
            return null;
        }

        return URL::signedRoute('public.kiosk.reservations.show', [
            'account' => (int) $account->id,
        ]);
    }

    private function reservationsAreAvailable(User $account): bool
    {
        return ! $account->isSuspended()
            && $this->featureService->hasFeature($account, 'reservations');
    }
}
