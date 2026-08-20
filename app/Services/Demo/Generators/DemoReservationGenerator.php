<?php

namespace App\Services\Demo\Generators;

use App\Models\AvailabilityException;
use App\Models\Customer;
use App\Models\Product;
use App\Models\PublicBookingLink;
use App\Models\Reservation;
use App\Models\TeamMember;
use App\Services\Demo\DemoScenarioContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;

final class DemoReservationGenerator
{
    /**
     * @param  array<string, mixed>  $blueprint
     * @param  Collection<int, Customer>  $customers
     * @param  Collection<string, Customer>  $storyCustomers
     * @param  Collection<string, TeamMember>  $teamMembers
     * @param  Collection<string, Product>  $services
     * @return array{reservations: Collection<int, int>, public_booking_links:int}
     */
    public function generate(
        DemoScenarioContext $context,
        array $blueprint,
        Collection $customers,
        Collection $storyCustomers,
        Collection $teamMembers,
        Collection $services,
        int $reservationTarget,
    ): array {
        $narrativeSlots = $this->buildNarrativeSlots(
            $context,
            $blueprint,
            $storyCustomers,
            $teamMembers,
            $services,
        );
        $genericTarget = $reservationTarget - $narrativeSlots->count();

        if ($genericTarget < 0) {
            throw new RuntimeException(sprintf(
                'Studio Naya requires %d narrative reservations for a target of %d.',
                $narrativeSlots->count(),
                $reservationTarget,
            ));
        }

        $candidates = $this->buildCandidates($context, $blueprint, $teamMembers, $services);
        $candidates = $candidates
            ->reject(fn (array $candidate): bool => $narrativeSlots->contains(
                fn (array $narrative): bool => $this->slotsOverlap($candidate, $narrative),
            ))
            ->values();

        if ($candidates->count() < $genericTarget) {
            throw new RuntimeException(sprintf(
                'Studio Naya produced only %d collision-free slots for a target of %d.',
                $candidates->count(),
                $genericTarget,
            ));
        }

        $selected = $this->selectCandidates($candidates, $context, $genericTarget);
        unset($candidates);
        gc_collect_cycles();
        $reservations = $this->createNarrativeReservations($context, $blueprint, $narrativeSlots);
        $storyCustomerIds = $storyCustomers
            ->pluck('id')
            ->map(fn (mixed $id): int => (int) $id)
            ->flip();
        $genericCustomers = $customers
            ->reject(fn (Customer $customer): bool => $storyCustomerIds->has((int) $customer->id))
            ->values();

        if ($genericTarget > 0 && $genericCustomers->isEmpty()) {
            throw new RuntimeException('Studio Naya needs at least one non-narrative customer to fill generic reservations.');
        }

        foreach ($selected as $index => $slot) {
            $startsAt = $slot['starts_at'];
            $isPast = $startsAt->lt($context->referenceDate->startOfDay());
            $status = isset($slot['forced_status'])
                ? (string) $slot['forced_status']
                : ($isPast
                    ? $this->pastStatus($index)
                    : ($index % 4 === 0 ? Reservation::STATUS_PENDING : Reservation::STATUS_CONFIRMED));
            $createdAt = $isPast
                ? $startsAt->subDays(3 + ($index % 42))
                : $context->referenceDate->subDays(1 + ($index % 21));
            $historyStart = $context->referenceDate->subMonths(18)->startOfDay();
            if ($createdAt->lt($historyStart)) {
                $createdAt = $historyStart;
            }
            $eligibleCustomers = $genericCustomers
                ->filter(fn (Customer $customer): bool => ! $customer->created_at || $customer->created_at->lte($createdAt))
                ->values();
            $customer = $eligibleCustomers->isNotEmpty()
                ? $eligibleCustomers[$index % $eligibleCustomers->count()]
                : $genericCustomers->sortBy('created_at')->first();
            $cancelledAt = $status === Reservation::STATUS_CANCELLED
                ? $startsAt->subDays(1 + ($index % 5))
                : null;

            $reservation = Reservation::query()->create([
                'account_id' => $context->owner->id,
                'team_member_id' => $slot['member']->id,
                'client_id' => $customer->id,
                'public_booking_link_id' => null,
                'service_id' => $slot['service']->id,
                'status' => $status,
                'source' => $index % 5 === 0 ? Reservation::SOURCE_PUBLIC_BOOKING : Reservation::SOURCE_STAFF,
                'timezone' => $context->timezone,
                'starts_at' => $startsAt->utc(),
                'ends_at' => $slot['ends_at']->utc(),
                'duration_minutes' => $slot['duration_minutes'],
                'buffer_minutes' => $slot['buffer_minutes'],
                'internal_notes' => $this->reservationNote($index, $status),
                'client_notes' => $index % 13 === 0 ? 'Préférence confirmée lors du rappel.' : null,
                'cancelled_at' => $cancelledAt?->utc(),
                'cancel_reason' => $cancelledAt ? ($index % 2 === 0 ? 'Conflit d’horaire' : 'Empêchement personnel') : null,
                'created_by_user_id' => $context->owner->id,
                'metadata' => [
                    'scenario_key' => $blueprint['key'],
                    'service_key' => $slot['service_key'],
                    'employee_key' => $slot['member_key'],
                    'preparation_minutes' => $slot['preparation_minutes'],
                    'cleanup_minutes' => $slot['cleanup_minutes'],
                    'secondary_service_keys' => $index % 17 === 0 ? ['hydrating_shampoo_care'] : [],
                    'deposit_required' => $index % 11 === 0,
                ],
            ]);

            DB::table('reservations')->where('id', $reservation->id)->update([
                'created_at' => $createdAt->utc(),
                'updated_at' => max($createdAt->timestamp, $startsAt->subDay()->timestamp) === $createdAt->timestamp
                    ? $createdAt->utc()
                    : $startsAt->subDay()->utc(),
            ]);
            $reservations->push($reservation->fresh());
        }

        $link = $this->createPublicBookingLink($context, $services);
        Reservation::query()
            ->where('account_id', $context->owner->id)
            ->where('source', Reservation::SOURCE_PUBLIC_BOOKING)
            ->update(['public_booking_link_id' => $link->id]);

        return [
            'reservations' => $reservations
                ->sortBy('starts_at')
                ->pluck('id')
                ->map(fn (mixed $id): int => (int) $id)
                ->values(),
            'public_booking_links' => 1,
        ];
    }

