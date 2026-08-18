<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\Storage;

final class TenantBrandingResolver
{
    /**
     * @return array{name: string, custom_logo_url: string|null, has_custom_logo: bool}
     */
    public function resolve(User $user): array
    {
        return $this->forAccountOwner($this->resolveAccountOwner($user));
    }

    public function resolveAccountOwner(User $user): ?User
    {
        $ownerId = $this->resolveAccountOwnerId($user);

        if ($ownerId === null) {
            return null;
        }

        return (int) $ownerId === (int) $user->id
            ? $user
            : User::query()->find($ownerId);
    }

    /**
     * @return array{name: string, custom_logo_url: string|null, has_custom_logo: bool}
     */
    public function forAccountOwner(?User $accountOwner): array
    {
        $customLogoUrl = $this->customLogoUrl($accountOwner);

        return [
            'name' => $this->companyName($accountOwner),
            'custom_logo_url' => $customLogoUrl,
            'has_custom_logo' => $customLogoUrl !== null,
        ];
    }

    private function resolveAccountOwnerId(User $user): ?int
    {
        if ($user->isClient()) {
            $customer = $user->relationLoaded('customerProfile')
                ? $user->customerProfile
                : $user->customerProfile()->first();

            if ($customer?->user_id) {
                return (int) $customer->user_id;
            }
        }

        return $user->accountOwnerId();
    }

    private function companyName(?User $accountOwner): string
    {
        foreach ([$accountOwner?->company_name, $accountOwner?->name, config('app.name', 'Malikia Pro')] as $name) {
            $name = trim((string) $name);

            if ($name !== '') {
                return $name;
            }
        }

        return 'Malikia Pro';
    }

    private function customLogoUrl(?User $accountOwner): ?string
    {
        $logo = trim((string) $accountOwner?->company_logo);

        if ($logo === '' || $this->isInvalidLogoValue($logo) || $this->isLegacyPlaceholder($logo)) {
            return null;
        }

        if (str_starts_with($logo, 'http://') || str_starts_with($logo, 'https://')) {
            return filter_var($logo, FILTER_VALIDATE_URL) !== false ? $logo : null;
        }

        if (str_starts_with($logo, '/')) {
            return str_starts_with($logo, '//') ? null : $logo;
        }

        return Storage::disk('public')->url($logo);
    }

    private function isInvalidLogoValue(string $logo): bool
    {
        if (preg_match('/[\x00-\x1F\x7F]/', $logo) === 1 || str_contains($logo, '\\')) {
            return true;
        }

        if (preg_match('/^[a-z][a-z0-9+.-]*:/i', $logo) === 1
            && ! str_starts_with($logo, 'http://')
            && ! str_starts_with($logo, 'https://')) {
            return true;
        }

        $path = parse_url($logo, PHP_URL_PATH);
        if (! is_string($path) || $path === '') {
            return true;
        }

        return in_array('..', explode('/', $path), true);
    }

    private function isLegacyPlaceholder(string $logo): bool
    {
        $path = parse_url($logo, PHP_URL_PATH);
        if (! is_string($path)) {
            return false;
        }

        $path = '/'.ltrim($path, '/');

        return $path === '/customers/customer.png'
            || $path === '/storage/customers/customer.png'
            || str_ends_with($path, '/customers/customer.png');
    }
}
