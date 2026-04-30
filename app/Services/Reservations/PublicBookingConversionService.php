<?php

namespace App\Services\Reservations;

use App\Models\Customer;
use App\Models\Request as LeadRequest;
use App\Models\Reservation;
use App\Models\User;
use App\Services\Prospects\ProspectConversionService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class PublicBookingConversionService
{
    public function __construct(
        private readonly ProspectConversionService $prospectConversionService
    ) {}

    /**
     * @return array<int, array<string, mixed>>
     */
    public function customerMatches(Reservation $reservation): array
    {
        $prospect = $this->prospectFor($reservation);
        $email = $this->normalizeEmail($prospect->contact_email);
        $phone = $this->normalizePhone($prospect->contact_phone);

        if (! $email && ! $phone) {
            return [];
        }

        $phoneTail = $phone ? substr($phone, -7) : null;

        return Customer::query()
            ->where('user_id', (int) $reservation->account_id)
            ->where(function ($query) use ($email, $phoneTail) {
                if ($email) {
                    $query->orWhereRaw('LOWER(email) = ?', [$email]);
                }

                if ($phoneTail) {
                    $query->orWhere('phone', 'like', '%'.$phoneTail.'%');
                }
            })
            ->limit(12)
            ->get(['id', 'number', 'company_name', 'first_name', 'last_name', 'email', 'phone', 'portal_user_id'])
            ->map(function (Customer $customer) use ($email, $phone) {
                $score = 0;
                $reasons = [];

                if ($email && $this->normalizeEmail($customer->email) === $email) {
                    $score += 100;
                    $reasons[] = ['code' => 'email_exact', 'label' => 'Email exact', 'weight' => 100];
                }

                if ($phone && $this->normalizePhone($customer->phone) === $phone) {
                    $score += 90;
                    $reasons[] = ['code' => 'phone_exact', 'label' => 'Telephone exact', 'weight' => 90];
                }

                if ($score === 0) {
                    return null;
                }

                return [
                    'id' => (int) $customer->id,
                    'number' => $customer->number,
                    'display_name' => $customer->company_name
                        ?: trim(($customer->first_name ?? '').' '.($customer->last_name ?? ''))
                        ?: $customer->email,
                    'company_name' => $customer->company_name,
                    'first_name' => $customer->first_name,
                    'last_name' => $customer->last_name,
                    'email' => $customer->email,
                    'phone' => $customer->phone,
                    'score' => $score,
                    'match_reasons' => $reasons,
                ];
            })
            ->filter()
            ->sortByDesc('score')
            ->values()
            ->all();
    }

    /**
     * @param  array<string, mixed>  $validated
     * @return array{customer: Customer, reservation: Reservation, prospect: LeadRequest, matches: array<int, array<string, mixed>>}
     */
    public function convert(Reservation $reservation, array $validated, User $actor): array
    {
        $prospect = $this->prospectFor($reservation);
        $matches = $this->customerMatches($reservation);

        return DB::transaction(function () use ($actor, $matches, $prospect, $reservation, $validated) {
            if ($prospect->customer_id) {
                $customer = Customer::query()
                    ->where('user_id', (int) $reservation->account_id)
                    ->find($prospect->customer_id);

                if (! $customer) {
                    throw ValidationException::withMessages([
                        'customer_id' => ['The converted customer is no longer available.'],
                    ]);
                }
            } else {
                $result = $this->prospectConversionService->execute($prospect, $validated, $actor, [
                    'matched_customer_ids' => collect($matches)->pluck('id')->values()->all(),
                ]);
                $customer = $result['customer'];
                $prospect = $result['lead'];
            }

            $metadata = (array) ($reservation->metadata ?? []);
            $publicBookingMeta = (array) data_get($metadata, 'public_booking', []);
            data_set($metadata, 'public_booking', array_merge($publicBookingMeta, [
                'converted_at' => now('UTC')->toIso8601String(),
                'converted_customer_id' => (int) $customer->id,
                'converted_by_user_id' => (int) $actor->id,
            ]));

            $reservation->forceFill([
                'client_id' => (int) $customer->id,
                'client_user_id' => $customer->portal_user_id ? (int) $customer->portal_user_id : null,
                'metadata' => $metadata,
            ])->save();

            return [
                'customer' => $customer->fresh(),
                'reservation' => $reservation->fresh([
                    'client:id,first_name,last_name,company_name,email,phone,portal_user_id',
                    'prospect:id,contact_name,contact_email,contact_phone,status,converted_at,converted_customer_id,customer_id,meta',
                    'service:id,name,price',
                    'teamMember.user:id,name',
                    'publicBookingLink:id,name,slug',
                ]),
                'prospect' => $prospect->fresh(),
                'matches' => $matches,
            ];
        });
    }

    private function prospectFor(Reservation $reservation): LeadRequest
    {
        $prospect = $reservation->relationLoaded('prospect')
            ? $reservation->prospect
            : $reservation->prospect()->first();

        if (! $prospect) {
            throw ValidationException::withMessages([
                'prospect_id' => ['This reservation is not linked to a public booking prospect.'],
            ]);
        }

        return $prospect;
    }

    private function normalizeEmail(mixed $value): ?string
    {
        $normalized = strtolower(trim((string) $value));

        return $normalized !== '' ? $normalized : null;
    }

    private function normalizePhone(mixed $value): ?string
    {
        $digits = preg_replace('/\D+/', '', (string) $value) ?? '';

        if ($digits !== '' && strlen($digits) === 11 && str_starts_with($digits, '1')) {
            $digits = substr($digits, 1);
        }

        return strlen($digits) >= 7 ? $digits : null;
    }
}
