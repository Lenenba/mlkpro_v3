<?php

namespace App\Services\Campaigns;

use App\Models\MarketingSetting;
use App\Models\User;
use Illuminate\Support\Arr;

class MarketingSettingsService
{
    /**
     * @var array<int, array<string, mixed>>
     */
    private array $resolvedCache = [];

    public function getModel(User $accountOwner): MarketingSetting
    {
        return MarketingSetting::query()->firstOrCreate(
            ['user_id' => $accountOwner->id],
            MarketingSetting::defaults()
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function getResolved(User $accountOwner): array
    {
        if (isset($this->resolvedCache[$accountOwner->id])) {
            return $this->resolvedCache[$accountOwner->id];
        }

        $model = $this->getModel($accountOwner);
        $defaults = MarketingSetting::defaults();
        $stored = [
            'channels' => is_array($model->channels) ? $model->channels : [],
            'consent' => is_array($model->consent) ? $model->consent : [],
            'audience' => is_array($model->audience) ? $model->audience : [],
            'templates' => is_array($model->templates) ? $model->templates : [],
            'tracking' => is_array($model->tracking) ? $model->tracking : [],
            'offers' => is_array($model->offers) ? $model->offers : [],
        ];

        $resolved = $this->mergeRecursive($defaults, $stored);
        $this->resolvedCache[$accountOwner->id] = $resolved;

        return $resolved;
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function update(User $accountOwner, array $payload): MarketingSetting
    {
        $model = $this->getModel($accountOwner);
        $resolved = $this->getResolved($accountOwner);

        foreach (['channels', 'consent', 'audience', 'templates', 'tracking', 'offers'] as $section) {
            if (!array_key_exists($section, $payload)) {
                continue;
            }

            $incoming = is_array($payload[$section]) ? $payload[$section] : [];
            $resolved[$section] = $this->mergeRecursive(
                is_array($resolved[$section] ?? null) ? $resolved[$section] : [],
                $incoming
            );
        }

        $model->fill([
            'channels' => $resolved['channels'] ?? [],
            'consent' => $resolved['consent'] ?? [],
            'audience' => $resolved['audience'] ?? [],
            'templates' => $resolved['templates'] ?? [],
            'tracking' => $resolved['tracking'] ?? [],
            'offers' => $resolved['offers'] ?? [],
        ]);
        $model->save();

        unset($this->resolvedCache[$accountOwner->id]);

        return $this->getModel($accountOwner);
    }

    public function getValue(User $accountOwner, string $path, mixed $default = null): mixed
    {
        $resolved = $this->getResolved($accountOwner);
        return Arr::get($resolved, $path, $default);
    }

    public function isChannelEnabled(User $accountOwner, string $channel): bool
    {
        $value = $this->getValue(
            $accountOwner,
            'channels.enabled.' . strtoupper(trim($channel)),
            true
        );

        return (bool) $value;
    }

    /**
     * @return array<int, string>
     */
    public function allowedOfferModes(User $accountOwner): array
    {
        $modes = $this->getValue($accountOwner, 'offers.allowed_modes', []);
        if (!is_array($modes)) {
            return [];
        }

        return collect($modes)
            ->map(fn ($mode) => strtoupper((string) $mode))
            ->filter()
            ->values()
            ->all();
    }

    /**
     * @param array<string, mixed> $base
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private function mergeRecursive(array $base, array $overrides): array
    {
        foreach ($overrides as $key => $value) {
            if (is_array($value) && is_array($base[$key] ?? null)) {
                $base[$key] = $this->mergeRecursive($base[$key], $value);
                continue;
            }

            $base[$key] = $value;
        }

        return $base;
    }
}

