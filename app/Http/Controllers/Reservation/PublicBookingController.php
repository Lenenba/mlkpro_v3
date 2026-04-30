<?php

namespace App\Http\Controllers\Reservation;

use App\Http\Controllers\Controller;
use App\Models\PublicBookingLink;
use App\Models\User;
use App\Services\ReservationAvailabilityService;
use App\Services\Reservations\PublicBookingService;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

class PublicBookingController extends Controller
{
    public function __construct(
        private readonly PublicBookingService $publicBookingService,
        private readonly ReservationAvailabilityService $availabilityService
    ) {}

    public function show(Request $request, string $company, string $slug)
    {
        $account = $this->resolveAccount($company);
        $link = $this->resolveLink($account, $slug);
        $this->publicBookingService->assertAvailable($account, $link);
        $link->load(['services' => fn ($query) => $query
            ->where('products.is_active', true)
            ->orderBy('products.name')]);

        return Inertia::render('Public/PublicBooking', [
            'company' => [
                'id' => (int) $account->id,
                'name' => $account->company_name ?: $account->name,
                'logo_url' => $account->company_logo_url,
                'phone' => $account->phone_number,
            ],
            'link' => [
                'id' => (int) $link->id,
                'name' => (string) $link->name,
                'slug' => (string) $link->slug,
                'description' => $link->description,
                'requires_manual_confirmation' => (bool) $link->requires_manual_confirmation,
                'requires_deposit' => (bool) $link->requires_deposit,
            ],
            'services' => $link->services
                ->map(function ($service) use ($account) {
                    $durationMinutes = $this->availabilityService->resolveDurationMinutes(
                        (int) $account->id,
                        (int) $service->id,
                        null
                    );

                    return [
                        'id' => (int) $service->id,
                        'name' => (string) $service->name,
                        'description' => $service->description,
                        'price' => (float) ($service->price ?? 0),
                        'currency_code' => $service->currency_code,
                        'duration_minutes' => $durationMinutes,
                    ];
                })
                ->values(),
            'settings' => [
                'timezone' => $this->availabilityService->timezoneForAccount($account),
            ],
            'endpoints' => [
                'slots' => route('public.booking.slots', ['company' => $company, 'slug' => $slug]),
                'store' => route('public.booking.store', ['company' => $company, 'slug' => $slug]),
            ],
        ]);
    }

    public function slots(Request $request, string $company, string $slug)
    {
        $account = $this->resolveAccount($company);
        $link = $this->resolveLink($account, $slug);
        $this->publicBookingService->assertAvailable($account, $link);

        $validated = $request->validate([
            'service_id' => ['required', 'integer'],
            'team_member_id' => [
                'nullable',
                'integer',
                Rule::exists('team_members', 'id')->where(fn ($query) => $query
                    ->where('account_id', (int) $account->id)
                    ->where('is_active', true)),
            ],
            'range_start' => ['required', 'date'],
            'range_end' => ['required', 'date', 'after:range_start'],
            'duration_minutes' => ['nullable', 'integer', 'min:5', 'max:720'],
            'party_size' => ['nullable', 'integer', 'min:1', 'max:500'],
        ]);

        $this->ensureDateRangeIsReasonable($validated['range_start'], $validated['range_end']);

        return response()->json($this->publicBookingService->slots($link, $validated));
    }

    public function store(Request $request, string $company, string $slug)
    {
        $account = $this->resolveAccount($company);
        $link = $this->resolveLink($account, $slug);
        $this->publicBookingService->assertAvailable($account, $link);

        $validated = $request->validate([
            'website' => ['prohibited'],
            'service_id' => ['required', 'integer'],
            'team_member_id' => [
                'nullable',
                'integer',
                Rule::exists('team_members', 'id')->where(fn ($query) => $query
                    ->where('account_id', (int) $account->id)
                    ->where('is_active', true)),
            ],
            'assignment_mode' => ['nullable', 'string', Rule::in(['auto', 'specific'])],
            'starts_at' => ['required', 'date'],
            'ends_at' => ['nullable', 'date', 'after:starts_at'],
            'duration_minutes' => ['nullable', 'integer', 'min:5', 'max:720'],
            'party_size' => ['nullable', 'integer', 'min:1', 'max:500'],
            'timezone' => ['nullable', 'timezone'],
            'resource_ids' => ['nullable', 'array'],
            'resource_ids.*' => ['integer'],
            'first_name' => ['required', 'string', 'max:120'],
            'last_name' => ['required', 'string', 'max:120'],
            'phone' => ['required', 'string', 'max:50'],
            'email' => ['required', 'email', 'max:255'],
            'message' => ['nullable', 'string', 'max:2000'],
        ]);

        if (Carbon::parse((string) $validated['starts_at'])->utc()->lt(now('UTC')->addMinutes(5))) {
            throw ValidationException::withMessages([
                'starts_at' => ['Please select a future time slot.'],
            ]);
        }

        $result = $this->publicBookingService->createBooking($link, $validated, $account);

        return response()->json([
            'message' => $link->requires_manual_confirmation
                ? 'Booking request sent. The company will confirm the appointment.'
                : 'Booking confirmed.',
            'reservation' => [
                'id' => (int) $result['reservation']->id,
                'status' => (string) $result['reservation']->status,
                'starts_at' => $result['reservation']->starts_at?->toIso8601String(),
                'ends_at' => $result['reservation']->ends_at?->toIso8601String(),
                'duration_minutes' => (int) ($result['reservation']->duration_minutes ?? 0),
                'team_member_id' => (int) ($result['reservation']->team_member_id ?? 0),
                'team_member_name' => $result['reservation']->teamMember?->user?->name,
                'service_name' => $result['reservation']->service?->name,
            ],
            'prospect_id' => (int) $result['prospect']->id,
        ], 201);
    }

    private function resolveAccount(string $company): User
    {
        $company = trim($company);
        $query = User::query();

        $account = is_numeric($company)
            ? $query->whereKey((int) $company)->first()
            : $query->where('company_slug', $company)->first();

        if (! $account) {
            abort(404);
        }

        return $account;
    }

    private function resolveLink(User $account, string $slug): PublicBookingLink
    {
        $link = PublicBookingLink::query()
            ->forAccount((int) $account->id)
            ->where('slug', $slug)
            ->first();

        if (! $link) {
            abort(404);
        }

        return $link;
    }

    private function ensureDateRangeIsReasonable(string $start, string $end): void
    {
        $startDate = Carbon::parse($start);
        $endDate = Carbon::parse($end);

        if ($startDate->diffInDays($endDate) > 60) {
            throw ValidationException::withMessages([
                'range_end' => ['Please request a date range of 60 days or less.'],
            ]);
        }
    }
}
