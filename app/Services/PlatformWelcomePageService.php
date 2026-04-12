<?php

namespace App\Services;

use App\Models\PlatformPage;
use App\Models\PlatformSection;
use App\Support\LocalePreference;
use App\Support\WelcomeEditorialSections;
use App\Support\WelcomeShowcaseSection;

class PlatformWelcomePageService
{
    public const WELCOME_SLUG = 'welcome';

    public function ensurePageExists(?int $userId = null): PlatformPage
    {
        $page = PlatformPage::query()
            ->where('slug', self::WELCOME_SLUG)
            ->first();

        if ($page) {
            return $this->synchronizeExistingPageLocales($page, $userId);
        }

        return $this->createFromLegacy($userId);
    }

    public function isWelcomePage(?PlatformPage $page): bool
    {
        return $page instanceof PlatformPage && $page->slug === self::WELCOME_SLUG;
    }

    public function publicUrl(PlatformPage $page): string
    {
        return $this->isWelcomePage($page)
            ? route('welcome')
            : route('public.pages.show', ['slug' => $page->slug]);
    }

    public function displayPath(PlatformPage $page): string
    {
        return $this->isWelcomePage($page) ? '/' : '/pages/'.$page->slug;
    }

    private function synchronizeExistingPageLocales(PlatformPage $page, ?int $userId = null): PlatformPage
    {
        $payload = is_array($page->content) ? $page->content : [];
        $locales = is_array($payload['locales'] ?? null) ? $payload['locales'] : [];
        if ($locales === []) {
            return $page;
        }

        $canonicalLocale = $this->canonicalLocaleForExistingSections($locales);
        $canonicalSections = $this->sanitizeSectionList($locales[$canonicalLocale]['sections'] ?? []);
        if ($canonicalSections === []) {
            return $page;
        }

        $changed = false;

        foreach (LocalePreference::supported() as $locale) {
            $localeContent = is_array($locales[$locale] ?? null) ? $locales[$locale] : [];
            $existingSections = $this->sanitizeSectionList($localeContent['sections'] ?? []);
            $existingById = collect($existingSections)
                ->filter(fn ($section) => is_array($section))
                ->mapWithKeys(function ($section) {
                    $id = trim((string) ($section['id'] ?? ''));

                    return $id !== '' ? [$id => $section] : [];
                })
                ->all();

            $rebuiltSections = array_values(array_map(function ($section) use ($existingById, $locale) {
                $id = trim((string) ($section['id'] ?? ''));

                if ($id !== '' && array_key_exists($id, $existingById)) {
                    return $existingById[$id];
                }

                return $this->localizeMissingSectionForLocale($section, $locale);
            }, $canonicalSections));

            if (($localeContent['sections'] ?? []) !== $rebuiltSections) {
                $changed = true;
            }

            $localeContent['sections'] = $rebuiltSections;
            $locales[$locale] = $localeContent;
        }

        if (! $changed) {
            return $page;
        }

        $payload['locales'] = $locales;
        $payload['updated_at'] = now()->toIso8601String();
        $payload['updated_by'] = $userId ?? ($payload['updated_by'] ?? $page->updated_by);
        $page->content = $payload;

        if ($userId !== null) {
            $page->updated_by = $userId;
        }

        $page->save();

        return $page->fresh();
    }

    private function canonicalLocaleForExistingSections(array $locales): string
    {
        $bestLocale = LocalePreference::default();
        $bestCount = -1;

        foreach (LocalePreference::resolutionOrder($bestLocale) as $locale) {
            $count = count($this->sanitizeSectionList($locales[$locale]['sections'] ?? []));
            if ($count > $bestCount) {
                $bestLocale = $locale;
                $bestCount = $count;
            }
        }

        return $bestLocale;
    }

    private function sanitizeSectionList($sections): array
    {
        if (! is_array($sections)) {
            return [];
        }

        return array_values(array_filter($sections, fn ($section) => is_array($section)));
    }

    private function localizeMissingSectionForLocale(array $section, string $locale): array
    {
        if (! empty($section['use_source']) && ! empty($section['source_id'])) {
            return $this->resetSourceDrivenFields($section);
        }

        $editorial = WelcomeEditorialSections::forId((string) ($section['id'] ?? ''), $locale);

        if ($editorial === null) {
            return $section;
        }

        return array_merge(
            $editorial,
            [
                'enabled' => array_key_exists('enabled', $section) ? (bool) $section['enabled'] : true,
            ]
        );
    }

