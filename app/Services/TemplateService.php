<?php

namespace App\Services;

use App\Models\PlatformSetting;
use App\Models\User;
use Illuminate\Support\Str;

class TemplateService
{
    public function resolveQuoteDefaults(?User $user): array
    {
        [$templates] = $this->resolveTemplates($user);

        $platformTemplates = PlatformSetting::getValue('templates', []);
        $platformQuoteDefault = trim((string) ($platformTemplates['quote_default'] ?? ''));

        return [
            'messages' => (string) ($templates['quote_messages'] ?? ''),
            'notes' => $platformQuoteDefault !== '' ? $platformQuoteDefault : (string) ($templates['quote_notes'] ?? ''),
        ];
    }

    public function resolveQuoteExamples(?User $user): array
    {
        $config = $this->getConfig();
        $sectorLabel = $this->resolveSectorLabel($user);
        $examples = is_array($config['examples'] ?? null) ? $config['examples'] : [];

        $resolved = [];
        foreach ($examples as $example) {
            if (!is_array($example)) {
                continue;
            }

            $label = $this->injectSector((string) ($example['label'] ?? 'Template'), $sectorLabel);
            $messages = $this->injectSector((string) ($example['messages'] ?? ''), $sectorLabel);
            $notes = $this->injectSector((string) ($example['notes'] ?? ''), $sectorLabel);

            $resolved[] = [
                'key' => (string) ($example['key'] ?? Str::slug($label, '_')),
                'label' => $label,
                'messages' => $messages,
                'notes' => $notes,
            ];
        }

        return $resolved;
    }

    public function resolveInvoiceNote(?User $user): string
    {
        [$templates] = $this->resolveTemplates($user);

        $platformTemplates = PlatformSetting::getValue('templates', []);
        $platformInvoiceDefault = trim((string) ($platformTemplates['invoice_default'] ?? ''));

        if ($platformInvoiceDefault !== '') {
            return $platformInvoiceDefault;
        }

        return (string) ($templates['invoice_note'] ?? '');
    }

    private function resolveTemplates(?User $user): array
    {
        $config = $this->getConfig();
        $sectorKey = $this->resolveSectorKey($user);
        $sectorLabel = $this->resolveSectorLabel($user, $sectorKey);

        $defaults = is_array($config['defaults'] ?? null) ? $config['defaults'] : [];
        $overrides = is_array($config['sector_overrides'][$sectorKey] ?? null)
            ? $config['sector_overrides'][$sectorKey]
            : [];

        $templates = array_merge($defaults, $overrides);

        foreach ($templates as $key => $value) {
            if (!is_string($value)) {
                continue;
            }
            $templates[$key] = $this->injectSector($value, $sectorLabel);
        }

        return [$templates, $sectorLabel];
    }

    private function resolveSectorKey(?User $user): string
    {
        $sector = $user?->company_sector ?? '';
        $normalized = Str::slug(
            Str::of((string) $sector)->lower()->trim()->replace(' ', '_')->toString(),
            '_'
        );

        return $normalized !== '' ? $normalized : 'general';
    }

    private function resolveSectorLabel(?User $user, ?string $sectorKey = null): string
    {
        $config = $this->getConfig();
        $labels = is_array($config['sector_labels'] ?? null) ? $config['sector_labels'] : [];
        $key = $sectorKey ?: $this->resolveSectorKey($user);

        if (isset($labels[$key])) {
            return (string) $labels[$key];
        }

        return 'General';
    }

    private function injectSector(string $value, string $sectorLabel): string
    {
        return str_replace('{sector}', $sectorLabel, $value);
    }

    private function getConfig(): array
    {
        $config = config('templates', []);
        return is_array($config) ? $config : [];
    }
}
