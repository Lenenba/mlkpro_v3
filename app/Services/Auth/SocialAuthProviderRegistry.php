<?php

namespace App\Services\Auth;

use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Route;

class SocialAuthProviderRegistry
{
    /**
     * @return array<int, array<string, mixed>>
     */
    public function all(?string $context = null): array
    {
        $providers = [];

        foreach ((array) config('social_auth.providers', []) as $key => $provider) {
            $resolved = $this->hydrate((string) $key, is_array($provider) ? $provider : []);

            if ($context !== null && ! ($resolved['contexts'][$context] ?? false)) {
                continue;
            }

            $providers[] = $resolved;
        }

        return $providers;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function enabled(?string $context = null): array
    {
        return array_values(array_filter(
            $this->all($context),
            static fn (array $provider): bool => (bool) ($provider['enabled'] ?? false)
        ));
    }

    /**
     * @return array<string, mixed>|null
     */
    public function active(string $provider, ?string $context = null): ?array
    {
        $resolved = $this->provider($provider);

        if (! $resolved) {
            return null;
        }

        if (! ($resolved['enabled'] ?? false)) {
            return null;
        }

        if ($context !== null && ! ($resolved['contexts'][$context] ?? false)) {
            return null;
        }

        return $resolved;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function provider(string $provider): ?array
    {
        $key = strtolower(trim($provider));
        $providers = (array) config('social_auth.providers', []);
        $resolved = $providers[$key] ?? null;

        if (! is_array($resolved)) {
            return null;
        }

        return $this->hydrate($key, $resolved);
    }

    /**
     * @return array<string, mixed>
     */
    public function publicPayload(?string $context = null): array
    {
        return [
            'enabled' => $this->globallyEnabled(),
            'providers' => array_map(function (array $provider): array {
                return Arr::only($provider, [
                    'key',
                    'label',
                    'icon',
                    'driver',
                    'contexts',
                    'configured',
                    'implemented',
                    'ready',
                    'redirect_url',
                    'callback_url',
                    'scopes',
                ]);
            }, $this->enabled($context)),
        ];
    }

    public function globallyEnabled(): bool
    {
        return (bool) config('social_auth.enabled', false);
    }

    /**
     * @param  array<string, mixed>  $provider
     * @return array<string, mixed>
     */
    private function hydrate(string $key, array $provider): array
    {
        $contexts = array_merge([
            'login' => true,
            'register' => true,
            'onboarding' => true,
        ], is_array($provider['contexts'] ?? null) ? $provider['contexts'] : []);
        $globallyEnabled = $this->globallyEnabled();
        $providerEnabled = $globallyEnabled && (bool) ($provider['enabled'] ?? false);
        $configured = $this->hasCredentials($provider);
        $implemented = (bool) ($provider['implemented'] ?? false);

        return [
            'key' => $key,
            'label' => (string) ($provider['label'] ?? ucfirst($key)),
            'icon' => (string) ($provider['icon'] ?? $key),
            'driver' => (string) ($provider['driver'] ?? 'oauth2'),
            'enabled' => $providerEnabled,
            'configured' => $configured,
            'implemented' => $implemented,
            'ready' => $providerEnabled && $configured && $implemented,
            'contexts' => $contexts,
            'client_id' => $provider['client_id'] ?? null,
            'client_secret' => $provider['client_secret'] ?? null,
            'redirect_uri' => $provider['redirect_uri'] ?? null,
            'authorize_url' => $provider['authorize_url'] ?? null,
            'token_url' => $provider['token_url'] ?? null,
            'userinfo_url' => $provider['userinfo_url'] ?? null,
            'tenant' => $provider['tenant'] ?? null,
            'scopes' => array_values((array) ($provider['scopes'] ?? [])),
            'redirect_url' => Route::has('auth.social.redirect')
                ? route('auth.social.redirect', ['provider' => $key], false)
                : null,
            'callback_url' => Route::has('auth.social.callback')
                ? route('auth.social.callback', ['provider' => $key], false)
                : null,
        ];
    }

    /**
     * @param  array<string, mixed>  $provider
     */
    private function hasCredentials(array $provider): bool
    {
        return trim((string) ($provider['client_id'] ?? '')) !== ''
            && trim((string) ($provider['client_secret'] ?? '')) !== ''
            && trim((string) ($provider['redirect_uri'] ?? '')) !== '';
    }
}
