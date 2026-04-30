<?php

namespace App\Services\Reservations;

use App\Models\Product;
use App\Models\PublicBookingLink;
use App\Models\Request as LeadRequest;
use App\Models\Reservation;
use App\Models\User;
use App\Notifications\ReservationDatabaseNotification;
use App\Services\CompanyFeatureService;
use App\Services\ReservationAvailabilityService;
use App\Support\LocalePreference;
use App\Support\NotificationDispatcher;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class PublicBookingService
{
    public function __construct(
        private readonly CompanyFeatureService $featureService,
        private readonly ReservationAvailabilityService $availabilityService
    ) {}

    public function assertAvailable(User $account, PublicBookingLink $link): void
    {
        if ((int) $link->account_id !== (int) $account->id) {
            abort(404);
        }

        if ($account->isSuspended() || ! $this->featureService->hasFeature($account, 'reservations')) {
            abort(404);
        }

        if (! $link->isAvailable()) {
            abort(404);
        }
    }

    public function slots(PublicBookingLink $link, array $validated): array
    {
        $accountId = (int) $link->account_id;
        $service = $this->resolveAllowedService($link, (int) $validated['service_id']);
        $durationMinutes = $this->availabilityService->resolveDurationMinutes(
            $accountId,
            (int) $service->id,
            isset($validated['duration_minutes']) ? (int) $validated['duration_minutes'] : null
        );

        $result = $this->availabilityService->generateSlots(
            $accountId,
            Carbon::parse($validated['range_start'])->utc(),
            Carbon::parse($validated['range_end'])->utc(),
            $durationMinutes,
            isset($validated['team_member_id']) ? (int) $validated['team_member_id'] : null,
            isset($validated['party_size']) ? (int) $validated['party_size'] : null,
            null
        );
        $slots = collect($result['slots']);

        return [
            'timezone' => $result['timezone'],
            'duration_minutes' => $durationMinutes,
            'available_dates' => $slots
                ->pluck('date')
                ->filter()
                ->unique()
                ->sort()
                ->values()
                ->all(),
            'team_members' => $slots
                ->groupBy('team_member_id')
                ->map(fn ($memberSlots, $id) => [
                    'id' => (int) $id,
                    'name' => (string) ($memberSlots->first()['team_member_name'] ?? 'Member'),
                    'slot_count' => $memberSlots->count(),
                    'first_available_at' => $memberSlots->min('starts_at'),
                ])
                ->sortBy('name')
                ->values()
                ->all(),
            'slots' => $slots->values()->all(),
        ];
    }

    /**
     * @return array{reservation: Reservation, prospect: LeadRequest}
     */
    public function createBooking(PublicBookingLink $link, array $validated, User $account): array
    {
        $service = $this->resolveAllowedService($link, (int) $validated['service_id']);
        $slot = $this->resolveAvailableBookingSlot($link, $service, $validated, $account);
        $durationMinutes = $this->availabilityService->resolveDurationMinutes(
            (int) $account->id,
            (int) $service->id,
            isset($validated['duration_minutes']) ? (int) $validated['duration_minutes'] : null
        );
        $assignmentMode = ! empty($validated['team_member_id']) ? 'specific' : 'auto';
        $validated['team_member_id'] = (int) $slot['team_member_id'];
        $validated['starts_at'] = (string) $slot['starts_at'];
        $validated['ends_at'] = (string) $slot['ends_at'];
        $validated['duration_minutes'] = $durationMinutes;
        $validated['assignment_mode'] = $validated['assignment_mode'] ?? $assignmentMode;
        if (empty($validated['resource_ids']) && ! empty($slot['resource_id'])) {
            $validated['resource_ids'] = [(int) $slot['resource_id']];
        }

        $startsAt = Carbon::parse((string) $validated['starts_at'])->utc();
        $status = $link->requires_manual_confirmation
            ? Reservation::STATUS_PENDING
            : Reservation::STATUS_CONFIRMED;
        $publicStatus = $status === Reservation::STATUS_CONFIRMED
            ? LeadRequest::PUBLIC_STATUS_BOOKING_CONFIRMED
            : LeadRequest::PUBLIC_STATUS_BOOKING_REQUESTED;
        $contactName = $this->contactName($validated);

        $result = DB::transaction(function () use ($account, $contactName, $link, $publicStatus, $service, $status, $startsAt, $validated) {
            $prospect = LeadRequest::query()->create([
                'user_id' => (int) $account->id,
                'public_booking_link_id' => (int) $link->id,
                'channel' => Reservation::SOURCE_PUBLIC_BOOKING,
                'status' => LeadRequest::STATUS_NEW,
                'status_updated_at' => now(),
                'last_activity_at' => now(),
                'service_type' => (string) $service->name,
                'title' => 'Public booking - '.$service->name,
                'description' => $validated['message'] ?? null,
                'contact_name' => $contactName,
                'contact_email' => strtolower(trim((string) $validated['email'])),
                'contact_phone' => trim((string) $validated['phone']),
                'meta' => [
                    LeadRequest::PUBLIC_BOOKING_META_KEY => [
                        'status' => $publicStatus,
                        'link_id' => (int) $link->id,
                        'link_name' => (string) $link->name,
                        'service_id' => (int) $service->id,
                        'service_name' => (string) $service->name,
                        'starts_at' => $startsAt->toIso8601String(),
                        'team_member_id' => (int) $validated['team_member_id'],
                        'team_member_name' => $slot['team_member_name'] ?? null,
                        'assignment_mode' => $validated['assignment_mode'],
                        'source' => $link->source,
                        'campaign' => $link->campaign,
                        'first_name' => trim((string) $validated['first_name']),
                        'last_name' => trim((string) $validated['last_name']),
                        'submitted_at' => now('UTC')->toIso8601String(),
                    ],
                ],
            ]);

            $reservation = $this->availabilityService->book([
                'account_id' => (int) $account->id,
                'team_member_id' => (int) $validated['team_member_id'],
                'service_id' => (int) $service->id,
                'prospect_id' => (int) $prospect->id,
                'public_booking_link_id' => (int) $link->id,
                'starts_at' => $validated['starts_at'],
                'ends_at' => $validated['ends_at'] ?? null,
                'duration_minutes' => $validated['duration_minutes'] ?? null,
                'timezone' => $validated['timezone'] ?? $this->availabilityService->timezoneForAccount($account),
                'party_size' => $validated['party_size'] ?? null,
                'resource_ids' => $validated['resource_ids'] ?? [],
                'status' => $status,
                'source' => Reservation::SOURCE_PUBLIC_BOOKING,
                'client_notes' => $validated['message'] ?? null,
                'metadata' => [
                    'public_booking' => [
                        'link_id' => (int) $link->id,
                        'link_name' => (string) $link->name,
                        'prospect_id' => (int) $prospect->id,
                        'contact_name' => $contactName,
                        'contact_email' => strtolower(trim((string) $validated['email'])),
                        'contact_phone' => trim((string) $validated['phone']),
                        'team_member_id' => (int) $validated['team_member_id'],
                        'team_member_name' => $slot['team_member_name'] ?? null,
                        'assignment_mode' => $validated['assignment_mode'],
                        'source' => $link->source,
                        'campaign' => $link->campaign,
                        'requires_manual_confirmation' => (bool) $link->requires_manual_confirmation,
                    ],
                ],
            ], $account);

            return [
                'prospect' => $prospect,
                'reservation' => $reservation->fresh([
                    'teamMember.user:id,name,email',
                    'service:id,name,price',
                    'prospect:id,contact_name,contact_email,contact_phone,status,meta',
                    'publicBookingLink:id,name,slug',
                ]),
            ];
        });

        $this->notifyTenant($account, $link, $result['reservation']);

        return $result;
    }

    /**
     * @return array<string, mixed>
     */
    private function resolveAvailableBookingSlot(PublicBookingLink $link, Product $service, array $validated, User $account): array
    {
        $accountId = (int) $account->id;
        $startsAt = Carbon::parse((string) $validated['starts_at'])->utc();
        $timezone = $this->availabilityService->timezoneForAccount($account);
        $localDay = $startsAt->copy()->setTimezone($timezone);
        $requestedTeamMemberId = ! empty($validated['team_member_id'])
            ? (int) $validated['team_member_id']
            : null;
        $durationMinutes = $this->availabilityService->resolveDurationMinutes(
            $accountId,
            (int) $service->id,
            isset($validated['duration_minutes']) ? (int) $validated['duration_minutes'] : null
        );

        $result = $this->availabilityService->generateSlots(
            $accountId,
            $localDay->copy()->startOfDay()->utc(),
            $localDay->copy()->endOfDay()->utc(),
            $durationMinutes,
            $requestedTeamMemberId,
            isset($validated['party_size']) ? (int) $validated['party_size'] : null,
            null
        );

        $slot = collect($result['slots'])
            ->filter(fn (array $candidate) => Carbon::parse((string) $candidate['starts_at'])->utc()->equalTo($startsAt))
            ->sortBy([
                ['team_member_name', 'asc'],
                ['team_member_id', 'asc'],
            ])
            ->first();

        if (! $slot) {
            throw ValidationException::withMessages([
                'starts_at' => ['Ce creneau n est plus disponible. Choisissez une autre heure ou une autre personne.'],
            ]);
        }

        return $slot;
    }

    private function resolveAllowedService(PublicBookingLink $link, int $serviceId): Product
    {
        $service = $link->services()
            ->where('products.id', $serviceId)
            ->where('products.user_id', (int) $link->account_id)
            ->where('products.item_type', Product::ITEM_TYPE_SERVICE)
            ->where('products.is_active', true)
            ->first(['products.id', 'products.name', 'products.price', 'products.user_id', 'products.item_type']);

        if (! $service) {
            throw ValidationException::withMessages([
                'service_id' => ['Selected service is not available through this booking link.'],
            ]);
        }

        return $service;
    }

    private function notifyTenant(User $account, PublicBookingLink $link, Reservation $reservation): void
    {
        $reservation->loadMissing(['teamMember.user:id,name,email']);
        $isFr = str_starts_with(LocalePreference::forUser($account), 'fr');
        $title = $isFr ? 'Nouvelle reservation publique' : 'New public booking';
        $message = ($isFr ? 'Nouvelle reservation publique recue depuis ' : 'New public booking received from ')
            .$link->name.'.';
        $actionUrl = route('reservation.index', [
            'date_from' => $reservation->starts_at?->toDateString(),
            'date_to' => $reservation->starts_at?->toDateString(),
            'per_page' => 50,
            'view_mode' => 'list',
            'reservation_id' => $reservation->id,
        ]);

        $recipients = collect([$account, $reservation->teamMember?->user])
            ->filter(fn ($user) => $user instanceof User)
            ->unique('id');

        foreach ($recipients as $recipient) {
            NotificationDispatcher::send($recipient, new ReservationDatabaseNotification([
                'title' => $title,
                'message' => $message,
                'event' => 'public_booking_received',
                'action_url' => $actionUrl,
                'reservation_id' => $reservation->id,
                'prospect_id' => $reservation->prospect_id,
                'public_booking_link_id' => $link->id,
                'status' => $reservation->status,
                'starts_at' => $reservation->starts_at?->toIso8601String(),
            ]), [
                'reservation_id' => $reservation->id,
                'prospect_id' => $reservation->prospect_id,
                'public_booking_link_id' => $link->id,
                'event' => 'public_booking_received',
            ]);
        }
    }

    private function contactName(array $validated): string
    {
        return trim(implode(' ', array_filter([
            trim((string) $validated['first_name']),
            trim((string) $validated['last_name']),
        ])));
    }
}