    /**
     * @param  array<string, mixed>  $blueprint
     * @param  Collection<string, TeamMember>  $teamMembers
     * @param  Collection<string, Product>  $services
     * @return Collection<int, array<string, mixed>>
     */
    private function buildCandidates(
        DemoScenarioContext $context,
        array $blueprint,
        Collection $teamMembers,
        Collection $services,
    ): Collection {
        $profiles = collect((array) $blueprint['employees'])->keyBy('key');
        $serviceDefinitions = collect((array) $blueprint['services'])->keyBy('key');
        $matrix = (array) $blueprint['employee_service_matrix'];
        $seasonality = (array) $blueprint['seasonality'];
        $blackouts = AvailabilityException::query()
            ->forAccount((int) $context->owner->id)
            ->where('type', AvailabilityException::TYPE_CLOSED)
            ->get(['team_member_id', 'date'])
            ->mapWithKeys(fn (AvailabilityException $exception): array => [
                $exception->team_member_id.'|'.$exception->date->toDateString() => true,
            ]);
        $date = $context->referenceDate->subMonths(18)->startOfDay();
        $end = $context->referenceDate->addWeeks(6)->endOfDay();
        $rng = $context->randomizer('reservations');
        $candidates = collect();

        while ($date->lte($end)) {
            foreach ($teamMembers as $memberKey => $member) {
                $profile = $profiles->get($memberKey);
                $hours = is_array($profile) ? data_get($profile, 'schedule.'.$date->dayOfWeekIso) : null;
                if (! is_array($hours) || $blackouts->has($member->id.'|'.$date->toDateString())) {
                    continue;
                }

                $allowed = array_values((array) data_get($matrix, $memberKey.'.bookable_service_keys', []));
                if ($allowed === []) {
                    continue;
                }

                $cursor = CarbonImmutable::parse($date->toDateString().' '.$hours['starts_at'], $context->timezone);
                $closesAt = CarbonImmutable::parse($date->toDateString().' '.$hours['ends_at'], $context->timezone);
                $slotIndex = 0;
                $previousEndsAt = null;
                $previousBuffer = 0;

                while ($cursor->addMinutes(20)->lte($closesAt)) {
                    $serviceKey = $allowed[$rng->getInt(0, count($allowed) - 1)];
                    $definition = $serviceDefinitions->get($serviceKey);
                    $service = $services->get($serviceKey);
                    if (! is_array($definition) || ! $service) {
                        break;
                    }

                    if (! (bool) $definition['active'] && $date->gte($context->referenceDate->subMonths(5))) {
                        $cursor = $cursor->addMinutes(30);
                        $slotIndex++;

                        continue;
                    }

                    $duration = (int) $definition['duration_minutes'];
                    $buffer = max(5, (int) $definition['buffer_after_minutes']);
                    if ($previousEndsAt instanceof CarbonImmutable) {
                        $minimumStart = $previousEndsAt->addMinutes(max($previousBuffer, $buffer));
                        if ($cursor->lt($minimumStart)) {
                            $cursor = $minimumStart;
                        }
                    }
                    $endsAt = $cursor->addMinutes($duration);
                    if ($endsAt->gt($closesAt)) {
                        break;
                    }

                    $monthWeight = (float) data_get($seasonality, 'monthly_demand_multipliers.'.$date->month, 1);
                    $weekdayWeight = (float) data_get($seasonality, 'weekday_demand_weights.'.$date->dayOfWeekIso, 1);
                    $demandWeight = match (data_get($definition, 'metadata.demand_profile')) {
                        'popular' => 1.22,
                        'high_value' => 1.08,
                        'low' => 0.72,
                        'legacy' => 0.18,
                        default => 1.0,
                    };
                    $score = $rng->getInt(1, 1_000_000) * $monthWeight * $weekdayWeight * $demandWeight;

                    $candidates->push([
                        'candidate_key' => $memberKey.'|'.$cursor->toIso8601String(),
                        'member_key' => $memberKey,
                        'member' => $member,
                        'service_key' => $serviceKey,
                        'service' => $service,
                        'starts_at' => $cursor,
                        'ends_at' => $endsAt,
                        'duration_minutes' => $duration,
                        'buffer_minutes' => $buffer,
                        'preparation_minutes' => (int) $definition['preparation_minutes'],
                        'cleanup_minutes' => (int) $definition['cleanup_minutes'],
                        'score' => $score,
                    ]);

                    $previousEndsAt = $endsAt;
                    $previousBuffer = $buffer;
                    $cursor = $endsAt->addMinutes($buffer + 10 + (($slotIndex % 3) * 5));
                    $slotIndex++;
                }
            }

            $date = $date->addDay();
        }

        return $candidates;
    }

