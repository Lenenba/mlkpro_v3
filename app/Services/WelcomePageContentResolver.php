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
        $pageContent = app(PlatformPageContentService::class)->resolveForLocale($page, $locale);
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
                    $generic = $this->mapGenericSection($pageSection);
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

    private function mapGenericSection(array $section): ?array
    {
        if (! is_array($section)) {
            return null;
        }

        return $section;
    }

    private function stringOrNull($value): ?string
    {
        $text = trim((string) $value);

        return $text !== '' ? $text : null;
    }
}
