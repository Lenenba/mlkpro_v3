<?php

namespace App\Http\Controllers\Reservation;

use App\Http\Controllers\Controller;
use App\Models\Reservation;
use App\Models\User;
use App\Services\Reservations\PublicBookingConversionService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class PublicBookingConversionController extends Controller
{
    public function __construct(
        private readonly PublicBookingConversionService $conversionService
    ) {}

    public function show(Request $request, Reservation $reservation)
    {
        $account = $this->resolveAccount($request);
        $this->authorize('view', $reservation);
        $this->assertReservationBelongsToAccount($reservation, $account);
        $reservation->loadMissing('prospect:id,contact_name,contact_email,contact_phone,meta,customer_id,converted_customer_id,converted_at');
        $prospect = $reservation->prospect;

        return response()->json([
            'matches' => $prospect ? $this->conversionService->customerMatches($reservation) : [],
            'preview' => $prospect ? [
                'contact_name' => $prospect->contact_name,
                'contact_email' => $prospect->contact_email,
                'contact_phone' => $prospect->contact_phone,
            ] : null,
            'already_converted' => (bool) ($prospect?->customer_id || $reservation->client_id),
        ]);
    }

    public function store(Request $request, Reservation $reservation)
    {
        $account = $this->resolveAccount($request);
        $this->authorize('update', $reservation);
        $this->assertReservationBelongsToAccount($reservation, $account);
        $reservation->loadMissing('prospect');

        $validated = $request->validate([
            'mode' => ['required', 'string', Rule::in(['link_existing', 'create_new'])],
            'customer_id' => [
                'nullable',
                'integer',
                Rule::requiredIf(fn (): bool => $request->input('mode') === 'link_existing'),
                Rule::exists('customers', 'id')->where(fn ($query) => $query->where('user_id', (int) $account->id)),
            ],
            'contact_name' => ['nullable', 'string', 'max:255'],
            'contact_email' => ['nullable', 'email', 'max:255'],
            'contact_phone' => ['nullable', 'string', 'max:50'],
            'company_name' => ['nullable', 'string', 'max:255'],
            'street1' => ['nullable', 'string', 'max:255'],
            'street2' => ['nullable', 'string', 'max:255'],
            'city' => ['nullable', 'string', 'max:255'],
            'state' => ['nullable', 'string', 'max:255'],
            'postal_code' => ['nullable', 'string', 'max:50'],
            'country' => ['nullable', 'string', 'max:255'],
        ]);

        $result = $this->conversionService->convert($reservation, $validated, $request->user());

        return response()->json([
            'message' => 'Public booking prospect converted to customer.',
            'customer' => $result['customer'],
            'reservation' => $result['reservation'],
            'prospect' => $result['prospect'],
            'matches' => $result['matches'],
        ]);
    }

    private function resolveAccount(Request $request): User
    {
        $user = $request->user();
        if (! $user) {
            abort(401);
        }

        $accountId = $user->accountOwnerId();
        $account = $accountId === $user->id
            ? $user
            : User::query()->find($accountId);

        if (! $account) {
            abort(404);
        }

        return $account;
    }

    private function assertReservationBelongsToAccount(Reservation $reservation, User $account): void
    {
        if ((int) $reservation->account_id !== (int) $account->id) {
            abort(404);
        }
    }
}