    /**
     * Keep enough future appointments for every employee and one completed
     * appointment per employee in the reference month, then fill the remaining
     * capacity using the deterministic seasonality score. The current-month
     * sample makes reservation-backed reports useful at every data volume.
     *
     * @param  Collection<int, array<string, mixed>>  $candidates
     * @return Collection<int, array<string, mixed>>
     */
    private function selectCandidates(
        Collection $candidates,
        DemoScenarioContext $context,
        int $target,
    ): Collection {
        $referenceDay = $context->referenceDate->toDateString();
        $requiredFuture = $candidates
            ->filter(fn (array $slot): bool => $slot['starts_at']->gte($context->referenceDate))
            ->groupBy('member_key')
            ->flatMap(function (Collection $slots) use ($referenceDay): Collection {
                $today = $slots
                    ->filter(fn (array $slot): bool => $slot['starts_at']->toDateString() === $referenceDay)
                    ->sortByDesc('score')
                    ->take(1);
                $todayKeys = $today->pluck('candidate_key')->flip();
                $remaining = $slots
                    ->reject(fn (array $slot): bool => $todayKeys->has($slot['candidate_key']))
                    ->sortByDesc('score')
                    ->take(max(0, 4 - $today->count()));

                return $today->concat($remaining);
            })
            ->values();
        $requiredCurrentMonth = $candidates
            ->filter(fn (array $slot): bool => $slot['starts_at']->gte($context->referenceDate->startOfMonth())
                && $slot['ends_at']->lt($context->referenceDate->startOfDay()))
            ->groupBy('member_key')
            ->map(function (Collection $slots): array {
                $slot = $slots->sortByDesc('starts_at')->first();
                $slot['forced_status'] = Reservation::STATUS_COMPLETED;

                return $slot;
            })
            ->values();
        $required = $requiredFuture
            ->concat($requiredCurrentMonth)
            ->unique('candidate_key')
            ->values();
        $reservedKeys = $required->pluck('candidate_key')->flip();
        $remaining = $candidates
            ->reject(fn (array $slot): bool => $reservedKeys->has($slot['candidate_key']))
            ->sortByDesc('score')
            ->take(max(0, $target - $required->count()));

        return $required
            ->concat($remaining)
            ->take($target)
            ->sortBy('starts_at')
            ->values();
    }

