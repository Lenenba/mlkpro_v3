<?php

namespace App\Services;

use App\Models\PlatformPage;
use App\Models\PlatformSection;

class WelcomePageContentResolver
{
    public function resolve(PlatformPage $page, string $locale): array
    {
        $legacy = app(WelcomeContentService::class);
        $locale = in_array($locale, $legacy->locales(), true) ? $locale : $legacy->locales()[0];
        $payload = $this->emptyContent($legacy->defaultContent($locale));
        $pageContentService = app(PlatformPageContentService::class);
        $resolvedContent = $pageContentService->synchronizeResolvedSectionStructure(
            $pageContentService->resolveAll($page),
            $locale
        );
        $pageContent = is_array($resolvedContent[$locale] ?? null)
            ? $resolvedContent[$locale]
            : $pageContentService->resolveForLocale($page, $locale);
        $pageSections = is_array($pageContent['sections'] ?? null) ? $pageContent['sections'] : [];

        $sourceIds = collect($pageSections)
            ->pluck('source_id')
            ->filter()
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values()
            ->all();

        $sourceSections = PlatformSection::query()
            ->whereIn('id', $sourceIds)
            ->get()
            ->keyBy('id');

        $sectionContentService = app(PlatformSectionContentService::class);

        foreach ($pageSections as $pageSection) {
            if (! is_array($pageSection) || array_key_exists('enabled', $pageSection) && ! $pageSection['enabled']) {
                continue;
            }

            $source = null;
            $sourceContent = [];
            if (! empty($pageSection['use_source']) && ! empty($pageSection['source_id'])) {
                $source = $sourceSections->get((int) $pageSection['source_id']);
                if ($source) {
                    $sourceContent = $sectionContentService->resolveForLocale($source, $locale);
                }
            }

            $type = $source?->type;

            switch ($type) {
                case 'welcome_hero':
                    $payload['hero'] = $this->mapHero($pageSection, $sourceContent);
                    break;

                case 'welcome_trust':
                    $payload['trust'] = $this->mapTrust($pageSection);
                    break;

                case 'feature_tabs':
                    $payload['home_service_showcase'] = $this->mapShowcase($pageSection);
                    break;

                case 'welcome_features':
                    $payload['features'] = $this->mapFeatures($pageSection, $sourceContent);
                    break;

                case 'welcome_workflow':
                    $payload['workflow'] = $this->mapWorkflow($pageSection, $sourceContent);
                    break;

                case 'welcome_field':
                    $payload['field'] = $this->mapField($pageSection);
                    break;

                case 'welcome_cta':
                    $payload['cta'] = $this->mapCta($pageSection);
                    break;

                default:
                    $generic = $this->mapGenericSection($pageSection, $sourceContent);
                    if ($generic) {
                        $payload['generic_sections'][] = $generic;
                    }
                    break;
            }
        }

        return $payload;
    }

    private function emptyContent(array $content): array
    {
        $content['hero']['enabled'] = false;
        $content['hero']['stats'] = [];
        $content['hero']['hero_images'] = [];
        $content['hero']['highlights'] = [];
        $content['hero']['preview_cards'] = [];

        $content['trust']['enabled'] = false;
        $content['trust']['items'] = [];

        $content['features']['enabled'] = false;
        $content['features']['items'] = [];
        $content['features']['new_features']['enabled'] = false;
        $content['features']['new_features']['items'] = [];

        $content['workflow']['enabled'] = false;
        $content['workflow']['steps'] = [];

        $content['field']['enabled'] = false;
        $content['field']['items'] = [];

        $content['cta']['enabled'] = false;
        $content['custom_sections'] = [];
        $content['generic_sections'] = [];
        $content['home_service_showcase'] = [
            'enabled' => false,
        ];

        return $content;
    }

    private function mapHero(array $section, array $source): array
    {
        $heroImages = is_array($section['hero_images'] ?? null) && count($section['hero_images']) > 0
            ? $section['hero_images']
            : (is_array($source['hero_images'] ?? null) ? $source['hero_images'] : []);

        return [
            'enabled' => true,
            'background_color' => $this->stringOrNull($section['background_color'] ?? null),
            'background_preset' => $this->stringOrNull($section['background_preset'] ?? null),
            'eyebrow' => (string) ($section['kicker'] ?? ''),
            'title' => (string) ($section['title'] ?? ''),
            'title_color' => $this->stringOrNull($section['title_color'] ?? null),
            'body_color' => $this->stringOrNull($section['body_color'] ?? null),
            'title_font_size' => (int) ($section['title_font_size'] ?? 0),
            'subtitle' => (string) ($section['body'] ?? ''),
            'primary_cta' => (string) ($section['primary_label'] ?? ''),
            'primary_href' => (string) ($section['primary_href'] ?? ''),
            'secondary_cta' => (string) ($section['secondary_label'] ?? ''),
            'secondary_href' => (string) ($section['secondary_href'] ?? ''),
            'note' => (string) ($section['note'] ?? ''),
            'stats' => is_array($section['stats'] ?? null) ? $section['stats'] : [],
            'hero_images' => $heroImages,
            'highlights' => is_array($section['items'] ?? null) ? $section['items'] : [],
            'preview_cards' => is_array($source['preview_cards'] ?? null) ? $source['preview_cards'] : [],
            'image_url' => (string) ($section['image_url'] ?? ''),
            'image_alt' => (string) ($section['image_alt'] ?? ''),
        ];
    }

