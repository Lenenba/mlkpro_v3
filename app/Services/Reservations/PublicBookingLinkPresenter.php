<?php

namespace App\Services\Reservations;

use App\Models\Product;
use App\Models\PublicBookingLink;
use App\Models\Request as LeadRequest;
use App\Models\Reservation;
use App\Models\User;
use Illuminate\Support\Collection;

class PublicBookingLinkPresenter
{
    /**
     * @return \Illuminate\Support\Collection<int, array<string, mixed>>
     */
    public function linksForAccount(User $account): Collection
    {
        return PublicBookingLink::query()
            ->forAccount((int) $account->id)
            ->with(['services:id,name,price,item_type,user_id'])
            ->withCount([
                'services as services_count',
                'reservations as reservations_count',
                'reservations as pending_reservations_count' => fn ($query) => $query->where('status', Reservation::STATUS_PENDING),
                'prospects as prospects_count',
                'prospects as converted_prospects_count' => fn ($query) => $query
                    ->where(function ($nested) {
                        $nested->where('status', LeadRequest::STATUS_CONVERTED)
                            ->orWhereNotNull('converted_at')
                            ->orWhereNotNull('converted_customer_id');
                    }),
            ])
            ->latest()
            ->get()
            ->map(fn (PublicBookingLink $link) => $this->link($link, $account));
    }

    /**
     * @return \Illuminate\Support\Collection<int, array<string, mixed>>
     */
    public function servicesForAccount(User $account): Collection
    {
        return Product::query()
            ->services()
            ->where('user_id', (int) $account->id)
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'name', 'price', 'item_type', 'user_id'])
            ->map(fn (Product $service) => [
                'id' => (int) $service->id,
                'name' => (string) $service->name,
                'price' => (float) ($service->price ?? 0),
            ])
            ->values();
    }

    /**
     * @return array<string, mixed>
     */
    public function link(PublicBookingLink $link, ?User $account = null): array
    {
        $account ??= $link->relationLoaded('account') ? $link->account : null;

        return [
            'id' => (int) $link->id,
            'name' => (string) $link->name,
            'slug' => (string) $link->slug,
            'description' => $link->description,
            'is_active' => (bool) $link->is_active,
            'requires_manual_confirmation' => (bool) $link->requires_manual_confirmation,
            'requires_deposit' => (bool) $link->requires_deposit,
            'expires_at' => $link->expires_at?->toDateString(),
            'source' => $link->source,
            'campaign' => $link->campaign,
            'public_url' => $link->publicUrl($account),
            'services' => $link->services
                ->map(fn (Product $service) => [
                    'id' => (int) $service->id,
                    'name' => (string) $service->name,
                    'price' => (float) ($service->price ?? 0),
                ])
                ->values()
                ->all(),
            'service_ids' => $link->services
                ->pluck('id')
                ->map(fn ($id) => (int) $id)
                ->values()
                ->all(),
            'stats' => [
                'services' => (int) ($link->services_count ?? $link->services->count()),
                'reservations' => (int) ($link->reservations_count ?? 0),
                'pending_reservations' => (int) ($link->pending_reservations_count ?? 0),
                'prospects' => (int) ($link->prospects_count ?? 0),
                'converted_prospects' => (int) ($link->converted_prospects_count ?? 0),
            ],
        ];
    }
}