    private function pastStatus(int $index): string
    {
        $bucket = $index % 100;

        return match (true) {
            $bucket < 78 => Reservation::STATUS_COMPLETED,
            $bucket < 88 => Reservation::STATUS_CANCELLED,
            $bucket < 94 => Reservation::STATUS_NO_SHOW,
            $bucket < 98 => Reservation::STATUS_RESCHEDULED,
            default => Reservation::STATUS_EXPIRED,
        };
    }

    private function reservationNote(int $index, string $status): string
    {
        return match ($status) {
            Reservation::STATUS_COMPLETED => $index % 9 === 0
                ? 'Service complété; fiche technique et recommandations de suivi enregistrées.'
                : 'Service complété selon la fiche client.',
            Reservation::STATUS_CANCELLED => 'Annulation conservée pour refléter l’activité réelle du salon.',
            Reservation::STATUS_NO_SHOW => 'Absence client; suivi et politique de dépôt à appliquer.',
            Reservation::STATUS_RESCHEDULED => 'Rendez-vous déplacé à la demande du client.',
            default => 'Rendez-vous Studio Naya généré par le scénario déterministe.',
        };
    }

    /**
     * Build the story appointments before generic volume filling. Their dates
     * are business facts from the blueprint, not suggestions for reassignment.
     *
     * @param  array<string, mixed>  $blueprint
     * @param  Collection<string, Customer>  $storyCustomers
     * @param  Collection<string, TeamMember>  $teamMembers
     * @param  Collection<string, Product>  $services
     * @return Collection<int, array<string, mixed>>
     */
    private function buildNarrativeSlots(
        DemoScenarioContext $context,
        array $blueprint,
        Collection $storyCustomers,
        Collection $teamMembers,
        Collection $services,
    ): Collection {
        $employeeProfiles = collect((array) $blueprint['employees'])->keyBy('key');
        $specifications = $this->narrativeSpecifications($blueprint);
        $slots = collect();

        foreach ($specifications as $sequence => $specification) {
            $storyKey = (string) $specification['story_key'];
            $employeeKey = (string) $specification['employee_key'];
            $serviceKey = (string) $specification['service_key'];
            $customer = $storyCustomers->get($storyKey);
            $member = $teamMembers->get($employeeKey);
            $service = $services->get($serviceKey);
            $profile = $employeeProfiles->get($employeeKey);

            if (! $customer || ! $member || ! $service || ! is_array($profile)) {
                throw new RuntimeException(sprintf(
                    'Studio Naya cannot resolve narrative reservation [%s/%s/%s].',
                    $storyKey,
                    $employeeKey,
                    $serviceKey,
                ));
            }

            $timing = $this->serviceTiming($service);
            $date = $context->referenceDate->addDays((int) $specification['offset_days']);
            $hours = data_get($profile, 'schedule.'.$date->dayOfWeekIso);
            $startsAt = is_array($hours)
                ? CarbonImmutable::parse($date->toDateString().' '.$hours['starts_at'], $context->timezone)
                : $date->setTime(10, 0);
            $endsAt = $startsAt->addMinutes($timing['duration_minutes']);

            if (is_array($hours)) {
                $closesAt = CarbonImmutable::parse($date->toDateString().' '.$hours['ends_at'], $context->timezone);
                if ($endsAt->gt($closesAt)) {
                    throw new RuntimeException(sprintf(
                        'Studio Naya narrative service [%s] does not fit %s schedule on %s.',
                        $serviceKey,
                        $employeeKey,
                        $date->toDateString(),
                    ));
                }
            } else {
                $this->ensureNarrativeOpening($context, $member, $startsAt, $endsAt, $timing['buffer_minutes']);
            }

            $slots->push([
                'candidate_key' => 'narrative|'.$storyKey.'|'.$specification['event'].'|'.$sequence,
                'member_key' => $employeeKey,
                'member' => $member,
                'service_key' => $serviceKey,
                'service' => $service,
                'customer' => $customer,
                'story_key' => $storyKey,
                'event' => (string) $specification['event'],
                'offset_days' => (int) $specification['offset_days'],
                'status' => (string) $specification['status'],
                'starts_at' => $startsAt,
                'ends_at' => $endsAt,
                'duration_minutes' => $timing['duration_minutes'],
                'buffer_minutes' => $timing['buffer_minutes'],
                'preparation_minutes' => $timing['preparation_minutes'],
                'cleanup_minutes' => $timing['cleanup_minutes'],
                'score' => PHP_INT_MAX,
            ]);
        }

        foreach ($slots as $index => $slot) {
            $collision = $slots->slice($index + 1)->first(
                fn (array $candidate): bool => $this->slotsOverlap($slot, $candidate),
            );

            if ($collision) {
                throw new RuntimeException(sprintf(
                    'Studio Naya narrative reservations collide for employee [%s] on [%s].',
                    $slot['member_key'],
                    $slot['starts_at']->toIso8601String(),
                ));
            }
        }

        return $slots->sortBy('starts_at')->values();
    }