    private function resetSourceDrivenFields(array $section): array
    {
        $section['kicker'] = '';
        $section['title'] = '';
        $section['body'] = '';
        $section['image_alt'] = '';
        $section['note'] = ! empty($section['override_note']) ? ($section['note'] ?? '') : '';
        $section['items'] = ! empty($section['override_items']) ? ($section['items'] ?? []) : [];
        $section['stats'] = ! empty($section['override_stats']) ? ($section['stats'] ?? []) : [];
        $section['hero_images'] = [];
        $section['testimonial_author'] = '';
        $section['testimonial_role'] = '';
        $section['aside_kicker'] = '';
        $section['aside_title'] = '';
        $section['aside_body'] = '';
        $section['aside_items'] = [];
        $section['aside_link_label'] = '';
        $section['aside_link_href'] = '';
        $section['aside_image_alt'] = '';
        $section['primary_label'] = '';
        $section['primary_href'] = '';
        $section['secondary_label'] = '';
        $section['secondary_href'] = '';
        $section['showcase_badge_label'] = '';
        $section['showcase_badge_value'] = '';
        $section['showcase_badge_note'] = '';

        return $section;
    }

    private function createFromLegacy(?int $userId = null): PlatformPage
    {
        $legacy = app(WelcomeContentService::class)->resolveAll();
        $sectionContentService = app(PlatformSectionContentService::class);

        $orderedSections = [];
        $orderedSections[] = $this->createSection(
            'Welcome Hero',
            'welcome_hero',
            $this->mapLegacyLocales($legacy, fn (array $localeLegacy) => $this->mapHeroSection($localeLegacy)),
            $userId
        );
        $orderedSections[] = $this->createSection(
            'Welcome Trust',
            'welcome_trust',
            $this->mapLegacyLocales($legacy, fn (array $localeLegacy) => $this->mapTrustSection($localeLegacy)),
            $userId
        );
        $orderedSections[] = $this->createSection(
            'Welcome Showcase',
            'feature_tabs',
            $this->mapSupportedLocales(fn (string $locale) => $this->defaultShowcaseSection($locale)),
            $userId
        );
        $orderedSections[] = $this->createSection(
            'Welcome Features',
            'welcome_features',
            $this->mapLegacyLocales($legacy, fn (array $localeLegacy) => $this->mapFeaturesSection($localeLegacy)),
            $userId
        );

        $page = PlatformPage::query()->create([
            'slug' => self::WELCOME_SLUG,
            'title' => 'Welcome',
            'is_active' => true,
            'updated_by' => $userId,
        ]);

        $locales = [];
        foreach (LocalePreference::supported() as $locale) {
            $sourceSections = array_map(function (PlatformSection $section, int $index) use ($locale, $sectionContentService) {
                $content = $sectionContentService->resolveForLocale($section, $locale);

                return [
                    'id' => $this->pageSectionIdForSource($section, $index),
                    'enabled' => $section->is_active,
                    'source_id' => $section->id,
                    'use_source' => true,
                    'layout' => (string) ($content['layout'] ?? 'split'),
                ];
            }, $orderedSections, array_keys($orderedSections));

            $locales[$locale] = [
                'page_title' => '',
                'page_subtitle' => '',
                'header' => [
                    'background_type' => 'none',
                    'background_color' => '',
                    'background_image_url' => '',
                    'background_image_alt' => '',
                    'alignment' => 'center',
                ],
                'sections' => [
                    ...$sourceSections,
                    ...WelcomeEditorialSections::genericSections($locale),
                ],
            ];
        }

        $page->content = [
            'locales' => $locales,
            'updated_by' => $userId,
            'updated_at' => now()->toIso8601String(),
        ];
        $page->save();

        return $page;
    }

    private function createSection(string $name, string $type, array $locales, ?int $userId = null): PlatformSection
    {
        return PlatformSection::query()->create([
            'name' => $name,
            'type' => $type,
            'is_active' => true,
            'content' => [
                'locales' => $locales,
                'updated_by' => $userId,
                'updated_at' => now()->toIso8601String(),
            ],
            'updated_by' => $userId,
        ]);
    }

