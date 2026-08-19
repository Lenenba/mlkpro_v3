<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\Storage;

final class TenantBrandingResolver
{
    public const DEFAULT_PRIMARY_COLOR = '#16A34A';

    /**
     * @return array{
     *     name: string,
     *     custom_logo_url: string|null,
     *     has_custom_logo: bool,
     *     primary_color: string,
     *     primary_hover_color: string,
     *     primary_focus_color: string,
     *     primary_foreground_color: string,
     *     has_custom_primary_color: bool
     * }
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
     * @return array{
     *     name: string,
     *     custom_logo_url: string|null,
     *     has_custom_logo: bool,
     *     primary_color: string,
     *     primary_hover_color: string,
     *     primary_focus_color: string,
     *     primary_foreground_color: string,
     *     has_custom_primary_color: bool
     * }
     */
    public function forAccountOwner(?User $accountOwner): array
    {
        $customLogoUrl = $this->customLogoUrl($accountOwner);
        $customPrimaryColor = $this->customPrimaryColor($accountOwner);
        $primaryColor = $customPrimaryColor ?? self::DEFAULT_PRIMARY_COLOR;
        $primaryForegroundColor = $this->foregroundColor($primaryColor);

        return [
            'name' => $this->companyName($accountOwner),
            'custom_logo_url' => $customLogoUrl,
            'has_custom_logo' => $customLogoUrl !== null,
            'primary_color' => $primaryColor,
            'primary_hover_color' => $this->stateColor($primaryColor, $primaryForegroundColor, 0.12),
            'primary_focus_color' => $this->stateColor($primaryColor, $primaryForegroundColor, 0.22),
            'primary_foreground_color' => $primaryForegroundColor,
            'has_custom_primary_color' => $customPrimaryColor !== null,
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

    private function customPrimaryColor(?User $accountOwner): ?string
    {
        $settings = $accountOwner?->company_branding_settings;
        if (! is_array($settings)) {
            return null;
        }

        $color = strtoupper(trim((string) ($settings['primary_color'] ?? '')));

        return preg_match('/^#[0-9A-F]{6}$/', $color) === 1 ? $color : null;
    }

    private function foregroundColor(string $background): string
    {
        $white = '#FFFFFF';
        $dark = '#111827';
        $whiteContrast = $this->contrastRatio($background, $white);
        $darkContrast = $this->contrastRatio($background, $dark);

        if ($whiteContrast >= $darkContrast && $whiteContrast >= 4.5) {
            return $white;
        }

        if ($darkContrast >= 4.5) {
            return $dark;
        }

        return '#000000';
    }

    private function stateColor(string $primaryColor, string $foregroundColor, float $amount): string
    {
        $target = $foregroundColor === '#FFFFFF' ? [0, 0, 0] : [255, 255, 255];
        $channels = $this->hexChannels($primaryColor);
        $mixed = array_map(
            static fn (int $channel, int $targetChannel): int => (int) round(
                $channel + (($targetChannel - $channel) * $amount)
            ),
            $channels,
            $target,
        );

        return sprintf('#%02X%02X%02X', ...$mixed);
    }

    private function contrastRatio(string $first, string $second): float
    {
        $firstLuminance = $this->relativeLuminance($first);
        $secondLuminance = $this->relativeLuminance($second);
        $lighter = max($firstLuminance, $secondLuminance);
        $darker = min($firstLuminance, $secondLuminance);

        return ($lighter + 0.05) / ($darker + 0.05);
    }

    private function relativeLuminance(string $color): float
    {
        $channels = array_map(static function (int $channel): float {
            $value = $channel / 255;

            return $value <= 0.04045
                ? $value / 12.92
                : (($value + 0.055) / 1.055) ** 2.4;
        }, $this->hexChannels($color));

        return (0.2126 * $channels[0]) + (0.7152 * $channels[1]) + (0.0722 * $channels[2]);
    }

    /**
     * @return array{0: int, 1: int, 2: int}
     */
    private function hexChannels(string $color): array
    {
        return [
            hexdec(substr($color, 1, 2)),
            hexdec(substr($color, 3, 2)),
            hexdec(substr($color, 5, 2)),
        ];
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
