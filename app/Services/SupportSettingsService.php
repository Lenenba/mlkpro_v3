<?php

namespace App\Services;

use App\Models\PlatformSetting;

class SupportSettingsService
{
    public const KEY = 'support_settings';

    public function settings(): array
    {
        $defaults = [
            'auto_assign' => true,
            'round_robin_last_user_id' => null,
            'sla_hours' => [
                'urgent' => 4,
                'high' => 24,
                'normal' => 48,
                'low' => 72,
            ],
            'reminders' => [
                'due_soon_hours' => 2,
                'cooldown_hours' => 6,
                'unassigned_hours' => 24,
            ],
        ];

        $current = PlatformSetting::getValue(self::KEY, []);
        if (!is_array($current)) {
            return $defaults;
        }

        return array_replace_recursive($defaults, $current);
    }

    public function autoAssignEnabled(): bool
    {
        return (bool) ($this->settings()['auto_assign'] ?? false);
    }

    public function slaHours(string $priority): int
    {
        $settings = $this->settings();
        $value = $settings['sla_hours'][$priority] ?? null;
        return is_numeric($value) ? max(0, (int) $value) : 0;
    }

    public function reminderConfig(): array
    {
        $settings = $this->settings();
        return $settings['reminders'] ?? [];
    }

    public function lastAssigneeId(): ?int
    {
        $settings = $this->settings();
        $id = $settings['round_robin_last_user_id'] ?? null;
        return is_numeric($id) ? (int) $id : null;
    }

    public function setLastAssigneeId(?int $userId): void
    {
        $settings = $this->settings();
        $settings['round_robin_last_user_id'] = $userId;
        PlatformSetting::setValue(self::KEY, $settings);
    }
}
