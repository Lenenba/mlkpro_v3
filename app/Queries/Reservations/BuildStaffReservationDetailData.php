<?php

namespace App\Queries\Reservations;

use App\Enums\CurrencyCode;
use App\Models\Customer;
use App\Models\Product;
use App\Models\Reservation;
use App\Models\TeamMember;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Str;

class BuildStaffReservationDetailData
{
    private const DEPOSIT_STATUSES = [
        'not_required',
        'required',
        'paid',
        'forfeited',
        'due_on_invoice',
        'refundable',
        'refunded',
    ];

    private const NO_SHOW_FEE_STATUSES = [
        'not_applicable',
        'not_applied',
        'charge_required',
        'waived',
        'paid',
    ];

    public function build(Reservation $reservation, User $actor, User $account, bool $ownerOnlyMode): array
    {
        $accountId = (int) $account->id;

        $reservation->load([
            'teamMember' => fn ($query) => $query
                ->forAccount($accountId)
                ->select(['id', 'account_id', 'user_id', 'title']),
            'teamMember.user' => fn ($query) => $query
                ->select(['id', 'name', 'profile_picture']),
            'creator' => fn ($query) => $this->scopeActorToAccount($query, $accountId)
                ->select(['id', 'name', 'profile_picture']),
            'canceller' => fn ($query) => $this->scopeActorToAccount($query, $accountId)
                ->select(['id', 'name', 'profile_picture']),
            'client' => fn ($query) => $query
                ->where('user_id', $accountId)
                ->select([
                    'id',
                    'user_id',
                    'first_name',
                    'last_name',
                    'company_name',
                    'client_type',
                    'email',
                    'phone',
                    'is_vip',
                    'logo',
                ]),
            'prospect' => fn ($query) => $query
                ->where('user_id', $accountId)
                ->select(['id', 'user_id', 'contact_name', 'contact_email', 'contact_phone']),
            'publicBookingLink' => fn ($query) => $query
                ->forAccount($accountId)
                ->select(['id', 'account_id', 'name']),
            'service' => fn ($query) => $query
                ->where('user_id', $accountId)
                ->select([
                    'id',
                    'user_id',
                    'category_id',
                    'name',
                    'description',
                    'price',
                    'currency_code',
                    'item_type',
                    'image',
                ]),
            'service.category' => fn ($query) => $query
                ->forAccount($accountId)
                ->select(['id', 'user_id', 'name']),
            'resourceAllocations' => fn ($query) => $query
                ->forAccount($accountId)
                ->select([
                    'id',
                    'account_id',
                    'reservation_id',
                    'reservation_resource_id',
                    'quantity',
                ]),
            'resourceAllocations.resource' => fn ($query) => $query
                ->forAccount($accountId)
                ->select(['id', 'account_id', 'name', 'type', 'capacity']),
        ]);

        $canEdit = ! $ownerOnlyMode && $actor->can('update', $reservation);
        $canDelete = ! $ownerOnlyMode && $actor->can('delete', $reservation);
        $canUpdateStatus = ! $ownerOnlyMode && $actor->can('updateStatus', $reservation);
        $canConvert = $canEdit && (bool) $reservation->prospect && ! $reservation->client_id;

        return [
            'id' => (int) $reservation->id,
            'team_member_id' => $reservation->team_member_id ? (int) $reservation->team_member_id : null,
            'client_id' => $reservation->client_id ? (int) $reservation->client_id : null,
            'prospect_id' => $reservation->prospect_id ? (int) $reservation->prospect_id : null,
            'service_id' => $reservation->service_id ? (int) $reservation->service_id : null,
            'status' => (string) $reservation->status,
            'source' => (string) $reservation->source,
            'timezone' => (string) $reservation->timezone,
            'starts_at' => $reservation->starts_at?->toIso8601String(),
            'ends_at' => $reservation->ends_at?->toIso8601String(),
            'duration_minutes' => (int) $reservation->duration_minutes,
            'buffer_minutes' => (int) $reservation->buffer_minutes,
            'party_size' => $this->partySize($reservation),
            'client_notes' => $reservation->client_notes,
            'internal_notes' => $reservation->internal_notes,
            'cancelled_at' => $reservation->cancelled_at?->toIso8601String(),
            'cancel_reason' => $reservation->cancel_reason,
            'auto_closed_at' => $reservation->auto_closed_at?->toIso8601String(),
            'auto_closed_reason' => $reservation->auto_closed_reason,
            'outcome_review_required_at' => $reservation->outcome_review_required_at?->toIso8601String(),
            'outcome_review_reason_code' => $reservation->outcome_review_reason_code,
            'created_at' => $reservation->created_at?->toIso8601String(),
            'updated_at' => $reservation->updated_at?->toIso8601String(),
            'client' => $this->clientData($reservation->client),
            'prospect' => ! $reservation->client_id ? $this->prospectData($reservation) : null,
            'public_booking_link' => $this->publicBookingLinkData($reservation),
            'service' => $this->serviceData($reservation->service, $account),
            'team_member' => $this->teamMemberData($reservation->teamMember),
            'creator' => $this->actorData($reservation->creator),
            'canceller' => $this->actorData($reservation->canceller),
            'resources' => $reservation->resourceAllocations
                ->filter(fn ($allocation) => (bool) $allocation->resource)
                ->map(fn ($allocation) => [
                    'id' => (int) $allocation->resource->id,
                    'name' => (string) $allocation->resource->name,
                    'type' => (string) $allocation->resource->type,
                    'capacity' => (int) $allocation->resource->capacity,
                    'quantity' => (int) $allocation->quantity,
                ])
                ->values()
                ->all(),
            'payment' => $this->paymentData($reservation, $account),
            'permissions' => [
                'can_edit' => $canEdit,
                'can_delete' => $canDelete,
                'can_update_status' => $canUpdateStatus,
                'can_convert' => $canConvert,
                'allowed_status_transitions' => $this->allowedStatusTransitions($reservation, $canUpdateStatus),
            ],
        ];
    }