    /**
     * @param  callable(string): array<string, mixed>  $resolver
     * @return array<string, array<string, mixed>>
     */
    private function mapSupportedLocales(callable $resolver): array
    {
        $locales = [];

        foreach (LocalePreference::supported() as $locale) {
            $locales[$locale] = $resolver($locale);
        }

        return $locales;
    }

    /**
     * @param  array<string, mixed>  $legacy
     * @param  callable(array<string, mixed>): array<string, mixed>  $resolver
     * @return array<string, array<string, mixed>>
     */
    private function mapLegacyLocales(array $legacy, callable $resolver): array
    {
        return $this->mapSupportedLocales(
            fn (string $locale) => $resolver(is_array($legacy[$locale] ?? null) ? $legacy[$locale] : [])
        );
    }

    private function pageSectionIdForSource(PlatformSection $section, int $index): string
    {
        return 'welcome-section-'.($index + 1);
    }

    private function mapHeroSection(array $legacy): array
    {
        $hero = is_array($legacy['hero'] ?? null) ? $legacy['hero'] : [];
        $heroImages = is_array($hero['hero_images'] ?? null) ? $hero['hero_images'] : [];

        if ($heroImages === []) {
            $fallbackImageUrl = trim((string) ($hero['image_url'] ?? ''));
            if ($fallbackImageUrl !== '') {
                $heroImages = [[
                    'image_url' => $fallbackImageUrl,
                    'image_alt' => (string) ($hero['image_alt'] ?? ''),
                ]];
            }
        }

        return [
            'layout' => 'split',
            'background_color' => (string) ($hero['background_color'] ?? ''),
            'image_position' => 'right',
            'alignment' => 'left',
            'density' => 'normal',
            'tone' => 'default',
            'kicker' => (string) ($hero['eyebrow'] ?? ''),
            'title' => (string) ($hero['title'] ?? ''),
            'body' => (string) ($hero['subtitle'] ?? ''),
            'note' => (string) ($hero['note'] ?? ''),
            'stats' => is_array($hero['stats'] ?? null) ? $hero['stats'] : [],
            'items' => is_array($hero['highlights'] ?? null) ? $hero['highlights'] : [],
            'preview_cards' => is_array($hero['preview_cards'] ?? null) ? $hero['preview_cards'] : [],
            'hero_images' => $heroImages,
            'image_url' => (string) ($hero['image_url'] ?? ''),
            'image_alt' => (string) ($hero['image_alt'] ?? ''),
            'primary_label' => (string) ($hero['primary_cta'] ?? ''),
            'primary_href' => (string) ($hero['primary_href'] ?? ''),
            'secondary_label' => (string) ($hero['secondary_cta'] ?? ''),
            'secondary_href' => (string) ($hero['secondary_href'] ?? ''),
        ];
    }

    private function mapTrustSection(array $legacy): array
    {
        $trust = is_array($legacy['trust'] ?? null) ? $legacy['trust'] : [];

        return [
            'layout' => 'stack',
            'background_color' => (string) ($trust['background_color'] ?? ''),
            'image_position' => 'left',
            'alignment' => 'center',
            'density' => 'compact',
            'tone' => 'muted',
            'kicker' => '',
            'title' => (string) ($trust['title'] ?? ''),
            'body' => '',
            'items' => is_array($trust['items'] ?? null) ? $trust['items'] : [],
        ];
    }

    private function mapFeaturesSection(array $legacy): array
    {
        $features = is_array($legacy['features'] ?? null) ? $legacy['features'] : [];
        $secondary = is_array($features['new_features'] ?? null) ? $features['new_features'] : [];

        return [
            'layout' => 'stack',
            'background_color' => (string) ($features['background_color'] ?? ''),
            'image_position' => 'left',
            'alignment' => 'center',
            'density' => 'normal',
            'tone' => 'contrast',
            'kicker' => (string) ($features['kicker'] ?? ''),
            'title' => (string) ($features['title'] ?? ''),
            'body' => (string) ($features['subtitle'] ?? ''),
            'feature_items' => is_array($features['items'] ?? null) ? $features['items'] : [],
            'secondary_enabled' => array_key_exists('enabled', $secondary) ? (bool) $secondary['enabled'] : true,
            'secondary_background_color' => (string) ($secondary['background_color'] ?? ''),
            'secondary_kicker' => (string) ($secondary['kicker'] ?? ''),
            'secondary_title' => (string) ($secondary['title'] ?? ''),
            'secondary_body' => (string) ($secondary['subtitle'] ?? ''),
            'secondary_badge' => (string) ($secondary['badge'] ?? ''),
            'secondary_feature_items' => is_array($secondary['items'] ?? null) ? $secondary['items'] : [],
        ];
    }