    private function mapTrust(array $section): array
    {
        return [
            'enabled' => true,
            'background_color' => $this->stringOrNull($section['background_color'] ?? null),
            'background_preset' => $this->stringOrNull($section['background_preset'] ?? null),
            'title' => (string) ($section['title'] ?? ''),
            'items' => is_array($section['items'] ?? null) ? $section['items'] : [],
        ];
    }

    private function mapShowcase(array $section): array
    {
        $showcase = $section;
        $showcase['enabled'] = true;

        return $showcase;
    }

    private function mapFeatures(array $section, array $source): array
    {
        return [
            'enabled' => true,
            'background_color' => $this->stringOrNull($section['background_color'] ?? null),
            'background_preset' => $this->stringOrNull($section['background_preset'] ?? null),
            'kicker' => (string) ($section['kicker'] ?? ''),
            'title' => (string) ($section['title'] ?? ''),
            'subtitle' => (string) ($section['body'] ?? ''),
            'items' => is_array($source['feature_items'] ?? null) ? $source['feature_items'] : [],
            'new_features' => [
                'enabled' => array_key_exists('secondary_enabled', $source) ? (bool) $source['secondary_enabled'] : false,
                'background_color' => $this->stringOrNull($source['secondary_background_color'] ?? null),
                'kicker' => (string) ($source['secondary_kicker'] ?? ''),
                'title' => (string) ($source['secondary_title'] ?? ''),
                'subtitle' => (string) ($source['secondary_body'] ?? ''),
                'badge' => (string) ($source['secondary_badge'] ?? ''),
                'items' => is_array($source['secondary_feature_items'] ?? null) ? $source['secondary_feature_items'] : [],
            ],
        ];
    }

    private function mapWorkflow(array $section, array $source): array
    {
        return [
            'enabled' => true,
            'background_color' => $this->stringOrNull($section['background_color'] ?? null),
            'background_preset' => $this->stringOrNull($section['background_preset'] ?? null),
            'kicker' => (string) ($section['kicker'] ?? ''),
            'title' => (string) ($section['title'] ?? ''),
            'subtitle' => (string) ($section['body'] ?? ''),
            'steps' => is_array($source['preview_cards'] ?? null) ? $source['preview_cards'] : [],
            'image_url' => (string) ($section['image_url'] ?? ''),
            'image_alt' => (string) ($section['image_alt'] ?? ''),
        ];
    }

    private function mapField(array $section): array
    {
        return [
            'enabled' => true,
            'background_color' => $this->stringOrNull($section['background_color'] ?? null),
            'background_preset' => $this->stringOrNull($section['background_preset'] ?? null),
            'kicker' => (string) ($section['kicker'] ?? ''),
            'title' => (string) ($section['title'] ?? ''),
            'subtitle' => (string) ($section['body'] ?? ''),
            'items' => is_array($section['items'] ?? null) ? $section['items'] : [],
            'image_url' => (string) ($section['image_url'] ?? ''),
            'image_alt' => (string) ($section['image_alt'] ?? ''),
        ];
    }

    private function mapCta(array $section): array
    {
        return [
            'enabled' => true,
            'background_color' => $this->stringOrNull($section['background_color'] ?? null),
            'background_preset' => $this->stringOrNull($section['background_preset'] ?? null),
            'title' => (string) ($section['title'] ?? ''),
            'subtitle' => (string) ($section['body'] ?? ''),
            'primary' => (string) ($section['primary_label'] ?? ''),
            'primary_href' => (string) ($section['primary_href'] ?? ''),
            'secondary' => (string) ($section['secondary_label'] ?? ''),
            'secondary_href' => (string) ($section['secondary_href'] ?? ''),
        ];
    }

    private function mapGenericSection(array $section, array $source = []): ?array
    {
        if (! is_array($section)) {
            return null;
        }

        if ($source !== []) {
            $section = $this->mergeSectionWithSource($section, $source);
        }

        return $section;
    }

