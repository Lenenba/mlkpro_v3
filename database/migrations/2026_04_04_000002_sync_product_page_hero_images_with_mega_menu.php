<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const PRODUCT_HEROES = [
        'sales-crm' => [
            'image_url' => '/images/mega-menu/sales-crm-suite.svg',
            'alt_fr' => 'Illustration du produit Sales et CRM',
            'alt_en' => 'Sales and CRM product illustration',
        ],
        'reservations' => [
            'image_url' => '/images/mega-menu/reservations-suite.svg',
            'alt_fr' => 'Illustration du produit Reservations',
            'alt_en' => 'Reservations product illustration',
        ],
        'operations' => [
            'image_url' => '/images/mega-menu/operations-suite.svg',
            'alt_fr' => 'Illustration du produit Operations',
            'alt_en' => 'Operations product illustration',
        ],
        'commerce' => [
            'image_url' => '/images/mega-menu/commerce-suite.svg',
            'alt_fr' => 'Illustration du produit Commerce',
            'alt_en' => 'Commerce product illustration',
        ],
        'marketing-loyalty' => [
            'image_url' => '/images/mega-menu/marketing-loyalty-suite.svg',
            'alt_fr' => 'Illustration du produit Marketing et Fidelisation',
            'alt_en' => 'Marketing and loyalty product illustration',
        ],
        'ai-automation' => [
            'image_url' => '/images/mega-menu/ai-automation-suite.svg',
            'alt_fr' => 'Illustration du produit AI et Automation',
            'alt_en' => 'AI and automation product illustration',
        ],
        'command-center' => [
            'image_url' => '/images/mega-menu/platform-command-center.svg',
            'alt_fr' => 'Illustration du produit Command Center',
            'alt_en' => 'Command center product illustration',
        ],
    ];

    public function up(): void
    {
        $this->syncHeaders(applyTargetHeaders: true);
    }

    public function down(): void
    {
        $this->syncHeaders(applyTargetHeaders: false);
    }

    private function syncHeaders(bool $applyTargetHeaders): void
    {
        $pages = DB::table('platform_pages')
            ->whereIn('slug', array_keys(self::PRODUCT_HEROES))
            ->get(['id', 'slug', 'content']);

        foreach ($pages as $page) {
            $content = is_array($page->content)
                ? $page->content
                : json_decode((string) $page->content, true);

            if (! is_array($content) || ! is_array($content['locales'] ?? null)) {
                continue;
            }

            $hero = self::PRODUCT_HEROES[$page->slug] ?? null;
            if (! is_array($hero)) {
                continue;
            }

            $locales = $content['locales'];
            $changed = false;

            foreach ($locales as $locale => $localeContent) {
                if (! is_array($localeContent)) {
                    continue;
                }

                $nextLocaleContent = $localeContent;
                $header = is_array($localeContent['header'] ?? null) ? $localeContent['header'] : [];
                $alignment = is_string($header['alignment'] ?? null) && $header['alignment'] !== ''
                    ? $header['alignment']
                    : 'center';
                $alt = $this->heroAlt($hero, (string) $locale);

                $nextHeader = $applyTargetHeaders
                    ? [
                        'background_type' => 'image',
                        'background_color' => '',
                        'background_image_url' => $hero['image_url'],
                        'background_image_alt' => $alt,
                        'alignment' => $alignment,
                    ]
                    : [
                        'background_type' => 'none',
                        'background_color' => '',
                        'background_image_url' => '',
                        'background_image_alt' => '',
                        'alignment' => $alignment,
                    ];

                if ($header !== $nextHeader) {
                    $nextLocaleContent['header'] = $nextHeader;
                    $locales[$locale] = $nextLocaleContent;
                    $changed = true;
                }
            }

            if (! $changed) {
                continue;
            }

            $content['locales'] = $locales;
            $content['updated_at'] = now()->toIso8601String();

            DB::table('platform_pages')
                ->where('id', $page->id)
                ->update([
                    'content' => json_encode($content, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                    'updated_at' => now(),
                ]);
        }
    }

    /**
     * @param  array{alt_en: string, alt_fr: string}  $hero
     */
    private function heroAlt(array $hero, string $locale): string
    {
        return str_starts_with(strtolower($locale), 'fr')
            ? $hero['alt_fr']
            : $hero['alt_en'];
    }
};
