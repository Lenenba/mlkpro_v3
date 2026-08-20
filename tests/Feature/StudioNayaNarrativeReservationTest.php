<?php

use App\Models\AvailabilityException;
use App\Models\Customer;
use App\Models\Reservation;
use App\Models\Role;
use App\Models\User;
use App\Services\Demo\DemoWorkspaceCatalog;
use App\Services\Demo\DemoWorkspaceProvisioner;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * @return array<string, mixed>
 */
function studioNayaNarrativeReservationPayload(): array
{
    $catalog = app(DemoWorkspaceCatalog::class);
    $preset = collect($catalog->presets())->firstWhere('key', 'studio_naya_coiffure');

    return array_replace($catalog->defaults(), [
        'prospect_name' => $preset['prospect_name'],
        'prospect_email' => null,
        'prospect_company' => $preset['prospect_company'],
        'company_name' => $preset['company_name'],
        'company_type' => $preset['company_type'],
        'company_sector' => $preset['company_sector'],
        'seed_profile' => $preset['seed_profile'],
        'scenario_key' => $preset['scenario_key'],
        'data_volume' => 'small',
        'reference_date' => '2026-08-20',
        'random_seed' => 12345,
        'scenario_version' => 1,
        'team_size' => $preset['team_size'],
        'locale' => $preset['locale'],
        'timezone' => $preset['timezone'],
        'desired_outcome' => $preset['desired_outcome'],
        'selected_modules' => $preset['modules'],
        'scenario_packs' => $preset['scenario_packs'],
        'branding_profile' => $preset['branding_profile'],
        'extra_access_roles' => $preset['extra_access_roles'],
        'suggested_flow' => $preset['suggested_flow'],
        'expires_at' => '2026-09-03',
    ]);
}

