<?php

namespace App\Http\Controllers\Reservation;

use App\Http\Controllers\Controller;
use App\Http\Requests\Reservation\ClientBookingRequest;
use App\Http\Requests\Reservation\ClientRescheduleRequest;
use App\Http\Requests\Reservation\ReservationReviewRequest;
use App\Http\Requests\Reservation\SlotRequest;
use App\Models\ActivityLog;
use App\Models\Customer;
use App\Models\Product;
use App\Models\Reservation;
use App\Models\ReservationReview;
use App\Models\TeamMember;
use App\Models\User;
use App\Services\ReservationAvailabilityService;
use App\Services\ReservationNotificationService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class ClientReservationController extends Controller
{
    public function __construct(
        private readonly ReservationAvailabilityService $availabilityService,
        private readonly ReservationNotificationService $notificationService
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
            'settings' => [
                'allow_client_cancel' => (bool) $settings['allow_client_cancel'],
                'allow_client_reschedule' => (bool) $settings['allow_client_reschedule'],
                'cancellation_cutoff_hours' => (int) $settings['cancellation_cutoff_hours'],
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

        $durationMinutes = $this->availabilityService->resolveDurationMinutes(
            $account->id,
            isset($validated['service_id']) ? (int) $validated['service_id'] : null,
            isset($validated['duration_minutes']) ? (int) $validated['duration_minutes'] : null
        );

        $result = $this->availabilityService->generateSlots(
            $account->id,
            \Illuminate\Support\Carbon::parse($validated['range_start'])->utc(),
            \Illuminate\Support\Carbon::parse($validated['range_end'])->utc(),
            $durationMinutes,
            isset($validated['team_member_id']) ? (int) $validated['team_member_id'] : null
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

        $reservation = $this->availabilityService->book([
            ...$validated,
            'account_id' => $account->id,
            'client_id' => $customer->id,
            'client_user_id' => $user->id,
            'source' => Reservation::SOURCE_CLIENT,
            'status' => Reservation::STATUS_PENDING,
            'metadata' => [
                'contact_name' => $validated['contact_name'] ?? ($customer->company_name ?: trim(($customer->first_name ?? '') . ' ' . ($customer->last_name ?? ''))),
                'contact_email' => $validated['contact_email'] ?? $customer->email,
                'contact_phone' => $validated['contact_phone'] ?? $customer->phone,
            ],
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
            'timezone' => $this->availabilityService->timezoneForAccount($account),
            'settings' => [
                'allow_client_cancel' => (bool) $settings['allow_client_cancel'],
                'allow_client_reschedule' => (bool) $settings['allow_client_reschedule'],
                'cancellation_cutoff_hours' => (int) $settings['cancellation_cutoff_hours'],
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