    private function clientData(?Customer $client): ?array
    {
        if (! $client) {
            return null;
        }

        $displayName = $client->company_name
            ?: trim(($client->first_name ?? '').' '.($client->last_name ?? ''))
            ?: $client->email;

        return [
            'id' => (int) $client->id,
            'display_name' => $displayName ?: null,
            'first_name' => $client->first_name,
            'last_name' => $client->last_name,
            'company_name' => $client->company_name,
            'client_type' => $client->client_type,
            'email' => $client->email,
            'phone' => $client->phone,
            'is_vip' => (bool) $client->is_vip,
            'avatar_url' => $client->logo_url,
        ];
    }

    private function prospectData(Reservation $reservation): ?array
    {
        if (! $reservation->prospect) {
            return null;
        }

        return [
            'id' => (int) $reservation->prospect->id,
            'contact_name' => $reservation->prospect->contact_name,
            'contact_email' => $reservation->prospect->contact_email,
            'contact_phone' => $reservation->prospect->contact_phone,
        ];
    }

    private function publicBookingLinkData(Reservation $reservation): ?array
    {
        if (! $reservation->publicBookingLink) {
            return null;
        }

        return [
            'id' => (int) $reservation->publicBookingLink->id,
            'name' => (string) $reservation->publicBookingLink->name,
        ];
    }

    private function serviceData(?Product $service, User $account): ?array
    {
        if (! $service) {
            return null;
        }

        return [
            'id' => (int) $service->id,
            'name' => (string) $service->name,
            'description' => $this->cleanDescription($service->description),
            'category' => $service->category ? [
                'id' => (int) $service->category->id,
                'name' => (string) $service->category->name,
            ] : null,
            'price' => round((float) $service->price, 2),
            'currency_code' => CurrencyCode::tryFromMixed($service->currency_code)?->value
                ?? $account->businessCurrencyCode(),
            'image_url' => $service->image_url,
            'has_image' => $this->hasCustomServiceImage($service),
        ];
    }

    private function teamMemberData(?TeamMember $teamMember): ?array
    {
        if (! $teamMember) {
            return null;
        }

        return [
            'id' => (int) $teamMember->id,
            'name' => $teamMember->user?->name,
            'title' => $teamMember->title,
            'avatar_url' => $teamMember->user?->profile_picture_url,
        ];
    }

    private function actorData(?User $actor): ?array
    {
        if (! $actor) {
            return null;
        }

        return [
            'name' => $actor->name,
            'avatar_url' => $actor->profile_picture_url,
        ];
    }