    private function mergeSectionWithSource(array $section, array $source): array
    {
        $section['background_preset'] = $section['background_preset'] !== '' ? $section['background_preset'] : ($source['background_preset'] ?? '');
        $section['kicker'] = $section['kicker'] !== '' ? $section['kicker'] : ($source['kicker'] ?? '');
        $section['title'] = $section['title'] !== '' ? $section['title'] : ($source['title'] ?? '');
        $section['body'] = $section['body'] !== '' ? $section['body'] : ($source['body'] ?? '');
        $section['title_color'] = $section['title_color'] !== '' ? $section['title_color'] : ($source['title_color'] ?? '');
        $section['body_color'] = $section['body_color'] !== '' ? $section['body_color'] : ($source['body_color'] ?? '');
        $section['title_font_size'] = ! empty($section['title_font_size']) ? $section['title_font_size'] : ($source['title_font_size'] ?? 0);
        $section['image_position'] = $section['image_position'] !== '' ? $section['image_position'] : ($source['image_position'] ?? 'left');
        $section['note'] = ! empty($section['override_note']) ? ($section['note'] ?? '') : ($source['note'] ?? '');

        if (empty($section['industry_cards'])) {
            $section['industry_cards'] = $source['industry_cards'] ?? [];
        }

        if (empty($section['story_cards'])) {
            $section['story_cards'] = $source['story_cards'] ?? [];
        }

        if (empty($section['feature_tabs'])) {
            $section['feature_tabs'] = $source['feature_tabs'] ?? [];
        }

        $section['feature_tabs_style'] = ! empty($section['feature_tabs_style'])
            ? $section['feature_tabs_style']
            : ($source['feature_tabs_style'] ?? 'editorial');

        $section['feature_tabs_font_size'] = ! empty($section['feature_tabs_font_size'])
            ? $section['feature_tabs_font_size']
            : ($source['feature_tabs_font_size'] ?? 0);

        if (empty($section['testimonial_cards'])) {
            $section['testimonial_cards'] = $source['testimonial_cards'] ?? [];
        }

        if (empty($section['override_stats'])) {
            $section['stats'] = $source['stats'] ?? [];
        }

        if (empty($section['hero_images'])) {
            $section['hero_images'] = $source['hero_images'] ?? [];
        }

        if (empty($section['override_items'])) {
            $section['items'] = $source['items'] ?? [];
        }

        $section['testimonial_author'] = $section['testimonial_author'] !== '' ? $section['testimonial_author'] : ($source['testimonial_author'] ?? '');
        $section['testimonial_role'] = $section['testimonial_role'] !== '' ? $section['testimonial_role'] : ($source['testimonial_role'] ?? '');
        $section['aside_kicker'] = $section['aside_kicker'] !== '' ? $section['aside_kicker'] : ($source['aside_kicker'] ?? '');
        $section['aside_title'] = $section['aside_title'] !== '' ? $section['aside_title'] : ($source['aside_title'] ?? '');
        $section['aside_body'] = $section['aside_body'] !== '' ? $section['aside_body'] : ($source['aside_body'] ?? '');

        if (empty($section['aside_items'])) {
            $section['aside_items'] = $source['aside_items'] ?? [];
        }

        $section['aside_link_label'] = $section['aside_link_label'] !== '' ? $section['aside_link_label'] : ($source['aside_link_label'] ?? '');
        $section['aside_link_href'] = $section['aside_link_href'] !== '' ? $section['aside_link_href'] : ($source['aside_link_href'] ?? '');
        $section['aside_image_url'] = $section['aside_image_url'] !== '' ? $section['aside_image_url'] : ($source['aside_image_url'] ?? '');
        $section['aside_image_alt'] = $section['aside_image_alt'] !== '' ? $section['aside_image_alt'] : ($source['aside_image_alt'] ?? '');
        $section['image_url'] = $section['image_url'] !== '' ? $section['image_url'] : ($source['image_url'] ?? '');
        $section['image_alt'] = $section['image_alt'] !== '' ? $section['image_alt'] : ($source['image_alt'] ?? '');
        $section['embed_url'] = $section['embed_url'] !== '' ? $section['embed_url'] : ($source['embed_url'] ?? '');
        $section['embed_title'] = $section['embed_title'] !== '' ? $section['embed_title'] : ($source['embed_title'] ?? '');
        $section['embed_height'] = ! empty($section['embed_height']) ? $section['embed_height'] : ($source['embed_height'] ?? 760);
        $section['primary_label'] = $section['primary_label'] !== '' ? $section['primary_label'] : ($source['primary_label'] ?? '');
        $section['primary_href'] = $section['primary_href'] !== '' ? $section['primary_href'] : ($source['primary_href'] ?? '');
        $section['secondary_label'] = $section['secondary_label'] !== '' ? $section['secondary_label'] : ($source['secondary_label'] ?? '');
        $section['secondary_href'] = $section['secondary_href'] !== '' ? $section['secondary_href'] : ($source['secondary_href'] ?? '');
        $section['showcase_badge_label'] = $section['showcase_badge_label'] !== '' ? $section['showcase_badge_label'] : ($source['showcase_badge_label'] ?? '');
        $section['showcase_badge_value'] = $section['showcase_badge_value'] !== '' ? $section['showcase_badge_value'] : ($source['showcase_badge_value'] ?? '');
        $section['showcase_badge_note'] = $section['showcase_badge_note'] !== '' ? $section['showcase_badge_note'] : ($source['showcase_badge_note'] ?? '');
        $section['showcase_divider_style'] = $section['showcase_divider_style'] !== ''
            ? $section['showcase_divider_style']
            : ($source['showcase_divider_style'] ?? 'diagonal');

        return $section;
    }

    private function stringOrNull($value): ?string
    {
        $text = trim((string) $value);

        return $text !== '' ? $text : null;
    }
}
