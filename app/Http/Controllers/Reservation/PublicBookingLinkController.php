<?php

namespace App\Http\Controllers\Reservation;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\PublicBookingLink;
use App\Models\Reservation;
use App\Models\User;
use App\Services\Reservations\PublicBookingLinkPresenter;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class PublicBookingLinkController extends Controller
{
    public function __construct(
        private readonly PublicBookingLinkPresenter $presenter
    ) {}

    public function store(Request $request)
    {
        $account = $this->resolveAccount($request);
        $this->authorize('manageSettings', Reservation::class);
        $validated = $this->validatedPayload($request, $account);
        $slug = $this->uniqueSlug($account, $validated['slug'] ?: $validated['name']);

        $link = PublicBookingLink::query()->create([
            'account_id' => (int) $account->id,
            'name' => $validated['name'],
            'slug' => $slug,
            'description' => $validated['description'] ?? null,
            'is_active' => (bool) ($validated['is_active'] ?? true),
            'requires_manual_confirmation' => (bool) ($validated['requires_manual_confirmation'] ?? true),
            'requires_deposit' => (bool) ($validated['requires_deposit'] ?? false),
            'expires_at' => $validated['expires_at'] ?? null,
            'source' => $validated['source'] ?? null,
            'campaign' => $validated['campaign'] ?? null,
        ]);
        $link->services()->sync($validated['service_ids']);

        return response()->json([
            'message' => 'Public booking link created.',
            'link' => $this->freshPayload($link, $account),
        ], 201);
    }

    public function update(Request $request, PublicBookingLink $publicBookingLink)
    {
        $account = $this->resolveAccount($request);
        $this->authorize('manageSettings', Reservation::class);
        $this->assertLinkBelongsToAccount($publicBookingLink, $account);
        $validated = $this->validatedPayload($request, $account, $publicBookingLink);

        $publicBookingLink->update([
            'name' => $validated['name'],
            'slug' => $this->uniqueSlug($account, $validated['slug'] ?: $validated['name'], $publicBookingLink),
            'description' => $validated['description'] ?? null,
            'is_active' => (bool) ($validated['is_active'] ?? true),
            'requires_manual_confirmation' => (bool) ($validated['requires_manual_confirmation'] ?? true),
            'requires_deposit' => (bool) ($validated['requires_deposit'] ?? false),
            'expires_at' => $validated['expires_at'] ?? null,
            'source' => $validated['source'] ?? null,
            'campaign' => $validated['campaign'] ?? null,
        ]);
        $publicBookingLink->services()->sync($validated['service_ids']);

        return response()->json([
            'message' => 'Public booking link updated.',
            'link' => $this->freshPayload($publicBookingLink, $account),
        ]);
    }

    public function toggle(Request $request, PublicBookingLink $publicBookingLink)
    {
        $account = $this->resolveAccount($request);
        $this->authorize('manageSettings', Reservation::class);
        $this->assertLinkBelongsToAccount($publicBookingLink, $account);

        $validated = $request->validate([
            'is_active' => ['required', 'boolean'],
        ]);

        $publicBookingLink->update([
            'is_active' => (bool) $validated['is_active'],
        ]);

        return response()->json([
            'message' => 'Public booking link status updated.',
            'link' => $this->freshPayload($publicBookingLink, $account),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function validatedPayload(Request $request, User $account, ?PublicBookingLink $link = null): array
    {
        $ignoreId = $link?->id;

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'slug' => [
                'nullable',
                'string',
                'max:120',
                'alpha_dash',
                Rule::unique('public_booking_links', 'slug')
                    ->where(fn ($query) => $query->where('account_id', (int) $account->id))
                    ->ignore($ignoreId),
            ],
            'description' => ['nullable', 'string', 'max:1000'],
            'is_active' => ['nullable', 'boolean'],
            'requires_manual_confirmation' => ['nullable', 'boolean'],
            'requires_deposit' => ['nullable', 'boolean'],
            'expires_at' => ['nullable', 'date'],
            'source' => ['nullable', 'string', 'max:80'],
            'campaign' => ['nullable', 'string', 'max:120'],
            'service_ids' => ['required', 'array', 'min:1'],
            'service_ids.*' => [
                'integer',
                Rule::exists('products', 'id')->where(fn ($query) => $query
                    ->where('user_id', (int) $account->id)
                    ->where('item_type', Product::ITEM_TYPE_SERVICE)
                    ->where('is_active', true)),
            ],
        ]);

        $validated['service_ids'] = collect($validated['service_ids'])
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values()
            ->all();
        $rawSlug = trim((string) ($validated['slug'] ?? ''));
        $validated['slug'] = $rawSlug !== '' ? PublicBookingLink::normalizeSlug($rawSlug) : '';

        return $validated;
    }

    private function uniqueSlug(User $account, string $value, ?PublicBookingLink $ignore = null): string
    {
        $base = PublicBookingLink::normalizeSlug($value);
        $slug = $base;
        $index = 2;

        while (
            PublicBookingLink::query()
                ->forAccount((int) $account->id)
                ->where('slug', $slug)
                ->when($ignore, fn ($query) => $query->whereKeyNot($ignore->id))
                ->exists()
        ) {
            $slug = Str::limit($base, 112, '').'-'.$index;
            $index++;
        }

        return $slug;
    }

    private function freshPayload(PublicBookingLink $link, User $account): array
    {
        $fresh = PublicBookingLink::query()
            ->forAccount((int) $account->id)
            ->whereKey($link->id)
            ->with(['services:id,name,price,item_type,user_id'])
            ->withCount([
                'services as services_count',
                'reservations as reservations_count',
                'reservations as pending_reservations_count' => fn ($query) => $query->where('status', Reservation::STATUS_PENDING),
                'prospects as prospects_count',
                'prospects as converted_prospects_count' => fn ($query) => $query->whereNotNull('converted_customer_id'),
            ])
            ->firstOrFail();

        return $this->presenter->link($fresh, $account);
    }

    private function assertLinkBelongsToAccount(PublicBookingLink $link, User $account): void
    {
        if ((int) $link->account_id !== (int) $account->id) {
            abort(404);
        }
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
}