    private function scopeActorToAccount(Builder|Relation $query, int $accountId): Builder|Relation
    {
        return $query->where(function (Builder $query) use ($accountId) {
            $query->where('users.id', $accountId)
                ->orWhereExists(fn ($members) => $members
                    ->selectRaw('1')
                    ->from('team_members')
                    ->whereColumn('team_members.user_id', 'users.id')
                    ->where('team_members.account_id', $accountId))
                ->orWhereExists(fn ($customers) => $customers
                    ->selectRaw('1')
                    ->from('customers')
                    ->whereColumn('customers.portal_user_id', 'users.id')
                    ->where('customers.user_id', $accountId));
        });
    }

    private function paymentData(Reservation $reservation, User $account): array
    {
        $metadata = is_array($reservation->metadata) ? $reservation->metadata : [];
        $policy = is_array($metadata['payment_policy'] ?? null) ? $metadata['payment_policy'] : [];
        $state = is_array($metadata['payment_state'] ?? null) ? $metadata['payment_state'] : [];
        $currencyCode = CurrencyCode::tryFromMixed($policy['currency_code'] ?? null)?->value;

        return [
            'currency_code' => $currencyCode ?? $account->businessCurrencyCode(),
            'policy' => [
                'deposit_required' => (bool) ($policy['deposit_required'] ?? false),
                'deposit_amount' => $this->money($policy['deposit_amount'] ?? 0),
                'no_show_fee_enabled' => (bool) ($policy['no_show_fee_enabled'] ?? false),
                'no_show_fee_amount' => $this->money($policy['no_show_fee_amount'] ?? 0),
            ],
            'state' => [
                'deposit_status' => $this->allowedValue($state['deposit_status'] ?? null, self::DEPOSIT_STATUSES),
                'deposit_due_amount' => $this->money($state['deposit_due_amount'] ?? 0),
                'no_show_fee_status' => $this->allowedValue($state['no_show_fee_status'] ?? null, self::NO_SHOW_FEE_STATUSES),
                'no_show_fee_amount' => $this->money($state['no_show_fee_amount'] ?? 0),
            ],
        ];
    }

    private function partySize(Reservation $reservation): ?int
    {
        $partySize = (int) data_get($reservation->metadata, 'party_size', 0);

        return $partySize > 0 ? $partySize : null;
    }

    private function hasCustomServiceImage(Product $service): bool
    {
        $path = ltrim(trim((string) $service->image), '/');

        return $path !== '' && ! in_array($path, [
            Product::LEGACY_DEFAULT_IMAGE_PATH,
            Product::DEFAULT_PRODUCT_IMAGE_PATH,
            Product::DEFAULT_SERVICE_IMAGE_PATH,
        ], true);
    }

    private function cleanDescription(mixed $description): ?string
    {
        $clean = preg_replace('/\s+/u', ' ', trim(strip_tags((string) $description))) ?: '';
        $clean = Str::limit($clean, 600, '');

        return $clean !== '' ? $clean : null;
    }

    private function allowedStatusTransitions(Reservation $reservation, bool $canUpdateStatus): array
    {
        if (! $canUpdateStatus) {
            return [];
        }

        $transitions = match ((string) $reservation->status) {
            Reservation::STATUS_PENDING => [Reservation::STATUS_CONFIRMED],
            Reservation::STATUS_CONFIRMED => [Reservation::STATUS_PENDING],
            Reservation::STATUS_RESCHEDULED => [Reservation::STATUS_CONFIRMED, Reservation::STATUS_PENDING],
            default => [],
        };

        if (! in_array($reservation->status, Reservation::ACTIVE_STATUSES, true)) {
            return $transitions;
        }

        if ($reservation->ends_at && ! $reservation->ends_at->isFuture()) {
            if (in_array($reservation->status, [Reservation::STATUS_CONFIRMED, Reservation::STATUS_RESCHEDULED], true)) {
                $transitions[] = Reservation::STATUS_COMPLETED;
            }
        }

        if ($reservation->starts_at && ! $reservation->starts_at->isFuture()) {
            $transitions[] = Reservation::STATUS_NO_SHOW;
        }

        $transitions[] = Reservation::STATUS_CANCELLED;

        return array_values(array_unique($transitions));
    }

    private function money(mixed $value): float
    {
        return max(0, round((float) $value, 2));
    }

    private function allowedValue(mixed $value, array $allowed): ?string
    {
        $normalized = trim((string) $value);

        return in_array($normalized, $allowed, true) ? $normalized : null;
    }
}
