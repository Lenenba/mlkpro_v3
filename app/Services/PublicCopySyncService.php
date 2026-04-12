<?php

namespace App\Services;

use App\Models\PlatformPage;
use App\Models\PlatformSection;
use App\Models\Role;
use App\Models\User;
use App\Support\WelcomeEditorialSections;
use App\Support\WelcomeShowcaseSection;
use Database\Seeders\MegaMenuSeeder;
use InvalidArgumentException;

class PublicCopySyncService
{
    private const TARGET_PAGES = 'pages';

    private const TARGET_WELCOME = 'welcome';

    private const TARGET_FOOTER = 'footer';

    /**
     * @return array<int, string>
     */
    private function allowedTargets(): array
    {
        return [
            self::TARGET_PAGES,
            self::TARGET_WELCOME,
            self::TARGET_FOOTER,
        ];
    }

    /**
     * @param  array<int, string>  $targets
     * @return array<string, mixed>
     */
    public function sync(array $targets = [], ?int $userId = null): array
    {
        $targets = $this->normalizeTargets($targets);
        $userId = $this->resolveUserId($userId);

        $summary = [
            'targets' => $targets,
            'user_id' => $userId,
        ];

        if (in_array(self::TARGET_PAGES, $targets, true)) {
            $summary['pages'] = app(MegaMenuSeeder::class)->sync($userId);
        }

        if (in_array(self::TARGET_WELCOME, $targets, true)) {
            $summary['welcome'] = $this->syncWelcome($userId);
        }

        if (in_array(self::TARGET_FOOTER, $targets, true)) {
            $summary['footer'] = $this->syncSharedFooter($userId);
        }

        return $summary;
    }

    /**
     * @param  array<int, string>  $targets
     * @return array<int, string>
     */
    private function normalizeTargets(array $targets): array
    {
        $normalized = [];

        foreach ($targets as $target) {
            foreach (explode(',', strtolower(trim((string) $target))) as $part) {
                $part = trim($part);
                if ($part === '') {
                    continue;
                }

                $normalized[] = $part;
            }
        }

        if ($normalized === [] || in_array('all', $normalized, true)) {
            return $this->allowedTargets();
        }

        $normalized = array_values(array_unique($normalized));
        $invalid = array_values(array_diff($normalized, $this->allowedTargets()));

        if ($invalid !== []) {
            throw new InvalidArgumentException(
                'Invalid target(s): '.implode(', ', $invalid).'. Allowed values: '.implode(', ', [...$this->allowedTargets(), 'all']).'.'
            );
        }

        return $normalized;
    }

    private function resolveUserId(?int $userId): ?int
    {
        if ($userId !== null) {
            if (! User::query()->whereKey($userId)->exists()) {
                throw new InvalidArgumentException("User {$userId} does not exist.");
            }

            return $userId;
        }

        $superadminRoleId = Role::query()->where('name', 'superadmin')->value('id');
        if (! $superadminRoleId) {
            return null;
        }

        return User::query()
            ->where('role_id', $superadminRoleId)
            ->orderBy('id')
            ->value('id');
    }

    /**
     * @return array<string, mixed>
     */
    private function syncWelcome(?int $userId): array
    {
        $welcomeContentService = app(WelcomeContentService::class);

        foreach ($welcomeContentService->locales() as $locale) {
            $welcomeContentService->updateLocale(
                $locale,
                $welcomeContentService->defaultContent($locale),
                [],
                $userId
            );
        }

        $page = app(PlatformWelcomePageService::class)->ensurePageExists($userId);
        $sourceSections = $this->syncWelcomeSourceSections($page, $userId);
        $pageContentService = app(PlatformPageContentService::class);
        $existing = $pageContentService->resolveAll($page);
        $theme = $pageContentService->resolveTheme($page);

        foreach ($pageContentService->locales() as $locale) {
            $current = is_array($existing[$locale] ?? null) ? $existing[$locale] : [];

            $payload = [
                'page_title' => (string) ($current['page_title'] ?? $page->title),
                'page_subtitle' => (string) ($current['page_subtitle'] ?? ''),
                'header' => is_array($current['header'] ?? null)
                    ? $current['header']
                    : $this->defaultPageHeader(),
                'sections' => [
                    ...$this->welcomeSourceReferences($sourceSections, $locale),
                    ...WelcomeEditorialSections::genericSections($locale),
                ],
            ];

            $pageContentService->updateLocale($page, $locale, $payload, $userId, $theme);
            $page = $page->fresh();
        }

        $page->forceFill([
            'title' => 'Welcome',
            'is_active' => true,
            'updated_by' => $userId,
            'published_at' => $page->published_at ?? now(),
        ])->save();

        return [
            'page_id' => $page->id,
            'source_section_ids' => array_map(
                fn (PlatformSection $section) => $section->id,
                array_values($sourceSections)
            ),
            'locales' => $pageContentService->locales(),
        ];
    }