    private function mapWorkflowSection(array $legacy): array
    {
        $workflow = is_array($legacy['workflow'] ?? null) ? $legacy['workflow'] : [];

        return [
            'layout' => 'split',
            'background_color' => (string) ($workflow['background_color'] ?? ''),
            'image_position' => 'right',
            'alignment' => 'left',
            'density' => 'normal',
            'tone' => 'default',
            'kicker' => (string) ($workflow['kicker'] ?? ''),
            'title' => (string) ($workflow['title'] ?? ''),
            'body' => (string) ($workflow['subtitle'] ?? ''),
            'preview_cards' => is_array($workflow['steps'] ?? null) ? $workflow['steps'] : [],
            'image_url' => (string) ($workflow['image_url'] ?? ''),
            'image_alt' => (string) ($workflow['image_alt'] ?? ''),
        ];
    }

    private function mapFieldSection(array $legacy): array
    {
        $field = is_array($legacy['field'] ?? null) ? $legacy['field'] : [];

        return [
            'layout' => 'split',
            'background_color' => (string) ($field['background_color'] ?? ''),
            'image_position' => 'left',
            'alignment' => 'left',
            'density' => 'normal',
            'tone' => 'default',
            'kicker' => (string) ($field['kicker'] ?? ''),
            'title' => (string) ($field['title'] ?? ''),
            'body' => (string) ($field['subtitle'] ?? ''),
            'items' => is_array($field['items'] ?? null) ? $field['items'] : [],
            'image_url' => (string) ($field['image_url'] ?? ''),
            'image_alt' => (string) ($field['image_alt'] ?? ''),
        ];
    }

    private function mapCustomSection(array $legacy): array
    {
        return [
            'layout' => 'split',
            'background_color' => (string) ($legacy['background_color'] ?? ''),
            'image_position' => 'right',
            'alignment' => 'left',
            'density' => 'normal',
            'tone' => 'default',
            'kicker' => (string) ($legacy['kicker'] ?? ''),
            'title' => (string) ($legacy['title'] ?? ''),
            'body' => (string) ($legacy['body'] ?? ''),
            'image_url' => (string) ($legacy['image_url'] ?? ''),
            'image_alt' => (string) ($legacy['image_alt'] ?? ''),
            'primary_label' => (string) ($legacy['primary_label'] ?? ''),
            'primary_href' => (string) ($legacy['primary_href'] ?? ''),
            'secondary_label' => (string) ($legacy['secondary_label'] ?? ''),
            'secondary_href' => (string) ($legacy['secondary_href'] ?? ''),
        ];
    }

    private function mapCtaSection(array $legacy): array
    {
        $cta = is_array($legacy['cta'] ?? null) ? $legacy['cta'] : [];

        return [
            'layout' => 'stack',
            'background_color' => (string) ($cta['background_color'] ?? ''),
            'image_position' => 'left',
            'alignment' => 'center',
            'density' => 'normal',
            'tone' => 'contrast',
            'kicker' => '',
            'title' => (string) ($cta['title'] ?? ''),
            'body' => (string) ($cta['subtitle'] ?? ''),
            'primary_label' => (string) ($cta['primary'] ?? ''),
            'primary_href' => (string) ($cta['primary_href'] ?? ''),
            'secondary_label' => (string) ($cta['secondary'] ?? ''),
            'secondary_href' => (string) ($cta['secondary_href'] ?? ''),
        ];
    }

    private function defaultShowcaseSection(string $locale): array
    {
        return WelcomeShowcaseSection::payload($locale);
    }
}
