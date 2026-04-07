<?php

use App\Models\PlatformPage;
use App\Support\PublicPageStockImages;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        $page = PlatformPage::query()->where('slug', 'sales-crm')->first();

        if (! $page) {
            return;
        }

        $content = is_array($page->content) ? $page->content : [];
        $locales = is_array($content['locales'] ?? null) ? $content['locales'] : [];
        $sharedMedia = is_array($content['shared_media'] ?? null) ? $content['shared_media'] : [];
        $sharedSections = is_array($sharedMedia['sections'] ?? null) ? $sharedMedia['sections'] : [];
        $changed = false;

        foreach ($locales as $localeKey => $localeContent) {
            if (! is_array($localeContent)) {
                continue;
            }

            $locale = PublicPageStockImages::normalizeLocale((string) $localeKey);
            $flowVisual = PublicPageStockImages::visual('marketing-desk', $locale);
            $storyVisuals = [
                PublicPageStockImages::visual('service-team', $locale),
                PublicPageStockImages::visual('team-laptop-window', $locale),
                PublicPageStockImages::visual('collab-laptop-desk', $locale),
            ];

            $sections = is_array($localeContent['sections'] ?? null) ? $localeContent['sections'] : [];

            foreach ($sections as $sectionIndex => $section) {
                if (! is_array($section)) {
                    continue;
                }

                $sectionId = strtolower(trim((string) ($section['id'] ?? '')));

                if ($sectionId === 'sales-crm-flow') {
                    $featureTabs = is_array($section['feature_tabs'] ?? null) ? $section['feature_tabs'] : [];
                    $firstTab = is_array($featureTabs[0] ?? null) ? $featureTabs[0] : null;

                    if ($firstTab !== null) {
                        $updatedFirstTab = $this->fillImageIfEmpty($firstTab, $flowVisual);

                        if ($updatedFirstTab !== $firstTab) {
                            $featureTabs[0] = $updatedFirstTab;
                            $sections[$sectionIndex]['feature_tabs'] = $featureTabs;
                            $changed = true;
                        }
                    }

                    $sharedSection = is_array($sharedSections[$sectionIndex] ?? null) ? $sharedSections[$sectionIndex] : [];
                    $sharedTabs = is_array($sharedSection['feature_tabs'] ?? null) ? $sharedSection['feature_tabs'] : [];
                    $sharedFirstTab = is_array($sharedTabs[0] ?? null) ? $sharedTabs[0] : null;

                    if ($sharedFirstTab !== null) {
                        $updatedSharedFirstTab = $this->fillSharedImageIfEmpty($sharedFirstTab, $flowVisual);

                        if ($updatedSharedFirstTab !== $sharedFirstTab) {
                            $sharedTabs[0] = $updatedSharedFirstTab;
                            $sharedSections[$sectionIndex] = [
                                ...$sharedSection,
                                'feature_tabs' => $sharedTabs,
                            ];
                            $changed = true;
                        }
                    }

                    continue;
                }

                if ($sectionId !== 'sales-crm-proof') {
                    continue;
                }

                $storyCards = is_array($section['story_cards'] ?? null) ? $section['story_cards'] : [];
                $sharedSection = is_array($sharedSections[$sectionIndex] ?? null) ? $sharedSections[$sectionIndex] : [];
                $sharedStoryCards = is_array($sharedSection['story_cards'] ?? null) ? $sharedSection['story_cards'] : [];

                foreach ($storyVisuals as $storyIndex => $storyVisual) {
                    $card = is_array($storyCards[$storyIndex] ?? null) ? $storyCards[$storyIndex] : null;
                    if ($card !== null) {
                        $updatedCard = $this->fillImageIfEmpty($card, $storyVisual);

                        if ($updatedCard !== $card) {
                            $storyCards[$storyIndex] = $updatedCard;
                            $changed = true;
                        }
                    }

                    $sharedCard = is_array($sharedStoryCards[$storyIndex] ?? null) ? $sharedStoryCards[$storyIndex] : null;
                    if ($sharedCard !== null) {
                        $updatedSharedCard = $this->fillSharedImageIfEmpty($sharedCard, $storyVisual);

                        if ($updatedSharedCard !== $sharedCard) {
                            $sharedStoryCards[$storyIndex] = $updatedSharedCard;
                            $changed = true;
                        }
                    }
                }

                $sections[$sectionIndex]['story_cards'] = $storyCards;

                if ($sharedStoryCards !== []) {
                    $sharedSections[$sectionIndex] = [
                        ...$sharedSection,
                        'story_cards' => $sharedStoryCards,
                    ];
                }
            }

            $locales[$localeKey]['sections'] = $sections;
        }

        if (! $changed) {
            return;
        }

        if ($sharedSections !== []) {
            $sharedMedia['sections'] = $sharedSections;
            $content['shared_media'] = $sharedMedia;
        }

        $content['locales'] = $locales;
        $content['updated_at'] = now()->toIso8601String();

        $page->forceFill(['content' => $content])->save();
    }

    public function down(): void
    {
        // Forward-only content backfill.
    }

    /**
     * @param  array{image_alt:string,image_url:string}  $visual
     * @return array<string, mixed>
     */
    private function fillImageIfEmpty(array $target, array $visual): array
    {
        if (trim((string) ($target['image_url'] ?? '')) !== '') {
            return $target;
        }

        $target['image_url'] = $visual['image_url'];
        $target['image_alt'] = $visual['image_alt'];

        return $target;
    }

    /**
     * @param  array{image_alt:string,image_url:string}  $visual
     * @return array<string, mixed>
     */
    private function fillSharedImageIfEmpty(array $target, array $visual): array
    {
        if (trim((string) ($target['image_url'] ?? '')) !== '') {
            return $target;
        }

        $target['image_url'] = $visual['image_url'];

        return $target;
    }
};