    /**
     * @param  array<string, mixed>  $blueprint
     * @return array<int, array{story_key:string, employee_key:string, service_key:string, status:string, offset_days:int, event:string}>
     */
    private function narrativeSpecifications(array $blueprint): array
    {
        $stories = collect((array) $blueprint['client_stories'])->keyBy('key');
        $event = function (string $storyKey, string $eventKey) use ($stories): array {
            $story = $stories->get($storyKey);
            $timelineEvent = is_array($story)
                ? collect((array) ($story['timeline'] ?? []))->firstWhere('event', $eventKey)
                : null;

            if (! is_array($story) || ! is_array($timelineEvent)) {
                throw new RuntimeException(sprintf(
                    'Studio Naya blueprint is missing narrative event [%s/%s].',
                    $storyKey,
                    $eventKey,
                ));
            }

            return [$story, $timelineEvent];
        };
        $make = static function (
            array $story,
            array $timelineEvent,
            string $status,
            string $serviceKey,
            ?string $eventKey = null,
            ?int $offsetDays = null,
        ): array {
            return [
                'story_key' => (string) $story['key'],
                'employee_key' => (string) data_get($story, 'profile.preferred_employee_key'),
                'service_key' => $serviceKey,
                'status' => $status,
                'offset_days' => $offsetDays ?? (int) $timelineEvent['offset_days'],
                'event' => $eventKey ?? (string) $timelineEvent['event'],
            ];
        };
        $specifications = [];

        [$aicha, $aichaFirst] = $event('aicha_martin', 'first_completed_reservation');
        [, $aichaRescheduled] = $event('aicha_martin', 'reservation_rescheduled');
        [, $aichaFuture] = $event('aicha_martin', 'next_reservation_confirmed');
        $aichaCompletedCount = (int) data_get($aicha, 'expected_records.completed_reservations', 1);
        $aichaCompletedOffset = (int) $aichaFirst['offset_days'];
        $aichaRescheduledOffset = (int) $aichaRescheduled['offset_days'];
        for ($index = 0; $index < $aichaCompletedCount; $index++) {
            $offset = $aichaCompletedOffset + ($index * 35);
            if ($offset === $aichaRescheduledOffset) {
                $offset += 7;
            }
            $specifications[] = $make(
                $aicha,
                $aichaFirst,
                Reservation::STATUS_COMPLETED,
                (string) data_get($aicha, 'profile.favorite_service_keys.0'),
                $index === 0 ? null : 'loyal_visit_completed',
                $offset,
            );
        }
        $specifications[] = $make(
            $aicha,
            $aichaRescheduled,
            Reservation::STATUS_RESCHEDULED,
            (string) data_get($aicha, 'profile.favorite_service_keys.1'),
        );
        $specifications[] = $make(
            $aicha,
            $aichaFuture,
            Reservation::STATUS_CONFIRMED,
            (string) $aichaFuture['service_key'],
        );

        [$samantha, $samanthaConsultation] = $event('samantha_joseph', 'consultation_reservation');
        [, $samanthaTrial] = $event('samantha_joseph', 'trial_reservation');
        [, $samanthaWedding] = $event('samantha_joseph', 'wedding_reservation');
        $specifications[] = $make($samantha, $samanthaConsultation, Reservation::STATUS_COMPLETED, (string) $samanthaConsultation['service_key']);
        $specifications[] = $make($samantha, $samanthaTrial, Reservation::STATUS_CONFIRMED, (string) $samanthaTrial['service_key']);
        $specifications[] = $make($samantha, $samanthaWedding, Reservation::STATUS_CONFIRMED, (string) $samanthaWedding['service_key']);

        [$nadia, $nadiaNoShow] = $event('nadia_pierre', 'reservation_no_show');
        [, $nadiaFuture] = $event('nadia_pierre', 'new_reservation_pending');
        $specifications[] = $make($nadia, $nadiaNoShow, Reservation::STATUS_NO_SHOW, (string) $nadiaNoShow['service_key']);
        $specifications[] = $make($nadia, $nadiaFuture, Reservation::STATUS_PENDING, (string) $nadiaFuture['service_key']);

        [$marc, $marcSeries] = $event('marc_andre_beaulieu', 'recurring_series_started');
        [, $marcFuture] = $event('marc_andre_beaulieu', 'next_reservation_confirmed');
        $marcCount = (int) data_get($marc, 'expected_records.completed_reservations', 1);
        $marcInterval = max(1, (int) ($marcSeries['interval_days'] ?? 21));
        for ($index = 0; $index < $marcCount; $index++) {
            $specifications[] = $make(
                $marc,
                $marcSeries,
                Reservation::STATUS_COMPLETED,
                (string) data_get($marc, 'profile.favorite_service_keys.0'),
                $index === 0 ? null : 'recurring_series_reservation',
                (int) $marcSeries['offset_days'] + ($index * $marcInterval),
            );
        }
        $specifications[] = $make($marc, $marcFuture, Reservation::STATUS_CONFIRMED, (string) $marcFuture['service_key']);

        [$chloe, $chloeColor] = $event('chloe_nguyen', 'color_reservation_completed');
        [, $chloeCorrection] = $event('chloe_nguyen', 'discounted_correction_completed');
        $specifications[] = $make($chloe, $chloeColor, Reservation::STATUS_COMPLETED, (string) $chloeColor['service_key']);
        $specifications[] = $make($chloe, $chloeCorrection, Reservation::STATUS_COMPLETED, (string) $chloeCorrection['service_key']);

        return $specifications;
    }