it('materializes the Studio Naya reservation stories at their exact blueprint dates', function () {
    $role = Role::query()->firstOrCreate(
        ['name' => 'superadmin'],
        ['description' => 'Superadmin role'],
    );
    $admin = User::query()->create([
        'name' => 'Narrative Reservation Admin',
        'email' => 'narrative-reservation-admin@example.test',
        'password' => 'password',
        'role_id' => $role->id,
        'onboarding_completed_at' => now(),
    ]);
    $workspace = app(DemoWorkspaceProvisioner::class)->create(
        studioNayaNarrativeReservationPayload(),
        $admin,
    );
    $ownerId = (int) $workspace->owner_user_id;
    $timezone = (string) $workspace->timezone;
    $reference = CarbonImmutable::parse('2026-08-20', $timezone)->startOfDay();
    $customers = Customer::query()
        ->where('user_id', $ownerId)
        ->whereIn('first_name', ['Aïcha', 'Samantha', 'Nadia', 'Marc-André', 'Chloé'])
        ->get()
        ->keyBy(fn (Customer $customer): string => trim($customer->first_name.' '.$customer->last_name));
    $reservationsFor = fn (string $customerName): Collection => Reservation::query()
        ->where('account_id', $ownerId)
        ->where('client_id', $customers->get($customerName)?->id)
        ->with(['client', 'service', 'teamMember.user'])
        ->orderBy('starts_at')
        ->get();
    $serviceKey = function (Reservation $reservation): ?string {
        $tag = collect((array) $reservation->service?->tags)
            ->first(fn (mixed $value): bool => is_string($value) && str_starts_with($value, 'key:'));

        return is_string($tag) ? substr($tag, 4) : null;
    };
    $findExact = function (
        Collection $reservations,
        string $event,
        int $offsetDays,
        string $status,
        string $expectedService,
        string $expectedEmployee,
    ) use ($reference, $timezone, $serviceKey): Reservation {
        $expectedDate = $reference->addDays($offsetDays)->toDateString();
        $reservation = $reservations->first(function (Reservation $candidate) use ($event, $expectedDate, $timezone): bool {
            return data_get($candidate->metadata, 'narrative_event') === $event
                && $candidate->starts_at?->copy()->setTimezone($timezone)->toDateString() === $expectedDate;
        });

        expect($reservation)->toBeInstanceOf(Reservation::class)
            ->and($reservation->status)->toBe($status)
            ->and($serviceKey($reservation))->toBe($expectedService)
            ->and($reservation->teamMember?->user?->name)->toBe($expectedEmployee)
            ->and((int) data_get($reservation->metadata, 'narrative_offset_days'))->toBe($offsetDays)
            ->and((bool) data_get($reservation->metadata, 'narrative_assignment'))->toBeTrue()
            ->and($reservation->client?->created_at?->lte($reservation->created_at))->toBeTrue()
            ->and($reservation->created_at?->lt($reservation->starts_at))->toBeTrue()
            ->and($reservation->starts_at?->diffInMinutes($reservation->ends_at))->toBe((float) $reservation->duration_minutes);

        return $reservation;
    };

    $aicha = $reservationsFor('Aïcha Martin');
    expect($aicha)->toHaveCount(14)
        ->and($aicha->where('status', Reservation::STATUS_COMPLETED))->toHaveCount(12)
        ->and($aicha->where('status', Reservation::STATUS_RESCHEDULED))->toHaveCount(1)
        ->and($aicha->where('status', Reservation::STATUS_CONFIRMED))->toHaveCount(1);
    $findExact($aicha, 'first_completed_reservation', -441, Reservation::STATUS_COMPLETED, 'short_box_braids', 'Sarah Mbaye');
    $findExact($aicha, 'reservation_rescheduled', -126, Reservation::STATUS_RESCHEDULED, 'cornrows', 'Sarah Mbaye');
    $aichaFuture = $findExact($aicha, 'next_reservation_confirmed', 18, Reservation::STATUS_CONFIRMED, 'short_box_braids', 'Sarah Mbaye');

    $samantha = $reservationsFor('Samantha Joseph');
    expect($samantha)->toHaveCount(3);
    $findExact($samantha, 'consultation_reservation', -28, Reservation::STATUS_COMPLETED, 'hair_consultation', 'Maya Koné');
    $findExact($samantha, 'trial_reservation', 12, Reservation::STATUS_CONFIRMED, 'event_updo', 'Maya Koné');
    $findExact($samantha, 'wedding_reservation', 47, Reservation::STATUS_CONFIRMED, 'event_updo', 'Maya Koné');

    $nadia = $reservationsFor('Nadia Pierre');
    expect($nadia)->toHaveCount(2);
    $nadiaNoShow = $findExact($nadia, 'reservation_no_show', -24, Reservation::STATUS_NO_SHOW, 'long_hair_blowout', 'Alicia Tremblay');
    $findExact($nadia, 'new_reservation_pending', 15, Reservation::STATUS_PENDING, 'deep_conditioning', 'Alicia Tremblay');

    $marc = $reservationsFor('Marc-André Beaulieu');
    $marcCompleted = $marc->where('status', Reservation::STATUS_COMPLETED)->values();
    $marcOffsets = $marcCompleted
        ->map(fn (Reservation $reservation): int => (int) $reference->diffInDays(
            CarbonImmutable::instance($reservation->starts_at)->setTimezone($timezone)->startOfDay(),
            false,
        ))
        ->all();
    expect($marc)->toHaveCount(22)
        ->and($marcCompleted)->toHaveCount(21)
        ->and($marcOffsets)->toBe(collect(range(0, 20))->map(fn (int $index): int => -497 + ($index * 21))->all())
        ->and($marcCompleted->every(fn (Reservation $reservation): bool => $serviceKey($reservation) === 'haircut_and_beard'))->toBeTrue()
        ->and($marcCompleted->every(fn (Reservation $reservation): bool => $reservation->teamMember?->user?->name === 'Kevin Diallo'))->toBeTrue();
    $findExact($marc, 'next_reservation_confirmed', 14, Reservation::STATUS_CONFIRMED, 'haircut_and_beard', 'Kevin Diallo');

    $chloe = $reservationsFor('Chloé Nguyen');
    expect($chloe)->toHaveCount(2);
    $findExact($chloe, 'color_reservation_completed', -35, Reservation::STATUS_COMPLETED, 'balayage', 'Maya Koné');
    $findExact($chloe, 'discounted_correction_completed', -27, Reservation::STATUS_COMPLETED, 'toner', 'Maya Koné');

    foreach ([$aichaFuture, $nadiaNoShow] as $specialOpeningReservation) {
        expect(AvailabilityException::query()
            ->where('account_id', $ownerId)
            ->where('team_member_id', $specialOpeningReservation->team_member_id)
            ->whereDate('date', $specialOpeningReservation->starts_at->copy()->setTimezone($timezone)->toDateString())
            ->where('type', AvailabilityException::TYPE_OPEN)
            ->exists())->toBeTrue();
    }

    $todayReservations = Reservation::query()
        ->where('account_id', $ownerId)
        ->whereBetween('starts_at', [$reference->utc(), $reference->endOfDay()->utc()])
        ->get();
    expect($todayReservations)->not->toBeEmpty()
        ->and($todayReservations->pluck('team_member_id')->unique())->toHaveCount(5);

    $allByMember = Reservation::query()
        ->where('account_id', $ownerId)
        ->orderBy('starts_at')
        ->get()
        ->groupBy('team_member_id');
    foreach ($allByMember as $memberReservations) {
        $previous = null;
        foreach ($memberReservations as $reservation) {
            if ($previous) {
                $effectiveBuffer = max((int) $previous->buffer_minutes, (int) $reservation->buffer_minutes);
                expect($reservation->starts_at->gte($previous->ends_at->copy()->addMinutes($effectiveBuffer)))->toBeTrue();
            }
            $previous = $reservation;
        }
    }
});
