<?php

namespace App\Services\Demo;

use App\Models\DemoWorkspace;
use App\Models\User;
use Carbon\CarbonImmutable;

final class DemoWorkspaceReferenceClock
{
    public function forOwner(User $owner): CarbonImmutable
    {
        $workspace = DemoWorkspace::query()
            ->where('owner_user_id', $owner->id)
            ->whereNotNull('reference_date')
            ->latest('id')
            ->first(['reference_date', 'timezone']);

        $timezone = filled($workspace?->timezone)
            ? (string) $workspace->timezone
            : (filled($owner->company_timezone)
                ? (string) $owner->company_timezone
                : (string) config('app.timezone', 'UTC'));

        if ($workspace?->reference_date) {
            return CarbonImmutable::parse($workspace->reference_date->toDateString(), $timezone)->endOfDay();
        }

        return CarbonImmutable::now($timezone);
    }
}