    private function ensureNarrativeOpening(
        DemoScenarioContext $context,
        TeamMember $member,
        CarbonImmutable $startsAt,
        CarbonImmutable $endsAt,
        int $bufferMinutes,
    ): void {
        AvailabilityException::query()->firstOrCreate(
            [
                'account_id' => $context->owner->id,
                'team_member_id' => $member->id,
                'date' => $startsAt->toDateString(),
                'type' => AvailabilityException::TYPE_OPEN,
            ],
            [
                'start_time' => $startsAt->format('H:i:s'),
                'end_time' => $endsAt->addMinutes($bufferMinutes)->format('H:i:s'),
                'reason' => 'Ouverture spéciale · parcours narratif Studio Naya',
            ],
        );
    }

    /**
     * @param  array<string, mixed>  $left
     * @param  array<string, mixed>  $right
     */
    private function slotsOverlap(array $left, array $right): bool
    {
        if ((int) $left['member']->id !== (int) $right['member']->id) {
            return false;
        }

        $leftStarts = CarbonImmutable::instance($left['starts_at']);
        $rightStarts = CarbonImmutable::instance($right['starts_at']);
        $first = $leftStarts->lte($rightStarts) ? $left : $right;
        $second = $leftStarts->lte($rightStarts) ? $right : $left;
        $buffer = max((int) $first['buffer_minutes'], (int) $second['buffer_minutes']);

        return CarbonImmutable::instance($second['starts_at'])->lt(
            CarbonImmutable::instance($first['ends_at'])->addMinutes($buffer),
        );
    }