    /**
     * @return array<string, PlatformSection>
     */
    private function syncWelcomeSourceSections(PlatformPage $page, ?int $userId): array
    {
        $sectionContentService = app(PlatformSectionContentService::class);
        $existing = $this->welcomeReferencedSectionsByType($page);
        $synced = [];

        foreach ($this->welcomeSourceDefinitions() as $definition) {
            $type = $definition['type'];
            $section = $existing[$type] ?? PlatformSection::query()->create([
                'name' => $definition['name'],
                'type' => $type,
                'is_active' => true,
                'content' => ['locales' => []],
                'updated_by' => $userId,
            ]);

            $section->forceFill([
                'name' => $definition['name'],
                'type' => $type,
                'is_active' => true,
                'updated_by' => $userId,
            ])->save();

            foreach ($sectionContentService->locales() as $locale) {
                $payload = $definition['build']($locale);
                $sectionContentService->updateLocale($section, $locale, $payload, $userId);
                $section = $section->fresh();
            }

            $synced[$definition['key']] = $section->fresh();
        }

        return $synced;
    }

    /**
     * @return array<string, PlatformSection>
     */
    private function welcomeReferencedSectionsByType(PlatformPage $page): array
    {
        $payload = is_array($page->content) ? $page->content : [];
        $locales = is_array($payload['locales'] ?? null) ? $payload['locales'] : [];
        $sourceIds = [];

        foreach ($locales as $localeContent) {
            $sections = is_array($localeContent['sections'] ?? null) ? $localeContent['sections'] : [];
            foreach ($sections as $section) {
                if (! is_array($section) || empty($section['use_source']) || empty($section['source_id'])) {
                    continue;
                }

                $sourceIds[] = (int) $section['source_id'];
            }
        }

        if ($sourceIds === []) {
            return [];
        }

        $existing = [];

        foreach (PlatformSection::query()->whereIn('id', array_values(array_unique($sourceIds)))->get() as $section) {
            $type = strtolower(trim((string) $section->type));
            if ($type === '' || array_key_exists($type, $existing)) {
                continue;
            }

            $existing[$type] = $section;
        }

        return $existing;
    }

    /**
     * @return array<int, array{build: callable, key: string, name: string, type: string}>
     */
    private function welcomeSourceDefinitions(): array
    {
        return [
            [
                'key' => 'hero',
                'name' => 'Welcome Hero',
                'type' => 'welcome_hero',
                'build' => fn (string $locale) => $this->welcomeHeroSectionPayload($locale),
            ],
            [
                'key' => 'trust',
                'name' => 'Welcome Trust',
                'type' => 'welcome_trust',
                'build' => fn (string $locale) => $this->welcomeTrustSectionPayload($locale),
            ],
            [
                'key' => 'showcase',
                'name' => 'Welcome Showcase',
                'type' => 'feature_tabs',
                'build' => fn (string $locale) => $this->welcomeShowcaseSectionPayload($locale),
            ],
            [
                'key' => 'features',
                'name' => 'Welcome Features',
                'type' => 'welcome_features',
                'build' => fn (string $locale) => $this->welcomeFeaturesSectionPayload($locale),
            ],
        ];
    }

    /**
     * @param  array<string, PlatformSection>  $sourceSections
     * @return array<int, array<string, mixed>>
     */
    private function welcomeSourceReferences(array $sourceSections, string $locale): array
    {
        $sectionContentService = app(PlatformSectionContentService::class);
        $references = [];
        $order = ['hero', 'trust', 'showcase', 'features'];

        foreach (array_values($order) as $index => $key) {
            $section = $sourceSections[$key] ?? null;
            if (! $section instanceof PlatformSection) {
                continue;
            }

            $resolved = $sectionContentService->resolveForLocale($section, $locale);

            $references[] = [
                'id' => 'welcome-section-'.($index + 1),
                'enabled' => $section->is_active,
                'source_id' => $section->id,
                'use_source' => true,
                'override_items' => false,
                'override_note' => false,
                'override_stats' => false,
                'layout' => (string) ($resolved['layout'] ?? 'split'),
            ];
        }

        return $references;
    }

    /**
     * @return array<string, mixed>
     */
    private function syncSharedFooter(?int $userId): array
    {
        $service = app(PlatformSectionContentService::class);
        $footer = $service->ensureSharedFooterSectionExists($userId);

        $footer->forceFill([
            'is_active' => true,
            'updated_by' => $userId,
        ])->save();

        foreach ($service->locales() as $locale) {
            $service->updateLocale(
                $footer,
                $locale,
                $service->defaultContent($locale, 'footer'),
                $userId
            );
            $footer = $footer->fresh();
        }

        return [
            'section_id' => $footer->id,
            'locales' => $service->locales(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function defaultPageHeader(): array
    {
        return [
            'background_type' => 'none',
            'background_color' => '',
            'background_image_url' => '',
            'background_image_alt' => '',
            'alignment' => 'center',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function welcomeHeroSectionPayload(string $locale): array
    {
        $content = app(WelcomeContentService::class)->defaultContent($locale);
        $hero = is_array($content['hero'] ?? null) ? $content['hero'] : [];
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

    /**
     * @return array<string, mixed>
     */
    private function welcomeTrustSectionPayload(string $locale): array
    {
        $content = app(WelcomeContentService::class)->defaultContent($locale);
        $trust = is_array($content['trust'] ?? null) ? $content['trust'] : [];

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

    /**
     * @return array<string, mixed>
     */
    private function welcomeFeaturesSectionPayload(string $locale): array
    {
        $content = app(WelcomeContentService::class)->defaultContent($locale);
        $features = is_array($content['features'] ?? null) ? $content['features'] : [];
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

    /**
     * @return array<string, mixed>
     */
    private function welcomeShowcaseSectionPayload(string $locale): array
    {
        return WelcomeShowcaseSection::payload($locale);
    }
}
