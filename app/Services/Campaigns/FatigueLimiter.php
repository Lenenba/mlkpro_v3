<?php

namespace App\Services\Campaigns;

use App\Models\Campaign;
use App\Models\CampaignRecipient;
use App\Models\Customer;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;

class FatigueLimiter
{
    public function __construct(
        private readonly MarketingSettingsService $marketingSettingsService,
    ) {
    }

    public function canSend(
        User $accountOwner,
        ?Customer $customer,
        string $channel,
        ?Campaign $campaign = null,
        ?Carbon $at = null
    ): array {
        if (!$customer) {
            return ['allowed' => true];
        }

        $now = $at ?: Carbon::now();
        if ($this->isQuietHours($accountOwner, $now)) {
            return ['allowed' => false, 'reason' => 'quiet_hours'];
        }

        $maxMessages = max(1, (int) $this->setting(
            $accountOwner,
            'channels.anti_fatigue.max_messages_per_window',
            config('campaigns.fatigue.max_messages_per_window', 2)
        ));
        $windowDays = max(1, (int) $this->setting(
            $accountOwner,
            'channels.anti_fatigue.window_days',
            config('campaigns.fatigue.window_days', 7)
        ));
        $since = $now->copy()->subDays($windowDays);

        $recentCount = CampaignRecipient::query()
            ->where('user_id', $accountOwner->id)
            ->where('customer_id', $customer->id)
            ->where('channel', strtoupper($channel))
            ->whereNotNull('sent_at')
            ->where('sent_at', '>=', $since)
            ->count();

        if ($recentCount >= $maxMessages) {
            return ['allowed' => false, 'reason' => 'fatigue_limit'];
        }

        if ($campaign) {
            $cooldown = max(0, (int) $this->setting(
                $accountOwner,
                'channels.anti_fatigue.same_campaign_cooldown_hours',
                config('campaigns.fatigue.same_campaign_cooldown_hours', 48)
            ));
            if ($cooldown > 0) {
                $alreadySent = CampaignRecipient::query()
                    ->where('user_id', $accountOwner->id)
                    ->where('customer_id', $customer->id)
                    ->where('campaign_id', $campaign->id)
                    ->where('channel', strtoupper($channel))
                    ->where('created_at', '>=', $now->copy()->subHours($cooldown))
                    ->exists();

                if ($alreadySent) {
                    return ['allowed' => false, 'reason' => 'duplicate_campaign_cooldown'];
                }
            }
        }

        return ['allowed' => true];
    }

    public function isQuietHours(User $accountOwner, Carbon $at): bool
    {
        $configuredTimezone = $this->setting(
            $accountOwner,
            'channels.quiet_hours.timezone',
            null
        );
        $timezone = is_string($configuredTimezone) && trim($configuredTimezone) !== ''
            ? trim($configuredTimezone)
            : ($accountOwner->company_timezone ?: config('app.timezone', 'UTC'));

        try {
            $local = CarbonImmutable::instance($at)->setTimezone($timezone);
        } catch (\Throwable) {
            $local = CarbonImmutable::instance($at)->setTimezone(config('app.timezone', 'UTC'));
        }

        $start = (string) $this->setting(
            $accountOwner,
            'channels.quiet_hours.start',
            config('campaigns.quiet_hours.start', '21:00')
        );
        $end = (string) $this->setting(
            $accountOwner,
            'channels.quiet_hours.end',
            config('campaigns.quiet_hours.end', '08:00')
        );

        [$startHour, $startMinute] = $this->parseHourMinute($start);
        [$endHour, $endMinute] = $this->parseHourMinute($end);

        $startMinutes = ($startHour * 60) + $startMinute;
        $endMinutes = ($endHour * 60) + $endMinute;
        $currentMinutes = ((int) $local->format('H') * 60) + (int) $local->format('i');

        if ($startMinutes === $endMinutes) {
            return false;
        }

        if ($startMinutes < $endMinutes) {
            return $currentMinutes >= $startMinutes && $currentMinutes < $endMinutes;
        }

        return $currentMinutes >= $startMinutes || $currentMinutes < $endMinutes;
    }

    private function setting(User $accountOwner, string $path, mixed $default): mixed
    {
        return $this->marketingSettingsService->getValue($accountOwner, $path, $default);
    }

    private function parseHourMinute(string $value): array
    {
        if (!preg_match('/^(\d{1,2}):(\d{2})$/', trim($value), $matches)) {
            return [0, 0];
        }

        $hour = max(0, min(23, (int) $matches[1]));
        $minute = max(0, min(59, (int) $matches[2]));

        return [$hour, $minute];
    }
}
