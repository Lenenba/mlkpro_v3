<?php

namespace App\Http\Controllers\Reservation;

use App\Http\Controllers\Controller;
use App\Http\Requests\Reservation\ClientBookingRequest;
use App\Http\Requests\Reservation\ClientRescheduleRequest;
use App\Http\Requests\Reservation\ClientTicketRequest;
use App\Http\Requests\Reservation\ClientWaitlistRequest;
use App\Http\Requests\Reservation\ReservationReviewRequest;
use App\Http\Requests\Reservation\SlotRequest;
use App\Models\ActivityLog;
use App\Models\Customer;
use App\Models\Product;
use App\Models\Reservation;
use App\Models\ReservationQueueItem;
use App\Models\ReservationReview;
use App\Models\ReservationWaitlist;
use App\Models\TeamMember;
use App\Models\User;
use App\Services\ReservationAvailabilityService;
use App\Services\ReservationIntentGuardService;
use App\Services\ReservationNotificationService;
use App\Services\ReservationQueueService;
use App\Support\ReservationPresetResolver;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\URL;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class ClientReservationController extends Controller
{
    public function __construct(
        private readonly ReservationAvailabilityService $availabilityService,
        private readonly ReservationNotificationService $notificationService,
        private readonly ReservationQueueService $queueService,
        private readonly ReservationIntentGuardService $intentGuard
    ) {
    }

    public function book(Request $request)
    {
        $user = $request->user();
        if (!$user) {
            abort(401);
        }

        $this->authorize('create', Reservation::class);
        [$account, $customer] = $this->resolveClientContext($user);

        $teamMembers = TeamMember::query()
            ->forAccount($account->id)
            ->active()
            ->with('user:id,name')
            ->orderBy('id')
            ->get()
            ->map(fn (TeamMember $member) => [
                'id' => $member->id,
                'name' => $member->user?->name ?? 'Member',
                'title' => $member->title,
            ])
            ->values();

        $services = Product::query()
            ->services()
            ->where('user_id', $account->id)
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'name', 'description', 'price', 'item_type']);

        $upcomingReservations = Reservation::query()
            ->forAccount($account->id)
            ->where(function ($query) use ($user, $customer) {
                $query->where('client_user_id', $user->id)
                    ->orWhere('client_id', $customer->id);
            })
            ->whereDate('starts_at', '>=', now()->toDateString())
            ->orderBy('starts_at')
            ->limit(8)
            ->with(['teamMember.user:id,name', 'service:id,name,price', 'review:id,reservation_id,rating,feedback'])
            ->get();

        $settings = $this->availabilityService->resolveSettings($account->id, null);

        return $this->inertiaOrJson('Reservation/ClientBook', [
            'timezone' => $this->availabilityService->timezoneForAccount($account),
            'teamMembers' => $teamMembers,
            'services' => $services,
            'client' => [
                'id' => $customer->id,
                'name' => $customer->company_name
                    ?: trim(($customer->first_name ?? '') . ' ' . ($customer->last_name ?? '')),
                'email' => $customer->email,
                'phone' => $customer->phone,
            ],
            'upcomingReservations' => $upcomingReservations,
            'waitlistEntries' => $this->mapClientWaitlistEntries($account->id, $customer->id, $user->id),
            'queueTickets' => $this->queueService->clientTickets($account->id, $customer->id, $user->id, $settings),
            'settings' => [
                'business_preset' => (string) ($settings['business_preset'] ?? 'service_general'),
                'waitlist_enabled' => (bool) ($settings['waitlist_enabled'] ?? false),
                'queue_mode_enabled' => $this->queueModeEnabled($settings),
                'queue_assignment_mode' => (string) ($settings['queue_assignment_mode'] ?? 'per_staff'),
                'queue_dispatch_mode' => (string) ($settings['queue_dispatch_mode'] ?? 'fifo_with_appointment_priority'),
                'queue_grace_minutes' => (int) ($settings['queue_grace_minutes'] ?? 5),
                'queue_pre_call_threshold' => (int) ($settings['queue_pre_call_threshold'] ?? 2),
                'queue_no_show_on_grace_expiry' => (bool) ($settings['queue_no_show_on_grace_expiry'] ?? false),
                'slot_duration_minutes' => $this->slotDurationMinutes($account->id),
                'kiosk_public_url' => $this->kioskEntryUrl($account->id, $settings),
                'allow_client_cancel' => (bool) $settings['allow_client_cancel'],
                'allow_client_reschedule' => (bool) $settings['allow_client_reschedule'],
                'cancellation_cutoff_hours' => (int) $settings['cancellation_cutoff_hours'],
                'deposit_required' => (bool) ($settings['deposit_required'] ?? false),
                'deposit_amount' => (float) ($settings['deposit_amount'] ?? 0),
                'no_show_fee_enabled' => (bool) ($settings['no_show_fee_enabled'] ?? false),
                'no_show_fee_amount' => (float) ($settings['no_show_fee_amount'] ?? 0),
            ],
        ]);
    }

    public function slots(SlotRequest $request)
    {
        $user = $request->user();
        if (!$user) {
            abort(401);
        }

        $this->authorize('viewAny', Reservation::class);
        [$account] = $this->resolveClientContext($user);
        $validated = $request->validated();

        $durationMinutes = $this->slotDurationMinutes($account->id);

        $result = $this->availabilityService->generateSlots(
            $account->id,
            Carbon::parse($validated['range_start'])->utc(),
            Carbon::parse($validated['range_end'])->utc(),
            $durationMinutes,
            isset($validated['team_member_id']) ? (int) $validated['team_member_id'] : null,
            isset($validated['party_size']) ? (int) $validated['party_size'] : null,
            $validated['resource_filters'] ?? null
        );

        return response()->json([
            'timezone' => $result['timezone'],
            'duration_minutes' => $durationMinutes,
            'slots' => $result['slots'],
        ]);
    }

    public function store(ClientBookingRequest $request)
    {
        $user = $request->user();
        if (!$user) {
            abort(401);
        }

        $this->authorize('create', Reservation::class);
        [$account, $customer] = $this->resolveClientContext($user);
        $validated = $request->validated();
        $settings = $this->availabilityService->resolveSettings($account->id, null);
        $this->intentGuard->ensureCanCreateReservation($account->id, $customer->id, $user->id, $settings);
        $durationMinutes = $this->slotDurationMinutes($account->id);

        $metadata = [
            'contact_name' => $validated['contact_name']
                ?? ($customer->company_name ?: trim(($customer->first_name ?? '') . ' ' . ($customer->last_name ?? ''))),
            'contact_email' => $validated['contact_email'] ?? $customer->email,
            'contact_phone' => $validated['contact_phone'] ?? $customer->phone,
        ];
        if (!empty($validated['party_size'])) {
            $metadata['party_size'] = (int) $validated['party_size'];
        }
        if (!empty($validated['resource_filters']) && is_array($validated['resource_filters'])) {
            $metadata['resource_filters'] = $validated['resource_filters'];
        }
        if (!empty($validated['resource_ids']) && is_array($validated['resource_ids'])) {
            $metadata['resource_ids'] = array_values(array_map('intval', $validated['resource_ids']));
        }

        $reservation = $this->availabilityService->book([
            ...$validated,
            'duration_minutes' => $durationMinutes,
            'account_id' => $account->id,
            'client_id' => $customer->id,
            'client_user_id' => $user->id,
            'source' => Reservation::SOURCE_CLIENT,
            'status' => Reservation::STATUS_PENDING,
            'metadata' => $metadata,
        ], $user);
        $this->notificationService->handleCreated($reservation, $user);

        $reservation->load(['teamMember.user:id,name', 'service:id,name,price', 'review:id,reservation_id,rating,feedback']);

        if ($this->shouldReturnJson($request)) {
            return response()->json([
                'message' => 'Reservation created successfully.',
                'reservation' => $reservation,
            ], 201);
        }

        return redirect()->route('client.reservations.index')->with('success', 'Reservation request submitted.');
    }

    public function index(Request $request)
    {
        $user = $request->user();
        if (!$user) {
            abort(401);
        }

        $this->authorize('viewAny', Reservation::class);
        [$account, $customer] = $this->resolveClientContext($user);

        $filters = $request->only(['status', 'date_from', 'date_to', 'view_mode']);
        $query = Reservation::query()
            ->forAccount($account->id)
            ->where(function ($builder) use ($user, $customer) {
                $builder->where('client_user_id', $user->id)
                    ->orWhere('client_id', $customer->id);
            })
            ->with(['teamMember.user:id,name', 'service:id,name,price', 'review:id,reservation_id,rating,feedback,reviewed_at'])
            ->when($filters['status'] ?? null, fn ($builder, $status) => $builder->where('status', $status))
            ->when($filters['date_from'] ?? null, fn ($builder, $date) => $builder->whereDate('starts_at', '>=', $date))
            ->when($filters['date_to'] ?? null, fn ($builder, $date) => $builder->whereDate('starts_at', '<=', $date));

        $reservations = (clone $query)
            ->orderBy('starts_at')
            ->simplePaginate(20)
            ->withQueryString();

        $events = (clone $query)
            ->whereDate('starts_at', '>=', now()->subDays(7)->toDateString())
            ->whereDate('starts_at', '<=', now()->addDays(35)->toDateString())
            ->orderBy('starts_at')
            ->get()
            ->map(fn (Reservation $reservation) => $this->mapEvent($reservation))
            ->values();

        $stats = [
            'total' => (clone $query)->count(),
            'pending' => (clone $query)->where('status', Reservation::STATUS_PENDING)->count(),
            'confirmed' => (clone $query)->where('status', Reservation::STATUS_CONFIRMED)->count(),
            'cancelled' => (clone $query)->where('status', Reservation::STATUS_CANCELLED)->count(),
            'today' => (clone $query)->whereDate('starts_at', now()->toDateString())->count(),
        ];

        $settings = $this->availabilityService->resolveSettings($account->id, null);

        return $this->inertiaOrJson('Reservation/ClientIndex', [
            'filters' => $filters,
            'reservations' => $reservations,
            'events' => $events,
            'statuses' => Reservation::STATUSES,
            'stats' => $stats,
            'waitlistEntries' => $this->mapClientWaitlistEntries($account->id, $customer->id, $user->id),
            'queueTickets' => $this->queueService->clientTickets($account->id, $customer->id, $user->id, $settings),
            'timezone' => $this->availabilityService->timezoneForAccount($account),
            'settings' => [
                'business_preset' => (string) ($settings['business_preset'] ?? 'service_general'),
                'waitlist_enabled' => (bool) ($settings['waitlist_enabled'] ?? false),
                'queue_mode_enabled' => $this->queueModeEnabled($settings),
                'queue_assignment_mode' => (string) ($settings['queue_assignment_mode'] ?? 'per_staff'),
                'queue_dispatch_mode' => (string) ($settings['queue_dispatch_mode'] ?? 'fifo_with_appointment_priority'),
                'queue_grace_minutes' => (int) ($settings['queue_grace_minutes'] ?? 5),
                'queue_pre_call_threshold' => (int) ($settings['queue_pre_call_threshold'] ?? 2),
                'queue_no_show_on_grace_expiry' => (bool) ($settings['queue_no_show_on_grace_expiry'] ?? false),
                'slot_duration_minutes' => $this->slotDurationMinutes($account->id),
                'kiosk_public_url' => $this->kioskEntryUrl($account->id, $settings),
                'allow_client_cancel' => (bool) $settings['allow_client_cancel'],
                'allow_client_reschedule' => (bool) $settings['allow_client_reschedule'],
                'cancellation_cutoff_hours' => (int) $settings['cancellation_cutoff_hours'],
                'deposit_required' => (bool) ($settings['deposit_required'] ?? false),
                'deposit_amount' => (float) ($settings['deposit_amount'] ?? 0),
                'no_show_fee_enabled' => (bool) ($settings['no_show_fee_enabled'] ?? false),
                'no_show_fee_amount' => (float) ($settings['no_show_fee_amount'] ?? 0),
            ],
        ]);
    }

    public function events(Request $request)
    {
        $user = $request->user();
        if (!$user) {
            abort(401);
        }

        $this->authorize('viewAny', Reservation::class);
        [$account, $customer] = $this->resolveClientContext($user);

        $validated = $request->validate([
            'start' => ['required', 'date'],
            'end' => ['required', 'date', 'after:start'],
            'status' => ['nullable', Rule::in(Reservation::STATUSES)],
        ]);

        $events = Reservation::query()
            ->forAccount($account->id)
            ->where(function ($query) use ($user, $customer) {
                $query->where('client_user_id', $user->id)
                    ->orWhere('client_id', $customer->id);
            })
            ->with(['teamMember.user:id,name', 'service:id,name,price'])
            ->where('starts_at', '<', $validated['end'])
            ->where('ends_at', '>', $validated['start'])
            ->when($validated['status'] ?? null, fn ($builder, $status) => $builder->where('status', $status))
            ->orderBy('starts_at')
            ->get()
            ->map(fn (Reservation $reservation) => $this->mapEvent($reservation))
            ->values();

        return response()->json([
            'events' => $events,
        ]);
    }

    public function cancel(Request $request, Reservation $reservation)
    {
        $user = $request->user();
        if (!$user) {
            abort(401);
        }

        $this->authorize('cancel', $reservation);
        [$account] = $this->resolveClientContext($user);
        if ((int) $reservation->account_id !== (int) $account->id) {
            abort(404);
        }

        if (!$reservation->canBeCancelled()) {
            throw ValidationException::withMessages([
                'reservation' => ['This reservation cannot be cancelled.'],
            ]);
        }

        $settings = $this->availabilityService->resolveSettings($account->id, $reservation->team_member_id);
        if (!$settings['allow_client_cancel']) {
            throw ValidationException::withMessages([
                'reservation' => ['Cancellation is disabled for this company.'],
            ]);
        }

        if (!$this->availabilityService->canClientModify($reservation)) {
            throw ValidationException::withMessages([
                'reservation' => ['This reservation is inside the cancellation cutoff window.'],
            ]);
        }

        $reason = $request->validate([
            'reason' => ['nullable', 'string', 'max:255'],
        ]);

        $reservation->update([
            'status' => Reservation::STATUS_CANCELLED,
            'cancelled_at' => now(),
            'cancelled_by_user_id' => $user->id,
            'cancel_reason' => $reason['reason'] ?? null,
            'metadata' => $this->availabilityService->metadataForStatusTransition($reservation, Reservation::STATUS_CANCELLED),
        ]);
        $this->notificationService->handleCancelled($reservation->fresh(), $user);

        return response()->json([
            'message' => 'Reservation cancelled.',
            'reservation' => $reservation->fresh(['teamMember.user:id,name', 'service:id,name,price', 'review:id,reservation_id,rating,feedback']),
        ]);
    }

    public function reschedule(ClientRescheduleRequest $request, Reservation $reservation)
    {
        $user = $request->user();
        if (!$user) {
            abort(401);
        }

        $this->authorize('reschedule', $reservation);
        [$account] = $this->resolveClientContext($user);
        if ((int) $reservation->account_id !== (int) $account->id) {
            abort(404);
        }

        if (!$reservation->canBeCancelled()) {
            throw ValidationException::withMessages([
                'reservation' => ['This reservation cannot be rescheduled.'],
            ]);
        }

        $settings = $this->availabilityService->resolveSettings($account->id, $reservation->team_member_id);
        if (!$settings['allow_client_reschedule']) {
            throw ValidationException::withMessages([
                'reservation' => ['Rescheduling is disabled for this company.'],
            ]);
        }

        if (!$this->availabilityService->canClientModify($reservation)) {
            throw ValidationException::withMessages([
                'reservation' => ['This reservation is inside the rescheduling cutoff window.'],
            ]);
        }

        $validated = $request->validated();
        $reservation = $this->availabilityService->reschedule($reservation, [
            ...$validated,
            'status' => Reservation::STATUS_PENDING,
            'source' => Reservation::SOURCE_CLIENT,
        ], $user);
        $this->notificationService->handleRescheduled($reservation, $user);

        return response()->json([
            'message' => 'Reservation rescheduled.',
            'reservation' => $reservation->load(['teamMember.user:id,name', 'service:id,name,price', 'review:id,reservation_id,rating,feedback']),
        ]);
    }

    public function waitlistStore(ClientWaitlistRequest $request)
    {
        $user = $request->user();
        if (!$user) {
            abort(401);
        }

        $this->authorize('create', Reservation::class);
        [$account, $customer] = $this->resolveClientContext($user);
        $settings = $this->availabilityService->resolveSettings($account->id, null);
        if (!($settings['waitlist_enabled'] ?? false)) {
            throw ValidationException::withMessages([
                'waitlist' => ['Waitlist is disabled for this company.'],
            ]);
        }

        $validated = $request->validated();
        $durationMinutes = $this->slotDurationMinutes($account->id);
        $waitlist = ReservationWaitlist::query()->create([
            'account_id' => $account->id,
            'client_id' => $customer->id,
            'client_user_id' => $user->id,
            'service_id' => $validated['service_id'] ?? null,
            'team_member_id' => $validated['team_member_id'] ?? null,
            'status' => ReservationWaitlist::STATUS_PENDING,
            'requested_start_at' => Carbon::parse($validated['requested_start_at'])->utc(),
            'requested_end_at' => Carbon::parse($validated['requested_end_at'])->utc(),
            'duration_minutes' => $durationMinutes,
            'party_size' => isset($validated['party_size']) ? (int) $validated['party_size'] : null,
            'notes' => $validated['notes'] ?? null,
            'resource_filters' => $validated['resource_filters'] ?? null,
            'metadata' => [
                'source' => 'client',
                'created_from' => 'client.reservations.book',
            ],
        ]);

        $waitlist->load(['service:id,name', 'teamMember.user:id,name']);

        return response()->json([
            'message' => 'Waitlist entry created.',
            'waitlist' => $this->mapWaitlistEntry($waitlist),
        ], 201);
    }

    public function waitlistCancel(Request $request, ReservationWaitlist $waitlist)
    {
        $user = $request->user();
        if (!$user) {
            abort(401);
        }

        $this->authorize('viewAny', Reservation::class);
        [$account, $customer] = $this->resolveClientContext($user);
        if ((int) $waitlist->account_id !== (int) $account->id) {
            abort(404);
        }
        if (!$this->clientOwnsWaitlist($waitlist, $customer->id, $user->id)) {
            abort(403);
        }
        if (!in_array($waitlist->status, ReservationWaitlist::OPEN_STATUSES, true)) {
            throw ValidationException::withMessages([
                'waitlist' => ['This waitlist entry can no longer be cancelled.'],
            ]);
        }

        $waitlist->update([
            'status' => ReservationWaitlist::STATUS_CANCELLED,
            'cancelled_at' => now('UTC'),
        ]);

        return response()->json([
            'message' => 'Waitlist entry cancelled.',
            'waitlist' => $this->mapWaitlistEntry($waitlist->fresh(['service:id,name', 'teamMember.user:id,name'])),
        ]);
    }

    public function ticketStore(ClientTicketRequest $request)
    {
        $user = $request->user();
        if (!$user) {
            abort(401);
        }

        $this->authorize('create', Reservation::class);
        [$account, $customer] = $this->resolveClientContext($user);
        $settings = $this->availabilityService->resolveSettings($account->id, null);
        $this->ensureQueueModeEnabled($settings);

        $validated = $request->validated();
        $item = $this->queueService->createTicket($account->id, [
            ...$validated,
            'client_id' => $customer->id,
            'client_user_id' => $user->id,
            'source' => $validated['source'] ?? 'client',
        ], $user, $settings);

        return response()->json([
            'message' => 'Queue ticket created.',
            'ticket' => $this->queueService->clientTickets($account->id, $customer->id, $user->id, $settings, 1)[0] ?? null,
            'queue_item_id' => $item->id,
        ], 201);
    }

    public function ticketCancel(Request $request, ReservationQueueItem $ticket)
    {
        $user = $request->user();
        if (!$user) {
            abort(401);
        }

        $this->authorize('viewAny', Reservation::class);
        [$account, $customer] = $this->resolveClientContext($user);

        if ((int) $ticket->account_id !== (int) $account->id || $ticket->item_type !== ReservationQueueItem::TYPE_TICKET) {
            abort(404);
        }
        if (!$this->clientOwnsQueueTicket($ticket, $customer->id, $user->id)) {
            abort(403);
        }

        $settings = $this->availabilityService->resolveSettings($account->id, null);
        $this->ensureQueueModeEnabled($settings);
        $this->queueService->transition($ticket, 'cancel', $user, $settings, [
            'by_client' => true,
        ]);

        return response()->json([
            'message' => 'Queue ticket cancelled.',
            'ticket' => $this->mapClientQueueTicket($ticket->fresh(['service:id,name', 'teamMember.user:id,name'])),
        ]);
    }

    public function ticketStillHere(Request $request, ReservationQueueItem $ticket)
    {
        $user = $request->user();
        if (!$user) {
            abort(401);
        }

        $this->authorize('viewAny', Reservation::class);
        [$account, $customer] = $this->resolveClientContext($user);

        if ((int) $ticket->account_id !== (int) $account->id || $ticket->item_type !== ReservationQueueItem::TYPE_TICKET) {
            abort(404);
        }
        if (!$this->clientOwnsQueueTicket($ticket, $customer->id, $user->id)) {
            abort(403);
        }

        $settings = $this->availabilityService->resolveSettings($account->id, null);
        $this->ensureQueueModeEnabled($settings);
        $this->queueService->transition($ticket, 'still_here', $user, $settings);

        return response()->json([
            'message' => 'Queue presence confirmed.',
            'ticket' => $this->mapClientQueueTicket($ticket->fresh(['service:id,name', 'teamMember.user:id,name'])),
        ]);
    }

    public function review(ReservationReviewRequest $request, Reservation $reservation)
    {
        $user = $request->user();
        if (!$user) {
            abort(401);
        }

        $this->authorize('review', $reservation);
        [$account, $customer] = $this->resolveClientContext($user);
        if ((int) $reservation->account_id !== (int) $account->id) {
            abort(404);
        }

        if ($reservation->status !== Reservation::STATUS_COMPLETED || !$reservation->ends_at || $reservation->ends_at->isFuture()) {
            throw ValidationException::withMessages([
                'reservation' => ['A review can only be submitted after the reservation is completed.'],
            ]);
        }

        $validated = $request->validated();
        $review = ReservationReview::query()->updateOrCreate(
            ['reservation_id' => $reservation->id],
            [
                'account_id' => $account->id,
                'client_id' => $customer->id,
                'client_user_id' => $user->id,
                'rating' => (int) $validated['rating'],
                'feedback' => $validated['feedback'] ?? null,
                'reviewed_at' => now(),
            ]
        );

        ActivityLog::record($user, $review, 'created', [
            'reservation_id' => $reservation->id,
            'rating' => $review->rating,
        ], 'Reservation reviewed by client');

        $this->notificationService->handleReviewSubmitted($review, $user);

        return response()->json([
            'message' => 'Reservation review submitted.',
            'review' => [
                'id' => $review->id,
                'rating' => $review->rating,
                'feedback' => $review->feedback,
                'reviewed_at' => $review->reviewed_at?->toIso8601String(),
            ],
        ], 201);
    }

    public function kiosk(Request $request)
    {
        $user = $request->user();
        if (!$user) {
            abort(401);
        }

        $this->authorize('viewAny', Reservation::class);
        [$account] = $this->resolveClientContext($user);
        $settings = $this->availabilityService->resolveSettings($account->id, null);

        if (!$this->queueModeEnabled($settings)) {
            return redirect()->route('client.reservations.index')
                ->with('warning', 'Kiosk is unavailable for this company.');
        }

        $kioskEnabled = (bool) data_get($account->company_notification_settings, 'reservations.kiosk_enabled', true);
        if (!$kioskEnabled) {
            return redirect()->route('client.reservations.index')
                ->with('warning', 'Kiosk is disabled for this company.');
        }

        $publicUrl = $this->kioskSignedPublicUrl($account->id);
        if (!$publicUrl) {
            return redirect()->route('client.reservations.index')
                ->with('warning', 'Kiosk link is unavailable right now.');
        }

        return redirect()->to($publicUrl);
    }

    private function mapClientWaitlistEntries(int $accountId, int $customerId, int $clientUserId): array
    {
        return ReservationWaitlist::query()
            ->forAccount($accountId)
            ->where(function ($query) use ($customerId, $clientUserId) {
                $query->where('client_user_id', $clientUserId)
                    ->orWhere('client_id', $customerId);
            })
            ->with(['service:id,name', 'teamMember.user:id,name'])
            ->orderByDesc('created_at')
            ->limit(20)
            ->get()
            ->map(fn (ReservationWaitlist $waitlist) => $this->mapWaitlistEntry($waitlist))
            ->values()
            ->all();
    }

    private function mapWaitlistEntry(ReservationWaitlist $waitlist): array
    {
        return [
            'id' => $waitlist->id,
            'status' => $waitlist->status,
            'service_id' => $waitlist->service_id,
            'service_name' => $waitlist->service?->name,
            'team_member_id' => $waitlist->team_member_id,
            'team_member_name' => $waitlist->teamMember?->user?->name,
            'requested_start_at' => $waitlist->requested_start_at?->toIso8601String(),
            'requested_end_at' => $waitlist->requested_end_at?->toIso8601String(),
            'duration_minutes' => (int) ($waitlist->duration_minutes ?? 0),
            'party_size' => $waitlist->party_size,
            'notes' => $waitlist->notes,
            'resource_filters' => $waitlist->resource_filters,
            'can_cancel' => in_array($waitlist->status, ReservationWaitlist::OPEN_STATUSES, true),
            'created_at' => $waitlist->created_at?->toIso8601String(),
        ];
    }

    private function clientOwnsWaitlist(ReservationWaitlist $waitlist, int $customerId, int $clientUserId): bool
    {
        if ($waitlist->client_user_id) {
            return (int) $waitlist->client_user_id === $clientUserId;
        }

        return (int) $waitlist->client_id === $customerId;
    }

    private function mapClientQueueTicket(ReservationQueueItem $ticket): array
    {
        return [
            'id' => $ticket->id,
            'queue_number' => $ticket->queue_number,
            'status' => $ticket->status,
            'service_name' => $ticket->service?->name,
            'team_member_name' => $ticket->teamMember?->user?->name,
            'position' => $ticket->position,
            'eta_minutes' => $ticket->eta_minutes,
            'call_expires_at' => $ticket->call_expires_at?->toIso8601String(),
            'created_at' => $ticket->created_at?->toIso8601String(),
            'can_cancel' => in_array($ticket->status, ReservationQueueItem::ACTIVE_STATUSES, true),
            'can_still_here' => in_array($ticket->status, [
                ReservationQueueItem::STATUS_CHECKED_IN,
                ReservationQueueItem::STATUS_PRE_CALLED,
                ReservationQueueItem::STATUS_CALLED,
                ReservationQueueItem::STATUS_SKIPPED,
            ], true),
        ];
    }

    private function clientOwnsQueueTicket(ReservationQueueItem $ticket, int $customerId, int $clientUserId): bool
    {
        if ($ticket->client_user_id) {
            return (int) $ticket->client_user_id === $clientUserId;
        }

        return (int) $ticket->client_id === $customerId;
    }

    private function queueFeaturesAvailable(array $settings): bool
    {
        return ReservationPresetResolver::queueFeaturesEnabled((string) ($settings['business_preset'] ?? null));
    }

    private function slotDurationMinutes(int $accountId): int
    {
        $settings = $this->availabilityService->resolveSettings($accountId, null);
        return max(5, min(240, (int) ($settings['slot_interval_minutes'] ?? 60)));
    }

    private function queueModeEnabled(array $settings): bool
    {
        return $this->queueFeaturesAvailable($settings)
            && (bool) ($settings['queue_mode_enabled'] ?? false);
    }

    private function ensureQueueModeEnabled(array $settings): void
    {
        if (!$this->queueFeaturesAvailable($settings)) {
            throw ValidationException::withMessages([
                'queue' => ['Hybrid queue is only available for salon businesses.'],
            ]);
        }

        if (!($settings['queue_mode_enabled'] ?? false)) {
            throw ValidationException::withMessages([
                'queue' => ['Queue mode is disabled for this company.'],
            ]);
        }
    }

    private function kioskEntryUrl(int $accountId, array $settings): ?string
    {
        if (!$this->queueModeEnabled($settings)) {
            return null;
        }

        if (Route::has('client.reservations.kiosk')) {
            return route('client.reservations.kiosk');
        }

        return $this->kioskSignedPublicUrl($accountId);
    }

    private function kioskSignedPublicUrl(int $accountId): ?string
    {
        if (!Route::has('public.kiosk.reservations.show')) {
            return null;
        }

        return URL::signedRoute('public.kiosk.reservations.show', ['account' => $accountId]);
    }

    private function resolveClientContext(User $user): array
    {
        $customer = $user->relationLoaded('customerProfile')
            ? $user->customerProfile
            : $user->customerProfile()->first();
        if (!$customer instanceof Customer) {
            abort(403);
        }

        $account = User::query()->find($customer->user_id);
        if (!$account) {
            abort(404);
        }

        return [$account, $customer];
    }

    private function mapEvent(Reservation $reservation): array
    {
        $serviceLabel = $reservation->service?->name ?: 'Reservation';
        $memberLabel = $reservation->teamMember?->user?->name ?: 'Team';

        return [
            'id' => $reservation->id,
            'title' => trim($serviceLabel . ' · ' . $memberLabel),
            'start' => $reservation->starts_at?->toIso8601String(),
            'end' => $reservation->ends_at?->toIso8601String(),
            'classNames' => ['reservation-event', 'status-' . $reservation->status],
            'extendedProps' => [
                'status' => $reservation->status,
                'team_member_name' => $memberLabel,
                'service_name' => $serviceLabel,
                'client_notes' => $reservation->client_notes,
                'source' => $reservation->source,
            ],
        ];
    }
}