    /**
     * @param  array<string, mixed>  $blueprint
     * @param  Collection<int, array<string, mixed>>  $slots
     * @return Collection<int, Reservation>
     */
    private function createNarrativeReservations(
        DemoScenarioContext $context,
        array $blueprint,
        Collection $slots,
    ): Collection {
        $reservations = collect();

        foreach ($slots as $slot) {
            /** @var Customer $customer */
            $customer = $slot['customer'];
            $startsAt = CarbonImmutable::instance($slot['starts_at']);
            $endsAt = CarbonImmutable::instance($slot['ends_at']);
            $createdAt = $startsAt->lt($context->referenceDate)
                ? $startsAt->subDays(14)
                : $context->referenceDate->subDays(2);
            if ($customer->created_at) {
                $customerAvailableAt = CarbonImmutable::instance($customer->created_at)
                    ->setTimezone($context->timezone)
                    ->addHour();
                if ($createdAt->lt($customerAvailableAt)) {
                    $createdAt = $customerAvailableAt;
                }
            }
            if ($createdAt->gte($startsAt)) {
                throw new RuntimeException(sprintf(
                    'Studio Naya narrative reservation [%s/%s] predates its customer.',
                    $slot['story_key'],
                    $slot['event'],
                ));
            }

            $reservation = Reservation::query()->create([
                'account_id' => $context->owner->id,
                'team_member_id' => $slot['member']->id,
                'client_id' => $customer->id,
                'public_booking_link_id' => null,
                'service_id' => $slot['service']->id,
                'status' => $slot['status'],
                'source' => Reservation::SOURCE_STAFF,
                'timezone' => $context->timezone,
                'starts_at' => $startsAt->utc(),
                'ends_at' => $endsAt->utc(),
                'duration_minutes' => $slot['duration_minutes'],
                'buffer_minutes' => $slot['buffer_minutes'],
                'internal_notes' => 'Parcours Studio Naya · '.str_replace('_', ' ', $slot['event']).'.',
                'client_notes' => null,
                'cancelled_at' => null,
                'cancel_reason' => null,
                'created_by_user_id' => $context->owner->id,
                'metadata' => [
                    'scenario_key' => $blueprint['key'],
                    'story_key' => $slot['story_key'],
                    'narrative_event' => $slot['event'],
                    'narrative_assignment' => true,
                    'narrative_offset_days' => $slot['offset_days'],
                    'service_key' => $slot['service_key'],
                    'employee_key' => $slot['member_key'],
                    'preparation_minutes' => $slot['preparation_minutes'],
                    'cleanup_minutes' => $slot['cleanup_minutes'],
                    'secondary_service_keys' => [],
                    'deposit_required' => $slot['story_key'] === 'nadia_pierre'
                        && $slot['status'] === Reservation::STATUS_PENDING,
                ],
            ]);

            $updatedAt = $startsAt->lt($context->referenceDate) ? $endsAt : $createdAt;
            DB::table('reservations')->where('id', $reservation->id)->update([
                'created_at' => $createdAt->utc(),
                'updated_at' => $updatedAt->utc(),
            ]);
            $reservations->push($reservation->fresh());
        }

        return $reservations;
    }

    /**
     * @return array{service_key:string, duration_minutes:int, buffer_minutes:int, preparation_minutes:int, cleanup_minutes:int}
     */
    private function serviceTiming(Product $service): array
    {
        $values = collect((array) $service->tags)
            ->filter(fn (mixed $tag): bool => is_string($tag) && str_contains($tag, ':'))
            ->mapWithKeys(function (string $tag): array {
                [$key, $value] = explode(':', $tag, 2);

                return [$key => $value];
            });

        return [
            'service_key' => (string) $values->get('key', $service->id),
            'duration_minutes' => max(15, (int) $values->get('duration', 60)),
            'buffer_minutes' => max(5, (int) $values->get('buffer-after', 10)),
            'preparation_minutes' => max(0, (int) $values->get('preparation', 0)),
            'cleanup_minutes' => max(0, (int) $values->get('cleanup', 0)),
        ];
    }

    /**
     * @param  Collection<string, Product>  $services
     */
    private function createPublicBookingLink(
        DemoScenarioContext $context,
        Collection $services,
    ): PublicBookingLink {
        $link = PublicBookingLink::query()->create([
            'account_id' => $context->owner->id,
            'name' => 'Réserver chez Studio Naya',
            'slug' => 'studio-naya',
            'description' => 'Réservation publique des services de coiffure, coloration, soins et barbier.',
            'is_active' => true,
            'requires_manual_confirmation' => false,
            'requires_deposit' => true,
            'source' => 'studio_naya_scenario',
            'campaign' => 'always_on_booking',
            'metadata' => [
                'scenario_key' => 'studio_naya_coiffure',
                'accent_color' => '#5B3A70',
            ],
        ]);
        $link->services()->sync($services->where('is_active', true)->pluck('id')->all());

        return $link;
    }
}
