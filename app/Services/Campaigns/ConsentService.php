<?php

namespace App\Services\Campaigns;

use App\Models\Campaign;
use App\Models\CampaignRecipient;
use App\Models\Customer;
use App\Models\CustomerConsent;
use App\Models\CustomerOptOut;
use App\Models\User;
use Illuminate\Support\Carbon;

class ConsentService
{
    public function __construct(
        private readonly MarketingSettingsService $marketingSettingsService,
    ) {
    }

    public function canReceive(
        User $accountOwner,
        ?Customer $customer,
        string $channel,
        ?string $destination
    ): array {
        $normalizedChannel = strtoupper($channel);
        $normalizedDestination = $this->normalizeDestination($normalizedChannel, $destination);

        if ($normalizedChannel === Campaign::CHANNEL_IN_APP) {
            if (!$customer || !$customer->portal_user_id) {
                return ['allowed' => false, 'reason' => 'missing_app_account'];
            }

            return ['allowed' => true, 'destination' => (string) $customer->portal_user_id];
        }

        if (!$normalizedDestination) {
            return ['allowed' => false, 'reason' => 'missing_destination'];
        }

        $hash = CampaignRecipient::destinationHash($normalizedDestination);
        if ($hash) {
            $optOutExists = CustomerOptOut::query()
                ->where('user_id', $accountOwner->id)
                ->where('channel', $normalizedChannel)
                ->where('destination_hash', $hash)
                ->exists();

            if ($optOutExists) {
                return ['allowed' => false, 'reason' => 'opted_out'];
            }
        }

        $defaultBehavior = strtolower((string) $this->marketingSettingsService->getValue(
            $accountOwner,
            'consent.default_behavior',
            'deny_without_explicit'
        ));
        $requireExplicit = (bool) $this->marketingSettingsService->getValue(
            $accountOwner,
            'consent.require_explicit',
            config('campaigns.require_explicit_consent', true)
        );
        if ($defaultBehavior === 'allow_without_explicit') {
            $requireExplicit = false;
        }

        if (!$customer) {
            if ($requireExplicit) {
                return ['allowed' => false, 'reason' => 'consent_missing'];
            }

            return ['allowed' => true, 'destination' => $normalizedDestination];
        }

        $consent = CustomerConsent::query()
            ->where('user_id', $accountOwner->id)
            ->where('customer_id', $customer->id)
            ->where('channel', $normalizedChannel)
            ->first();

        if ($consent && $consent->status === CustomerConsent::STATUS_REVOKED) {
            return ['allowed' => false, 'reason' => 'consent_revoked'];
        }

        if ($requireExplicit && (!$consent || $consent->status !== CustomerConsent::STATUS_GRANTED)) {
            return ['allowed' => false, 'reason' => 'consent_missing'];
        }

        return ['allowed' => true, 'destination' => $normalizedDestination];
    }

    public function grant(
        User $accountOwner,
        Customer $customer,
        string $channel,
        ?string $source = null,
        array $metadata = []
    ): CustomerConsent {
        return CustomerConsent::query()->updateOrCreate(
            [
                'user_id' => $accountOwner->id,
                'customer_id' => $customer->id,
                'channel' => strtoupper($channel),
            ],
            [
                'status' => CustomerConsent::STATUS_GRANTED,
                'source' => $source,
                'granted_at' => Carbon::now(),
                'revoked_at' => null,
                'metadata' => $metadata ?: null,
            ]
        );
    }

    public function revoke(
        User $accountOwner,
        ?Customer $customer,
        string $channel,
        string $destination,
        ?string $source = null,
        ?string $reason = null,
        array $metadata = []
    ): CustomerOptOut {
        $normalizedChannel = strtoupper($channel);
        $normalizedDestination = $this->normalizeDestination($normalizedChannel, $destination) ?? trim($destination);
        $hash = CampaignRecipient::destinationHash($normalizedDestination);

        $optOut = CustomerOptOut::query()->updateOrCreate(
            [
                'user_id' => $accountOwner->id,
                'channel' => $normalizedChannel,
                'destination_hash' => $hash ?: hash('sha256', strtolower($normalizedDestination)),
            ],
            [
                'customer_id' => $customer?->id,
                'destination' => $normalizedDestination,
                'reason' => $reason,
                'source' => $source,
                'opted_out_at' => Carbon::now(),
                'metadata' => $metadata ?: null,
            ]
        );

        if ($customer) {
            CustomerConsent::query()->updateOrCreate(
                [
                    'user_id' => $accountOwner->id,
                    'customer_id' => $customer->id,
                    'channel' => $normalizedChannel,
                ],
                [
                    'status' => CustomerConsent::STATUS_REVOKED,
                    'source' => $source,
                    'revoked_at' => Carbon::now(),
                    'metadata' => $metadata ?: null,
                ]
            );
        }

        return $optOut;
    }

    private function normalizeDestination(string $channel, ?string $destination): ?string
    {
        $value = trim((string) $destination);
        if ($value === '') {
            return null;
        }

        if ($channel === Campaign::CHANNEL_EMAIL) {
            return filter_var($value, FILTER_VALIDATE_EMAIL) ? strtolower($value) : null;
        }

        if ($channel === Campaign::CHANNEL_SMS) {
            $digits = preg_replace('/\D+/', '', $value) ?: '';
            if ($digits === '') {
                return null;
            }

            if (str_starts_with($digits, '00') && strlen($digits) > 2) {
                $digits = substr($digits, 2);
            }

            if (strlen($digits) === 10) {
                return '+1' . $digits;
            }

            if (strlen($digits) >= 11) {
                return '+' . $digits;
            }

            return null;
        }

        return $value;
    }
}
